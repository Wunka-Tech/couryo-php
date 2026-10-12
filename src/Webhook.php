<?php

declare(strict_types=1);

namespace Couryo;

use Couryo\Exception\WebhookVerificationError;

/**
 * Couryo webhooks follow Standard Webhooks: headers webhook-id, webhook-timestamp and
 * webhook-signature = "v1," + base64(HMAC-SHA256(base64decode(secret without whsec_), "id.timestamp.body")).
 */
final class Webhook
{
    /**
     * Checks a Couryo webhook and returns the decoded event {type, timestamp, data}.
     *
     * Pass the raw body exactly as received (file_get_contents('php://input') or
     * $request->getContent()), never JSON that was decoded and encoded again.
     *
     * @param string               $secret  whsec_... from the webhook creation
     * @param array<string, mixed> $headers any header bag: getallheaders(), $_SERVER (HTTP_WEBHOOK_ID keys),
     *                                      $request->headers->all() (array values) or a plain array
     *
     * @return array<string, mixed>
     *
     * @throws WebhookVerificationError when the signature does not match or the timestamp is outside the tolerance
     */
    public static function verify(string $secret, array $headers, string $rawBody, int $toleranceSeconds = 300, ?int $now = null): array
    {
        $id = self::header($headers, 'webhook-id');
        $timestamp = self::header($headers, 'webhook-timestamp');
        $signatures = self::header($headers, 'webhook-signature');
        if ($id === null || $id === '' || $timestamp === null || $timestamp === '' || $signatures === null || $signatures === '') {
            throw new WebhookVerificationError('missing webhook-id, webhook-timestamp or webhook-signature header');
        }
        $ts = trim($timestamp);
        if (!preg_match('/^-?\d+$/', $ts)) {
            throw new WebhookVerificationError('invalid webhook-timestamp');
        }
        $seconds = (int) $ts;
        $now ??= time();
        if (abs($now - $seconds) > $toleranceSeconds) {
            throw new WebhookVerificationError('timestamp too old or in the future');
        }

        $expected = substr(self::sign($secret, $id, $seconds, $rawBody), 3);
        $ok = false;
        foreach (explode(' ', $signatures) as $entry) {
            $parts = explode(',', $entry, 2);
            if (count($parts) !== 2 || $parts[0] !== 'v1' || $parts[1] === '') {
                continue;
            }
            if (hash_equals($expected, $parts[1])) {
                $ok = true;
            }
        }
        if (!$ok) {
            throw new WebhookVerificationError('signature does not match');
        }

        try {
            $event = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new WebhookVerificationError('body is not JSON');
        }
        if (!is_array($event)) {
            throw new WebhookVerificationError('body is not JSON');
        }

        return $event;
    }

    /** Signature header value for a body ("v1,<base64>"). Useful in tests. */
    public static function sign(string $secret, string $id, int $timestamp, string $body): string
    {
        $key = base64_decode(preg_replace('/^whsec_/', '', $secret) ?? '', false);

        return 'v1,' . base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key === false ? '' : $key, true));
    }

    /**
     * Case-insensitive lookup; accepts "webhook-id", "Webhook-Id", "HTTP_WEBHOOK_ID" and array values (first one).
     *
     * @param array<string, mixed> $headers
     */
    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            $normalized = str_replace('_', '-', strtolower((string) $key));
            if (str_starts_with($normalized, 'http-')) {
                $normalized = substr($normalized, 5);
            }
            if ($normalized !== $name) {
                continue;
            }
            if (is_array($value)) {
                $value = reset($value);
            }

            return is_scalar($value) ? (string) $value : null;
        }

        return null;
    }
}
