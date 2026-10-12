<?php

declare(strict_types=1);

namespace Couryo\Exception;

/**
 * Every API error, with the fields of the JSON `error` object plus the HTTP status.
 *
 * PHP reserves Exception::$code for an integer, so the API code (for example
 * "domain_not_verified") is in getErrorCode() and in the $errorCode property,
 * while getCode() returns the HTTP status (0 for network errors).
 */
class CouryoException extends \RuntimeException
{
    /** HTTP status, 0 for network errors and timeouts. */
    public readonly int $status;

    /** Error type: invalid_request, authentication, permission, not_found, conflict, rate_limited, tier_limit, api_error or network. */
    public readonly string $type;

    /** API error code, for example "domain_not_verified" or "rate_limited". */
    public readonly string $errorCode;

    /** Field that caused the error, when there is one (for example "to[1]"). */
    public readonly ?string $param;

    public readonly ?string $docUrl;

    public readonly ?string $requestId;

    /** Seconds from the Retry-After header, when the API sent one. */
    public readonly ?float $retryAfter;

    /** @var array<string, mixed> The whole `error` object (extra fields such as upgrade_options included). */
    public readonly array $error;

    /**
     * @param array<string, mixed> $error the `error` object of the response body
     */
    public function __construct(int $status, array $error = [], ?float $retryAfter = null, ?\Throwable $previous = null)
    {
        $message = self::str($error['message'] ?? null) ?? "HTTP {$status}";
        parent::__construct($message, $status, $previous);
        $this->status = $status;
        $this->type = self::str($error['type'] ?? null) ?? ($status === 0 ? 'network' : 'api_error');
        $this->errorCode = self::str($error['code'] ?? null) ?? ($status === 0 ? 'network_error' : 'unknown');
        $this->param = self::str($error['param'] ?? null);
        $this->docUrl = self::str($error['doc_url'] ?? null);
        $this->requestId = self::str($error['request_id'] ?? null);
        $this->retryAfter = $retryAfter;
        $this->error = $error;
    }

    /**
     * Builds the subclass that matches the error `type` (or the HTTP status when the type is missing).
     *
     * @param array<string, mixed> $error
     */
    public static function create(int $status, array $error = [], ?float $retryAfter = null, ?\Throwable $previous = null): self
    {
        $class = match (self::str($error['type'] ?? null)) {
            'invalid_request' => InvalidRequestError::class,
            'authentication' => AuthenticationError::class,
            'permission' => PermissionError::class,
            'not_found' => NotFoundError::class,
            'conflict' => ConflictError::class,
            'rate_limited' => RateLimitError::class,
            'tier_limit' => TierLimitError::class,
            'api_error' => ApiError::class,
            'network' => NetworkError::class,
            default => match (true) {
                $status === 0 => NetworkError::class,
                $status === 400, $status === 422 => InvalidRequestError::class,
                $status === 401 => AuthenticationError::class,
                $status === 402 => TierLimitError::class,
                $status === 403 => PermissionError::class,
                $status === 404 => NotFoundError::class,
                $status === 409 => ConflictError::class,
                $status === 429 => RateLimitError::class,
                $status >= 500 => ApiError::class,
                default => self::class,
            },
        };

        return new $class($status, $error, $retryAfter, $previous);
    }

    /** API error code, for example "domain_not_verified". */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getParam(): ?string
    {
        return $this->param;
    }

    public function getDocUrl(): ?string
    {
        return $this->docUrl;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    /** HTTP status, 0 for network errors (same as getCode()). */
    public function getStatus(): int
    {
        return $this->status;
    }

    public function getRetryAfter(): ?float
    {
        return $this->retryAfter;
    }

    /** @return array<string, mixed> */
    public function getError(): array
    {
        return $this->error;
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : null;
    }
}
