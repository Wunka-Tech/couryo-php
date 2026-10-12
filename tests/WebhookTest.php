<?php

declare(strict_types=1);

namespace Couryo\Tests;

use Couryo\Exception\WebhookVerificationError;
use Couryo\Webhook;
use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase
{
    private const SECRET = 'whsec_MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=';
    private const NOW = 1791460810;

    private string $body;
    /** @var array<string, string> */
    private array $headers;

    protected function setUp(): void
    {
        $this->body = json_encode(['type' => 'delivered', 'timestamp' => '2026-10-08T12:00:00Z', 'data' => ['email_id' => 'msg_1', 'event_id' => 'evt_1', 'tags' => new \stdClass(), 'metadata' => new \stdClass()]], JSON_THROW_ON_ERROR);
        $ts = self::NOW - 10;
        $this->headers = [
            'webhook-id' => 'evt_1',
            'webhook-timestamp' => (string) $ts,
            'webhook-signature' => Webhook::sign(self::SECRET, 'evt_1', $ts, $this->body),
        ];
    }

    public function testSharedVectorFromTheNodeSdk(): void
    {
        $vector = json_decode((string) file_get_contents(__DIR__ . '/fixtures/webhook-vector.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($vector['signature'], Webhook::sign($vector['secret'], $vector['id'], $vector['timestamp'], $vector['body']));
        $event = Webhook::verify($vector['secret'], [
            'webhook-id' => $vector['id'],
            'webhook-timestamp' => (string) $vector['timestamp'],
            'webhook-signature' => $vector['signature'],
        ], $vector['body'], 300, $vector['timestamp']);

        $this->assertSame('bounced', $event['type']);
        $this->assertSame('msg_abc', $event['data']['email_id']);
        $this->assertSame('550 5.1.1', $event['data']['smtp_code']);
    }

    public function testAcceptsAValidCallInAnyHeaderShape(): void
    {
        $this->assertSame('delivered', Webhook::verify(self::SECRET, $this->headers, $this->body, 300, self::NOW)['type']);

        $mixedCase = [
            'Webhook-Id' => $this->headers['webhook-id'],
            'WEBHOOK-TIMESTAMP' => $this->headers['webhook-timestamp'],
            'Webhook-Signature' => $this->headers['webhook-signature'],
        ];
        $this->assertSame('msg_1', Webhook::verify(self::SECRET, $mixedCase, $this->body, 300, self::NOW)['data']['email_id']);

        $server = [
            'REQUEST_METHOD' => 'POST',
            'HTTP_WEBHOOK_ID' => $this->headers['webhook-id'],
            'HTTP_WEBHOOK_TIMESTAMP' => $this->headers['webhook-timestamp'],
            'HTTP_WEBHOOK_SIGNATURE' => $this->headers['webhook-signature'],
        ];
        $this->assertSame('delivered', Webhook::verify(self::SECRET, $server, $this->body, 300, self::NOW)['type']);

        $lists = array_map(static fn (string $v): array => [$v], $this->headers);
        $this->assertSame('delivered', Webhook::verify(self::SECRET, $lists, $this->body, 300, self::NOW)['type']);
    }

    public function testAcceptsSeveralSignatures(): void
    {
        $headers = $this->headers;
        $headers['webhook-signature'] = 'v1,AAAA v2,BBBB ' . $this->headers['webhook-signature'];

        $this->assertSame('delivered', Webhook::verify(self::SECRET, $headers, $this->body, 300, self::NOW)['type']);
    }

    public function testRefusesAChangedBody(): void
    {
        $this->expectException(WebhookVerificationError::class);
        $this->expectExceptionMessage('signature does not match');
        Webhook::verify(self::SECRET, $this->headers, str_replace('delivered', 'bounced', $this->body), 300, self::NOW);
    }

    public function testRefusesAWrongSignature(): void
    {
        $headers = $this->headers;
        $headers['webhook-signature'] = 'v1,AAAA v1,' . base64_encode(str_repeat('x', 32));

        $this->expectException(WebhookVerificationError::class);
        $this->expectExceptionMessage('signature does not match');
        Webhook::verify(self::SECRET, $headers, $this->body, 300, self::NOW);
    }

    public function testRefusesAWrongSecret(): void
    {
        $this->expectExceptionMessage('signature does not match');
        Webhook::verify('whsec_' . base64_encode(str_repeat('x', 32)), $this->headers, $this->body, 300, self::NOW);
    }

    public function testRefusesOldAndFutureTimestamps(): void
    {
        foreach ([self::NOW + 600, self::NOW - 600] as $now) {
            try {
                Webhook::verify(self::SECRET, $this->headers, $this->body, 300, $now);
                $this->fail('expected an exception');
            } catch (WebhookVerificationError $e) {
                $this->assertSame('timestamp too old or in the future', $e->getMessage());
            }
        }
        // A wider tolerance accepts it.
        $this->assertSame('delivered', Webhook::verify(self::SECRET, $this->headers, $this->body, 900, self::NOW + 600)['type']);
    }

    public function testRefusesMissingHeaders(): void
    {
        $this->expectException(WebhookVerificationError::class);
        $this->expectExceptionMessage('missing webhook-id, webhook-timestamp or webhook-signature header');
        Webhook::verify(self::SECRET, ['webhook-id' => 'evt_1'], $this->body, 300, self::NOW);
    }

    public function testRefusesAnInvalidTimestamp(): void
    {
        $headers = $this->headers;
        $headers['webhook-timestamp'] = '12.5';

        $this->expectExceptionMessage('invalid webhook-timestamp');
        Webhook::verify(self::SECRET, $headers, $this->body, 300, self::NOW);
    }

    public function testRefusesABodyThatIsNotJson(): void
    {
        $ts = self::NOW;
        $headers = ['webhook-id' => 'evt_2', 'webhook-timestamp' => (string) $ts, 'webhook-signature' => Webhook::sign(self::SECRET, 'evt_2', $ts, 'not json')];

        $this->expectExceptionMessage('body is not JSON');
        Webhook::verify(self::SECRET, $headers, 'not json', 300, self::NOW);
    }
}
