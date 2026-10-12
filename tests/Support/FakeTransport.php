<?php

declare(strict_types=1);

namespace Couryo\Tests\Support;

use Couryo\Couryo;
use Couryo\Http\Response;
use Couryo\Http\Transport;
use Couryo\Http\TransportException;

/** Answers in order (the last answer repeats) and records every call. No network. */
final class FakeTransport implements Transport
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string, timeout: float}> */
    public array $calls = [];

    /** @var list<float> */
    public array $sleeps = [];

    private int $index = 0;

    /**
     * @param list<Response|TransportException> $answers
     */
    public function __construct(private array $answers)
    {
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): Response
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'timeout' => $timeout];
        $answer = $this->answers[min($this->index++, count($this->answers) - 1)];
        if ($answer instanceof TransportException) {
            throw $answer;
        }

        return $answer;
    }

    /**
     * @param list<Response|TransportException> $answers
     * @param array<string, mixed>              $options
     *
     * @return array{0: Couryo, 1: self}
     */
    public static function client(array $answers, array $options = []): array
    {
        $fake = new self($answers);
        $couryo = new Couryo(array_merge([
            'api_key' => 'ck_test_abc',
            'transport' => $fake,
            'max_retries' => 2,
            'sleep' => static function (float $seconds) use ($fake): void {
                $fake->sleeps[] = $seconds;
            },
        ], $options));

        return [$couryo, $fake];
    }

    /**
     * @param array<string, string> $headers
     */
    public static function json(int $status, mixed $body, array $headers = []): Response
    {
        return new Response($status, array_merge(['content-type' => 'application/json'], $headers), json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, string> $headers
     */
    public static function apiError(int $status, string $code, string $type = 'invalid_request', array $headers = []): Response
    {
        return self::json($status, ['error' => [
            'type' => $type,
            'code' => $code,
            'message' => "msg {$code}",
            'param' => 'to',
            'doc_url' => "https://couryo.com/docs/errors#{$code}",
            'request_id' => 'req_1',
        ]], $headers);
    }

    /** Method and path of every call, without the base URL. */
    public function routes(): array
    {
        return array_map(
            static fn (array $call): string => $call['method'] . ' ' . str_replace(Couryo::DEFAULT_BASE_URL, '', $call['url']),
            $this->calls,
        );
    }

    /** @return mixed decoded body of call $i */
    public function body(int $i): mixed
    {
        $body = $this->calls[$i]['body'];

        return $body === null ? null : json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }
}
