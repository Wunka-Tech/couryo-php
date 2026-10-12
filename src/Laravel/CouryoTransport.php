<?php

declare(strict_types=1);

namespace Couryo\Laravel;

use Couryo\Couryo;
use Couryo\Exception\CouryoException;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Symfony Mailer transport that sends through the Couryo API (Laravel registers it as "couryo").
 *
 * Maps from, to, cc, bcc, reply-to, subject, html, text, attachments and inline images, X-* headers,
 * tags (->tag() in Laravel, TagHeader in Symfony, or an X-Couryo-Tags: k=v, k2=v2 header), metadata
 * (MetadataHeader) and the X-Couryo-Stream header (transactional or marketing). After sending, the Couryo id is in the X-Couryo-Email-ID
 * header of the message and in SentMessage::getMessageId().
 */
class CouryoTransport extends AbstractTransport
{
    public const EMAIL_ID_HEADER = 'X-Couryo-Email-ID';
    public const STREAM_HEADER = 'X-Couryo-Stream';
    public const TAGS_HEADER = 'X-Couryo-Tags';

    public function __construct(
        private readonly Couryo $couryo,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($dispatcher, $logger);
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $body = self::toRequest($email, $message->getEnvelope());

        $options = [];
        $messageId = $email->getHeaders()->get('Message-ID');
        if ($messageId !== null) {
            // Retrying the same message (a queued job, for example) never sends it twice.
            $options['idempotency_key'] = substr(trim($messageId->getBodyAsString(), '<> '), 0, 255);
        }

        try {
            $result = $this->couryo->emails->send($body, $options);
        } catch (CouryoException $e) {
            throw new TransportException(sprintf('Couryo API request failed: %s (%s).', $e->getMessage(), $e->getErrorCode()), $e->getStatus(), $e);
        }

        $id = (string) ($result['id'] ?? '');
        if ($id !== '') {
            $headers = $email->getHeaders();
            $headers->remove(self::EMAIL_ID_HEADER);
            $headers->addTextHeader(self::EMAIL_ID_HEADER, $id);
            $message->setMessageId($id);
        }
    }

    /**
     * Converts a Symfony Email into the body of POST /v1/emails.
     *
     * @return array<string, mixed>
     */
    public static function toRequest(Email $email, ?Envelope $envelope = null): array
    {
        $from = $email->getFrom()[0] ?? $envelope?->getSender();
        if ($from === null) {
            throw new TransportException('The email has no From address.');
        }

        $cc = $email->getCc();
        $bcc = $email->getBcc();
        $to = $email->getTo();
        if ($envelope !== null && $envelope->getRecipients() !== []) {
            // The envelope holds every recipient (to, cc and bcc): keep the ones that are not cc or bcc.
            $copies = [];
            foreach (array_merge($cc, $bcc) as $address) {
                $copies[strtolower($address->getAddress())] = true;
            }
            $to = array_values(array_filter(
                $envelope->getRecipients(),
                static fn (Address $address): bool => !isset($copies[strtolower($address->getAddress())]),
            ));
        }

        $body = ['from' => self::address($from), 'to' => array_map([self::class, 'address'], $to)];
        if ($cc !== []) {
            $body['cc'] = array_map([self::class, 'address'], $cc);
        }
        if ($bcc !== []) {
            $body['bcc'] = array_map([self::class, 'address'], $bcc);
        }
        if ($email->getReplyTo() !== []) {
            $body['reply_to'] = array_map([self::class, 'address'], $email->getReplyTo());
        }
        if ($email->getSubject() !== null) {
            $body['subject'] = $email->getSubject();
        }
        $html = self::content($email->getHtmlBody());
        $text = self::content($email->getTextBody());
        if ($html !== null) {
            $body['html'] = $html;
        }
        if ($text !== null) {
            $body['text'] = $text;
        }

        $attachments = [];
        foreach ($email->getAttachments() as $part) {
            $attachments[] = self::attachment($part, $html);
        }
        if ($attachments !== []) {
            $body['attachments'] = $attachments;
        }

        $headers = $tags = $metadata = [];
        foreach ($email->getHeaders()->all() as $header) {
            if ($header instanceof TagHeader) {
                $key = substr((string) preg_replace('/[^A-Za-z0-9_-]/', '_', $header->getValue()), 0, 64);
                if ($key !== '') {
                    $tags[$key] = 'true';
                }
                continue;
            }
            if ($header instanceof MetadataHeader) {
                $metadata[$header->getKey()] = $header->getValue();
                continue;
            }
            $name = $header->getName();
            $lower = strtolower($name);
            if ($lower === strtolower(self::STREAM_HEADER)) {
                $body['stream'] = strtolower(trim($header->getBodyAsString()));
                continue;
            }
            if ($lower === strtolower(self::TAGS_HEADER)) {
                // "k=v, k2=v2", the same format as the SMTP relay and the Django backend.
                foreach (explode(',', $header->getBodyAsString()) as $pair) {
                    $parts = explode('=', $pair, 2);
                    if (count($parts) === 2 && trim($parts[0]) !== '') {
                        $tags[trim($parts[0])] = trim($parts[1]);
                    }
                }
                continue;
            }
            // Other X-Couryo-* headers are ours (like X-Couryo-Email-ID) and never go to the recipient.
            if (str_starts_with($lower, 'x-couryo-') || !str_starts_with($lower, 'x-')) {
                continue;
            }
            $headers[$name] = $header->getBodyAsString();
        }
        if ($headers !== []) {
            $body['headers'] = $headers;
        }
        if ($tags !== []) {
            $body['tags'] = $tags;
        }
        if ($metadata !== []) {
            $body['metadata'] = $metadata;
        }

        return $body;
    }

    public function __toString(): string
    {
        return 'couryo';
    }

    /** "Name <a@b.com>" or "a@b.com". */
    private static function address(Address $address): string
    {
        $name = trim((string) preg_replace('/[\r\n<>"]+/', ' ', $address->getName()));

        return $name !== '' ? $name . ' <' . $address->getAddress() . '>' : $address->getAddress();
    }

    /**
     * @param resource|string|null $content
     */
    private static function content(mixed $content): ?string
    {
        if (is_resource($content)) {
            $content = stream_get_contents($content);
        }

        return is_string($content) ? $content : null;
    }

    /**
     * @return array{filename: string, content: string, content_type: string, content_id?: string}
     */
    private static function attachment(DataPart $part, ?string $html): array
    {
        $headers = $part->getPreparedHeaders();
        $filename = method_exists($part, 'getFilename') ? $part->getFilename() : null;
        $filename ??= $headers->getHeaderParameter('Content-Disposition', 'filename') ?? $part->getName() ?? 'attachment';
        $contentType = method_exists($part, 'getContentType')
            ? $part->getContentType()
            : $part->getMediaType() . '/' . $part->getMediaSubtype();

        $item = [
            'filename' => (string) $filename,
            'content' => base64_encode($part->getBody()),
            'content_type' => $contentType,
        ];

        // Inline when marked so, or when the HTML points to it (cid:<name>), like Symfony does.
        $hasCid = method_exists($part, 'hasContentId') && $part->hasContentId();
        $cid = $hasCid ? $part->getContentId() : ($part->getName() ?? $item['filename']);
        $disposition = method_exists($part, 'getDisposition') ? $part->getDisposition() : $headers->getHeaderBody('Content-Disposition');
        $referenced = $html !== null && $cid !== '' && str_contains($html, 'cid:' . $cid);
        if ($disposition === 'inline' || $referenced) {
            $item['content_id'] = $cid;
        }

        return $item;
    }
}
