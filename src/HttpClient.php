<?php

declare(strict_types=1);

namespace Couryo;

use Couryo\Exception\CouryoException;
use Couryo\Exception\NetworkError;
use Couryo\Http\CurlTransport;
use Couryo\Http\Transport;
use Couryo\Http\TransportException;

/**
 * Auth, JSON, retries with backoff and Retry-After, and an automatic Idempotency-Key on every POST.
 *
 * Used by the resources of Couryo\Couryo; you only need it for an endpoint the SDK does not cover yet.
 */
final class HttpClient
{
    public const DEFAULT_BASE_URL = 'https://api.couryo.com/v1';
    public const VERSION = '0.1.0';

    public readonly string $baseUrl;
    private readonly string $apiKey;
    private readonly int $maxRetries;
    private readonly float $timeout;
    private readonly string $language;
    private readonly Transport $transport;
    /** @var callable(float): void */
    private $sleep;

    /**
     * @param array{api_key?: ?string, base_url?: ?string, max_retries?: ?int, timeout?: int|float|null, language?: ?string, transport?: ?Transport, sleep?: ?callable} $options
     */
    public function __construct(array $options = [])
    {
        $key = $options['api_key'] ?? null;
        if ($key === null || $key === '') {
            $env = getenv('COURYO_API_KEY');
            $key = is_string($env) && $env !== '' ? $env : ($_ENV['COURYO_API_KEY'] ?? $_SERVER['COURYO_API_KEY'] ?? null);
        }
        if (!is_string($key) || $key === '') {
            throw new \InvalidArgumentException("Couryo: missing API key. Pass ['api_key' => ...] or set COURYO_API_KEY.");
        }
        $this->apiKey = $key;
        $this->baseUrl = rtrim((string) ($options['base_url'] ?? self::DEFAULT_BASE_URL), '/');
        $this->maxRetries = max(0, (int) ($options['max_retries'] ?? 2));
        $this->timeout = (float) ($options['timeout'] ?? 30);
        $this->language = (string) ($options['language'] ?? 'pt-BR');
        $this->transport = $options['transport'] ?? new CurlTransport();
        $this->sleep = $options['sleep'] ?? static function (float $seconds): void {
            if ($seconds > 0) {
                usleep((int) round($seconds * 1_000_000));
            }
        };
    }

    /**
     * Delay in seconds before retry `$attempt` (1-based): Retry-After when given (at most 60 s),
     * otherwise 0.5 s, 1 s, 2 s... with jitter of 0.8x to 1.2x, at most 8 s.
     */
    public static function backoff(int $attempt, ?float $retryAfter = null): float
    {
        if ($retryAfter !== null && is_finite($retryAfter)) {
            return max(0.0, min($retryAfter, 60.0));
        }
        $base = min(8.0, 0.5 * 2 ** ($attempt - 1));

        return round($base * (0.8 + (mt_rand() / mt_getrandmax()) * 0.4), 3);
    }

    /**
     * @param 'GET'|'POST'|'PATCH'|'DELETE'  $method
     * @param array<string, mixed>           $query   null and empty values are skipped
     * @param array<mixed>|null              $body    JSON body (null = no body)
     * @param array{idempotency_key?: string} $options
     *
     * @return mixed decoded JSON (associative arrays), or null for 204
     *
     * @throws CouryoException
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null, array $options = []): mixed
    {
        $url = $this->baseUrl . $path . self::queryString($query);
        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
            'Accept-Language' => $this->language,
            'User-Agent' => 'couryo-php/' . self::VERSION,
        ];
        $payload = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $payload = $body === [] ? '{}' : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        }
        // Same key on every retry: a POST that timed out but went through is never sent twice.
        if ($method === 'POST') {
            $key = $options['idempotency_key'] ?? $options['idempotencyKey'] ?? null;
            $headers['Idempotency-Key'] = is_string($key) && $key !== '' ? $key : self::uuid4();
        }

        for ($attempt = 0; ; $attempt++) {
            try {
                $response = $this->transport->send($method, $url, $headers, $payload, $this->timeout);
            } catch (TransportException $e) {
                if ($attempt >= $this->maxRetries) {
                    throw new NetworkError(0, ['message' => 'Network error: ' . $e->getMessage()], null, $e);
                }
                ($this->sleep)(self::backoff($attempt + 1));
                continue;
            }

            if ($response->status === 204) {
                return null;
            }
            $text = $response->body;
            $json = null;
            if ($text !== '') {
                try {
                    $json = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    $json = null;
                }
            }
            if ($response->status >= 200 && $response->status < 300) {
                return $json;
            }

            $error = is_array($json) && is_array($json['error'] ?? null)
                ? $json['error']
                : ['message' => $text !== '' ? self::truncate($text, 300) : "HTTP {$response->status}"];
            $retryHeader = $response->header('retry-after');
            $retryAfter = $retryHeader !== null && is_numeric(trim($retryHeader)) ? (float) trim($retryHeader) : null;
            $code = $error['code'] ?? null;
            // Retry what can change with time; never a 4xx that would fail again (and never idempotency_conflict).
            $retryable = $response->status >= 500
                || ($response->status === 429 && $code === 'rate_limited')
                || $code === 'idempotency_in_progress';
            if (!$retryable || $attempt >= $this->maxRetries) {
                throw CouryoException::create($response->status, $error, $retryAfter);
            }
            ($this->sleep)(self::backoff($attempt + 1, $retryAfter));
        }
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function queryString(array $query): string
    {
        $pairs = [];
        foreach ($query as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif (is_array($value)) {
                $value = implode(',', array_map('strval', $value));
            } elseif ($value instanceof \DateTimeInterface) {
                $value = $value->format(DATE_ATOM);
            }
            $pairs[] = rawurlencode((string) $name) . '=' . rawurlencode((string) $value);
        }

        return $pairs ? '?' . implode('&', $pairs) : '';
    }

    /** Random UUID v4. */
    public static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private static function truncate(string $text, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($text, 0, $length, 'UTF-8') : substr($text, 0, $length);
    }
}
