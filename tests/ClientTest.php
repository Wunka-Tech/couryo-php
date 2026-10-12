<?php

declare(strict_types=1);

namespace Couryo\Tests;

use Couryo\Couryo;
use Couryo\Exception\ApiError;
use Couryo\Exception\ConflictError;
use Couryo\Exception\CouryoException;
use Couryo\Exception\InvalidRequestError;
use Couryo\Exception\NetworkError;
use Couryo\Exception\NotFoundError;
use Couryo\Exception\RateLimitError;
use Couryo\Exception\TierLimitError;
use Couryo\Http\Response;
use Couryo\Http\TransportException;
use Couryo\HttpClient;
use Couryo\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private const EMAIL = ['from' => 'Loja <oi@loja.com.br>', 'to' => 'ana@gmail.com', 'subject' => 'Oi', 'text' => 'Olá'];

    public function testSendsWithAuthJsonAndAutomaticIdempotencyKey(): void
    {
        [$couryo, $fake] = FakeTransport::client([FakeTransport::json(202, ['id' => 'msg_1', 'status' => 'queued'])]);

        $result = $couryo->emails->send(self::EMAIL);

        $this->assertSame(['id' => 'msg_1', 'status' => 'queued'], $result);
        $call = $fake->calls[0];
        $this->assertSame('POST', $call['method']);
        $this->assertSame('https://api.couryo.com/v1/emails', $call['url']);
        $this->assertSame('Bearer ck_test_abc', $call['headers']['Authorization']);
        $this->assertSame('application/json', $call['headers']['Accept']);
        $this->assertSame('application/json', $call['headers']['Content-Type']);
        $this->assertSame('pt-BR', $call['headers']['Accept-Language']);
        $this->assertSame('couryo-php/0.1.0', $call['headers']['User-Agent']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $call['headers']['Idempotency-Key']);
        $this->assertSame(30.0, $call['timeout']);
        $this->assertSame(self::EMAIL, $fake->body(0));
        $this->assertStringContainsString('"text":"Olá"', $call['body']);
    }

    public function testEveryPostGetsANewKeyAndGetsHaveNone(): void
    {
        [$couryo, $fake] = FakeTransport::client([FakeTransport::json(200, ['data' => [], 'has_more' => false])], ['language' => 'en', 'timeout' => 5]);

        $couryo->emails->send(self::EMAIL);
        $couryo->emails->send(self::EMAIL);
        $couryo->emails->list();

        $this->assertNotSame($fake->calls[0]['headers']['Idempotency-Key'], $fake->calls[1]['headers']['Idempotency-Key']);
        $this->assertArrayNotHasKey('Idempotency-Key', $fake->calls[2]['headers']);
        $this->assertArrayNotHasKey('Content-Type', $fake->calls[2]['headers']);
        $this->assertNull($fake->calls[2]['body']);
        $this->assertSame('en', $fake->calls[2]['headers']['Accept-Language']);
        $this->assertSame(5.0, $fake->calls[2]['timeout']);
    }

    public function testRetriesNetworkErrorsAnd5xxWithTheSameIdempotencyKey(): void
    {
        [$couryo, $fake] = FakeTransport::client([
            new TransportException('Could not resolve host'),
            FakeTransport::apiError(500, 'internal_error', 'api_error'),
            FakeTransport::json(202, ['id' => 'msg_2', 'status' => 'queued']),
        ]);

        $result = $couryo->emails->send(self::EMAIL, ['idempotency_key' => 'pedido-42']);

        $this->assertSame('msg_2', $result['id']);
        $this->assertCount(3, $fake->calls);
        $this->assertSame(['pedido-42'], array_values(array_unique(array_map(static fn (array $c): string => $c['headers']['Idempotency-Key'], $fake->calls))));
        $this->assertCount(2, $fake->sleeps);
        $this->assertGreaterThanOrEqual(0.4, $fake->sleeps[0]);
        $this->assertLessThanOrEqual(0.6, $fake->sleeps[0]);
        $this->assertGreaterThanOrEqual(0.8, $fake->sleeps[1]);
        $this->assertLessThanOrEqual(1.2, $fake->sleeps[1]);
    }

    public function testAutomaticKeyIsReusedOnRetry(): void
    {
        [$couryo, $fake] = FakeTransport::client([new TransportException('timeout'), FakeTransport::json(202, ['id' => 'msg_3', 'status' => 'queued'])]);

        $couryo->emails->send(self::EMAIL);

        $this->assertCount(2, $fake->calls);
        $this->assertSame($fake->calls[0]['headers']['Idempotency-Key'], $fake->calls[1]['headers']['Idempotency-Key']);
    }

    public function testRetriesRateLimitedHonoringRetryAfter(): void
    {
        [$couryo, $fake] = FakeTransport::client([
            FakeTransport::apiError(429, 'rate_limited', 'rate_limited', ['retry-after' => '0']),
            FakeTransport::apiError(429, 'rate_limited', 'rate_limited', ['Retry-After' => '2']),
            FakeTransport::json(200, ['data' => [], 'has_more' => false]),
        ]);

        $couryo->emails->list();

        $this->assertCount(3, $fake->calls);
        $this->assertSame([0.0, 2.0], $fake->sleeps);
    }

    public function testRetryAfterIsCappedAt60Seconds(): void
    {
        [$couryo, $fake] = FakeTransport::client([
            FakeTransport::apiError(503, 'billing_unavailable', 'api_error', ['retry-after' => '120']),
            FakeTransport::json(200, ['data' => [], 'has_more' => false]),
        ]);

        $couryo->domains->list();

        $this->assertSame([60.0], $fake->sleeps);
    }

    public function testRetriesIdempotencyInProgress(): void
    {
        [$couryo, $fake] = FakeTransport::client([
            FakeTransport::apiError(409, 'idempotency_in_progress', 'conflict', ['retry-after' => '1']),
            FakeTransport::json(202, ['id' => 'msg_4', 'status' => 'queued']),
        ]);

        $this->assertSame('msg_4', $couryo->emails->send(self::EMAIL)['id']);
        $this->assertCount(2, $fake->calls);
        $this->assertSame([1.0], $fake->sleeps);
    }

    public function testDoesNotRetryErrorsThatWouldFailAgainAndExposesTheFields(): void
    {
        [$couryo, $fake] = FakeTransport::client([FakeTransport::apiError(422, 'domain_not_verified')]);

        try {
            $couryo->emails->send(self::EMAIL);
            $this->fail('expected an exception');
        } catch (InvalidRequestError $e) {
            $this->assertInstanceOf(CouryoException::class, $e);
            $this->assertSame(422, $e->getCode());
            $this->assertSame(422, $e->getStatus());
            $this->assertSame(422, $e->status);
            $this->assertSame('domain_not_verified', $e->getErrorCode());
            $this->assertSame('domain_not_verified', $e->errorCode);
            $this->assertSame('invalid_request', $e->getType());
            $this->assertSame('msg domain_not_verified', $e->getMessage());
            $this->assertSame('to', $e->getParam());
            $this->assertSame('https://couryo.com/docs/errors#domain_not_verified', $e->getDocUrl());
            $this->assertSame('req_1', $e->getRequestId());
            $this->assertNull($e->getRetryAfter());
            $this->assertSame('domain_not_verified', $e->getError()['code']);
        }
        $this->assertCount(1, $fake->calls);
        $this->assertSame([], $fake->sleeps);
    }

    public function testDoesNotRetryTierLimitsOrIdempotencyConflicts(): void
    {
        [$couryo, $fake] = FakeTransport::client([FakeTransport::apiError(429, 'daily_limit_reached', 'tier_limit', ['retry-after' => '3600'])]);
        try {
            $couryo->emails->send(self::EMAIL);
            $this->fail('expected an exception');
        } catch (TierLimitError $e) {
            $this->assertSame('daily_limit_reached', $e->getErrorCode());
            $this->assertSame(3600.0, $e->getRetryAfter());
        }
        $this->assertCount(1, $fake->calls);

        [$couryo, $fake] = FakeTransport::client([FakeTransport::apiError(409, 'idempotency_conflict', 'conflict')]);
        $this->expectException(ConflictError::class);
        try {
            $couryo->emails->send(self::EMAIL, ['idempotency_key' => 'k']);
        } finally {
            $this->assertCount(1, $fake->calls);
        }
    }

    public function testGivesUpAfterMaxRetries(): void
    {
        [$couryo, $fake] = FakeTransport::client([FakeTransport::apiError(503, 'billing_unavailable', 'api_error')], ['max_retries' => 1]);

        try {
            $couryo->domains->list();
            $this->fail('expected an exception');
        } catch (ApiError $e) {
            $this->assertSame(503, $e->getStatus());
        }
        $this->assertCount(2, $fake->calls);
    }

    public function testNetworkErrorAfterRetries(): void
    {
        [$couryo, $fake] = FakeTransport::client([new TransportException('Connection refused')]);

        try {
            $couryo->emails->get('msg_1');
            $this->fail('expected an exception');
        } catch (NetworkError $e) {
            $this->assertSame(0, $e->getCode());
            $this->assertSame('network', $e->getType());
            $this->assertSame('network_error', $e->getErrorCode());
            $this->assertSame('Network error: Connection refused', $e->getMessage());
            $this->assertInstanceOf(TransportException::class, $e->getPrevious());
        }
        $this->assertCount(3, $fake->calls);
    }

    public function testErrorBodyThatIsNotJson(): void
    {
        [$couryo] = FakeTransport::client([new Response(502, [], str_repeat('x', 400))], ['max_retries' => 0]);
        try {
            $couryo->domains->list();
            $this->fail('expected an exception');
        } catch (ApiError $e) {
            $this->assertSame(str_repeat('x', 300), $e->getMessage());
            $this->assertSame('api_error', $e->getType());
            $this->assertSame('unknown', $e->getErrorCode());
        }

        [$couryo] = FakeTransport::client([new Response(404, [], '')]);
        try {
            $couryo->domains->get('dom_x');
            $this->fail('expected an exception');
        } catch (NotFoundError $e) {
            $this->assertSame('HTTP 404', $e->getMessage());
        }

        [$couryo] = FakeTransport::client([FakeTransport::apiError(429, 'too_fast', 'rate_limited')]);
        $this->expectException(RateLimitError::class);
        $couryo->emails->list();
    }

    public function testNoContentReturnsNull(): void
    {
        [$couryo, $fake] = FakeTransport::client([new Response(204)]);

        $this->assertNull($couryo->http->request('DELETE', '/domains/dom_1'));
        $couryo->domains->delete('dom_1');
        $this->assertSame(['DELETE /domains/dom_1', 'DELETE /domains/dom_1'], $fake->routes());
    }

    public function testQueryStringSkipsNullAndEmptyValues(): void
    {
        [$couryo, $fake] = FakeTransport::client([FakeTransport::json(200, ['data' => [], 'has_more' => false])]);

        $couryo->emails->list(['status' => 'bounced', 'recipient' => null, 'tag' => '', 'period' => '7d', 'limit' => 10]);
        $couryo->http->request('GET', '/x', ['a' => true, 'b' => false, 'c' => 'a b+c@d']);

        $this->assertSame([
            'GET /emails?status=bounced&period=7d&limit=10',
            'GET /x?a=true&b=false&c=a%20b%2Bc%40d',
        ], $fake->routes());
    }

    public function testIteratesEveryPage(): void
    {
        [$couryo, $fake] = FakeTransport::client([
            FakeTransport::json(200, ['data' => [['id' => 'msg_3'], ['id' => 'msg_2']], 'has_more' => true]),
            FakeTransport::json(200, ['data' => [['id' => 'msg_1']], 'has_more' => false]),
        ]);

        $ids = [];
        foreach ($couryo->emails->iterate(['status' => 'bounced']) as $email) {
            $ids[] = $email['id'];
        }

        $this->assertSame(['msg_3', 'msg_2', 'msg_1'], $ids);
        $this->assertSame([
            'GET /emails?limit=100&status=bounced',
            'GET /emails?limit=100&status=bounced&starting_after=msg_2',
        ], $fake->routes());
    }

    public function testIterateStopsOnEmptyPage(): void
    {
        [$couryo, $fake] = FakeTransport::client([FakeTransport::json(200, ['data' => [], 'has_more' => true])]);

        $this->assertSame([], iterator_to_array($couryo->events->iterate(['type' => 'bounced'])));
        $this->assertSame(['GET /events?limit=100&type=bounced'], $fake->routes());
    }

    public function testRequiresAnApiKey(): void
    {
        $previous = getenv('COURYO_API_KEY');
        putenv('COURYO_API_KEY');
        unset($_ENV['COURYO_API_KEY'], $_SERVER['COURYO_API_KEY']);
        try {
            try {
                new Couryo();
                $this->fail('expected an exception');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('missing API key', $e->getMessage());
            }
            putenv('COURYO_API_KEY=ck_test_env');
            $fake = new FakeTransport([FakeTransport::json(200, ['data' => [], 'has_more' => false])]);
            (new Couryo(['transport' => $fake, 'base_url' => 'http://localhost:3000/v1///']))->domains->list();
            $this->assertSame('Bearer ck_test_env', $fake->calls[0]['headers']['Authorization']);
            $this->assertSame('http://localhost:3000/v1/domains', $fake->calls[0]['url']);
        } finally {
            putenv($previous === false ? 'COURYO_API_KEY' : "COURYO_API_KEY={$previous}");
        }

        $this->assertInstanceOf(Couryo::class, new Couryo('ck_test_string'));
    }

    public function testBackoff(): void
    {
        $this->assertSame(2.0, HttpClient::backoff(1, 2.0));
        $this->assertSame(60.0, HttpClient::backoff(1, 300.0));
        for ($i = 0; $i < 50; $i++) {
            $b = HttpClient::backoff(3);
            $this->assertGreaterThanOrEqual(1.6, $b);
            $this->assertLessThanOrEqual(2.4, $b);
            $max = HttpClient::backoff(10);
            $this->assertGreaterThanOrEqual(6.4, $max);
            $this->assertLessThanOrEqual(9.6, $max);
        }
    }
}
