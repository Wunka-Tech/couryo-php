<?php

declare(strict_types=1);

namespace Couryo\Http;

/**
 * Sends one HTTP request. The client adds auth, JSON, retries and idempotency on top of it.
 *
 * Implementations must throw TransportException for network failures and timeouts
 * (those are retried) and return every HTTP response, whatever the status.
 */
interface Transport
{
    /**
     * @param string                $method  GET, POST, PATCH or DELETE
     * @param array<string, string> $headers header name => value
     * @param float                 $timeout seconds for this attempt
     *
     * @throws TransportException
     */
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): Response;
}
