<?php

declare(strict_types=1);

namespace Couryo\Tests;

use Couryo\Laravel\CouryoTransport;
use Couryo\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

final class SymfonyTransportTest extends TestCase
{
    private function email(): Email
    {
        $email = (new Email())
            ->from(new Address('pedidos@loja.com.br', 'Loja Exemplo'))
            ->to(new Address('ana@exemplo.com.br', 'Ana Souza'), 'bruno@exemplo.com.br')
            ->cc('carla@exemplo.com.br')
            ->bcc('arquivo@loja.com.br')
            ->replyTo(new Address('suporte@loja.com.br', 'Suporte'))
            ->subject('Pedido 1042 enviado')
            ->text('Seu pedido saiu para entrega.')
            ->html('<p>Seu pedido saiu para entrega.</p><img src="cid:logo">')
            ->attach('%PDF-1.4 nota', 'nota-1042.pdf', 'application/pdf')
            ->addPart((new DataPart('PNGDATA', 'logo', 'image/png'))->asInline());
        $headers = $email->getHeaders();
        $headers->add(new TagHeader('pedido enviado'));
        $headers->add(new TagHeader('loja'));
        $headers->add(new MetadataHeader('order_id', '1042'));
        $headers->addTextHeader('X-Entity-Ref-ID', 'pedido-1042');
        $headers->addTextHeader('X-Couryo-Stream', 'Transactional');
        $headers->addTextHeader('X-Couryo-Tags', 'tipo=pedido, canal = site, sem-valor');
        $headers->addTextHeader('X-Couryo-Unknown', 'dropped');
        $headers->addTextHeader('List-Help', '<https://loja.com.br/ajuda>');

        return $email;
    }

    public function testConvertsTheEmailAndSendsIt(): void
    {
        [$couryo, $fake] = FakeTransport::client([FakeTransport::json(202, ['id' => 'msg_42', 'status' => 'queued'])]);
        $transport = new CouryoTransport($couryo);

        $sent = $transport->send($this->email());

        $this->assertSame('couryo', (string) $transport);
        $this->assertSame(['POST /emails'], $fake->routes());
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $fake->calls[0]['headers']['Idempotency-Key']);
        $this->assertSame([
            'from' => 'Loja Exemplo <pedidos@loja.com.br>',
            'to' => ['Ana Souza <ana@exemplo.com.br>', 'bruno@exemplo.com.br'],
            'cc' => ['carla@exemplo.com.br'],
            'bcc' => ['arquivo@loja.com.br'],
            'reply_to' => ['Suporte <suporte@loja.com.br>'],
            'subject' => 'Pedido 1042 enviado',
            'html' => '<p>Seu pedido saiu para entrega.</p><img src="cid:logo">',
            'text' => 'Seu pedido saiu para entrega.',
            'attachments' => [
                ['filename' => 'nota-1042.pdf', 'content' => base64_encode('%PDF-1.4 nota'), 'content_type' => 'application/pdf'],
                ['filename' => 'logo', 'content' => base64_encode('PNGDATA'), 'content_type' => 'image/png', 'content_id' => 'logo'],
            ],
            'stream' => 'transactional',
            'headers' => ['X-Entity-Ref-ID' => 'pedido-1042'],
            'tags' => ['pedido_enviado' => 'true', 'loja' => 'true', 'tipo' => 'pedido', 'canal' => 'site'],
            'metadata' => ['order_id' => '1042'],
        ], $fake->body(0));

        $this->assertNotNull($sent);
        $this->assertSame('msg_42', $sent->getMessageId());
        $original = $sent->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $original);
        $this->assertSame('msg_42', $original->getHeaders()->get('X-Couryo-Email-ID')?->getBodyAsString());
    }

    public function testUsesTheEnvelopeAndTheMessageIdAsIdempotencyKey(): void
    {
        [$couryo, $fake] = FakeTransport::client([FakeTransport::json(202, ['id' => 'msg_43', 'status' => 'queued'])]);
        $email = (new Email())
            ->from('oi@loja.com.br')
            ->to('ana@exemplo.com.br')
            ->bcc('arquivo@loja.com.br')
            ->subject('Oi')
            ->text('Olá');
        $email->getHeaders()->addIdHeader('Message-ID', 'pedido-1042@loja.com.br');
        $envelope = new Envelope(new Address('bounces@loja.com.br'), [new Address('outra@exemplo.com.br'), new Address('arquivo@loja.com.br')]);

        (new CouryoTransport($couryo))->send($email, $envelope);

        $this->assertSame('pedido-1042@loja.com.br', $fake->calls[0]['headers']['Idempotency-Key']);
        $body = $fake->body(0);
        $this->assertSame('oi@loja.com.br', $body['from']);
        $this->assertSame(['outra@exemplo.com.br'], $body['to']);
        $this->assertSame(['arquivo@loja.com.br'], $body['bcc']);
        $this->assertArrayNotHasKey('html', $body);
        $this->assertArrayNotHasKey('attachments', $body);
        $this->assertArrayNotHasKey('tags', $body);
    }

    public function testApiErrorsBecomeTransportExceptions(): void
    {
        [$couryo] = FakeTransport::client([FakeTransport::apiError(422, 'domain_not_verified')]);

        try {
            (new CouryoTransport($couryo))->send((new Email())->from('oi@x.com')->to('a@b.com')->subject('x')->text('y'));
            $this->fail('expected an exception');
        } catch (TransportException $e) {
            $this->assertStringContainsString('domain_not_verified', $e->getMessage());
            $this->assertSame(422, $e->getCode());
            $this->assertInstanceOf(\Couryo\Exception\InvalidRequestError::class, $e->getPrevious());
        }
    }
}
