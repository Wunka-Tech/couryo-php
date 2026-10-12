<?php

declare(strict_types=1);

namespace Couryo\Resource;

/** /v1/webhooks */
final class Webhooks extends AbstractResource
{
    /** @return array{data: list<array<string, mixed>>, has_more: bool} */
    public function list(): array
    {
        return $this->http->request('GET', '/webhooks');
    }

    /**
     * The secret (whsec_...) comes back only here: store it.
     *
     * @param array{url: string, events: list<string>} $body
     * @param array{idempotency_key?: string}          $options
     *
     * @return array<string, mixed>
     */
    public function create(array $body, array $options = []): array
    {
        return $this->http->request('POST', '/webhooks', [], $body, $options);
    }

    /** @return array<string, mixed> */
    public function get(string $id): array
    {
        return $this->http->request('GET', '/webhooks/' . self::enc($id));
    }

    /**
     * @param array{url?: string, events?: list<string>, enabled?: bool} $body
     *
     * @return array<string, mixed>
     */
    public function update(string $id, array $body): array
    {
        return $this->http->request('PATCH', '/webhooks/' . self::enc($id), [], $body);
    }

    public function delete(string $id): void
    {
        $this->http->request('DELETE', '/webhooks/' . self::enc($id));
    }
}
