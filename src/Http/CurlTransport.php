<?php

declare(strict_types=1);

namespace Couryo\Http;

/** Default transport, built on ext-curl. Reuses one handle so connections stay open between calls. */
final class CurlTransport implements Transport
{
    private ?\CurlHandle $handle = null;

    /**
     * @param array<int, mixed> $curlOptions extra CURLOPT_* values (proxy, CA bundle...)
     */
    public function __construct(private readonly array $curlOptions = [])
    {
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): Response
    {
        if ($this->handle === null) {
            $handle = curl_init();
            if ($handle === false) {
                throw new TransportException('could not start curl');
            }
            $this->handle = $handle;
        } else {
            curl_reset($this->handle);
        }
        $ch = $this->handle;

        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        // No "Expect: 100-continue" round trip for large bodies (attachments).
        $lines[] = 'Expect:';

        $responseHeaders = [];
        $timeoutMs = max(1, (int) ceil($timeout * 1000));
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                if (str_starts_with($line, 'HTTP/')) {
                    $responseHeaders = [];
                }
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        foreach ($this->curlOptions as $option => $value) {
            $options[$option] = $value;
        }
        curl_setopt_array($ch, $options);

        $raw = curl_exec($ch);
        if ($raw === false || !is_string($raw)) {
            throw new TransportException(curl_error($ch) ?: 'curl request failed', curl_errno($ch));
        }

        return new Response((int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $responseHeaders, $raw);
    }
}
