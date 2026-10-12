<?php

declare(strict_types=1);

namespace Couryo\Tests;

use Couryo\Couryo;
use Couryo\Exception\NetworkError;
use Couryo\Http\Psr18Transport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class Psr18TransportTest extends TestCase
{
    public function testSendsThroughAPsr18Client(): void
    {
        $client = new class () implements ClientInterface {
            /** @var list<RequestInterface> */
            public array $requests = [];

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->requests[] = $request;

                return new Response(202, ['Content-Type' => 'application/json'], '{"id":"msg_1","status":"queued"}');
            }
        };
        $factory = new Psr17Factory();
        $couryo = new Couryo(['api_key' => 'ck_test_abc', 'transport' => new Psr18Transport($client, $factory, $factory)]);

        $result = $couryo->emails->send(['from' => 'oi@loja.com.br', 'to' => 'ana@exemplo.com.br', 'subject' => 'Oi', 'text' => 'Olá']);

        $this->assertSame(['id' => 'msg_1', 'status' => 'queued'], $result);
        $request = $client->requests[0];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://api.couryo.com/v1/emails', (string) $request->getUri());
        $this->assertSame('Bearer ck_test_abc', $request->getHeaderLine('Authorization'));
        $this->assertNotSame('', $request->getHeaderLine('Idempotency-Key'));
        $this->assertSame('{"from":"oi@loja.com.br","to":"ana@exemplo.com.br","subject":"Oi","text":"Olá"}', (string) $request->getBody());
    }

    public function testNetworkExceptionsAreRetried(): void
    {
        $client = new class () implements ClientInterface {
            public int $calls = 0;

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->calls++;

                throw new class ('connection refused', $request) extends \RuntimeException implements NetworkExceptionInterface {
                    public function __construct(string $message, private RequestInterface $request)
                    {
                        parent::__construct($message);
                    }

                    public function getRequest(): RequestInterface
                    {
                        return $this->request;
                    }
                };
            }
        };
        $factory = new Psr17Factory();
        $couryo = new Couryo(['api_key' => 'ck_test_abc', 'transport' => new Psr18Transport($client, $factory, $factory), 'sleep' => static fn () => null]);

        try {
            $couryo->domains->list();
            $this->fail('expected an exception');
        } catch (NetworkError $e) {
            $this->assertSame('Network error: connection refused', $e->getMessage());
        }
        $this->assertSame(3, $client->calls);
    }
}
