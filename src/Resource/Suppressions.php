<?php

declare(strict_types=1);

namespace Couryo\Resource;

/** /v1/suppressions */
final class Suppressions extends AbstractResource
{
    /**
     * @param array{limit?: int, starting_after?: string, q?: string, reason?: string} $params
     *
     * @return array{data: list<array<string, mixed>>, has_more: bool}
     */
    public function list(array $params = []): array
    {
        return $this->http->request('GET', '/suppressions', $params);
    }

    /**
     * Adds an address (reason manual). The stream defaults to "all".
     *
     * @param array{email: string, stream?: string} $body
     * @param array{idempotency_key?: string}       $options
     *
     * @return array<string, mixed>
     */
    public function add(array $body, array $options = []): array
    {
        return $this->http->request('POST', '/suppressions', [], array_merge(['stream' => 'all'], $body), $options);
    }

    /** Removes the address from every stream. */
    public function remove(string $email): void
    {
        $this->http->request('DELETE', '/suppressions/' . self::enc($email));
    }
}
