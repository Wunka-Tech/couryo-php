<?php

declare(strict_types=1);

namespace Couryo\Resource;

/** /v1/contacts (the id accepts con_... or the email address) */
final class Contacts extends AbstractResource
{
    /**
     * Creates the contact or merges the attributes of the existing one with the same email (201 created, 200 updated).
     *
     * @param array{email: string, attributes?: array<string, mixed>, tags?: list<string>} $body
     * @param array{idempotency_key?: string} $options
     *
     * @return array<string, mixed>
     */
    public function create(array $body, array $options = []): array
    {
        return $this->http->request('POST', '/contacts', [], self::maps($body, ['attributes']), $options);
    }

    /**
     * Same as create(): the API upserts by email.
     *
     * @param array{email: string, attributes?: array<string, mixed>, tags?: list<string>} $body
     * @param array{idempotency_key?: string} $options
     *
     * @return array<string, mixed>
     */
    public function upsert(array $body, array $options = []): array
    {
        return $this->create($body, $options);
    }

    /**
     * @param array{limit?: int, starting_after?: string, q?: string} $params
     *
     * @return array{data: list<array<string, mixed>>, has_more: bool}
     */
    public function list(array $params = []): array
    {
        return $this->http->request('GET', '/contacts', $params);
    }

    /** @return array<string, mixed> */
    public function get(string $idOrEmail): array
    {
        return $this->http->request('GET', '/contacts/' . self::enc($idOrEmail));
    }

    /**
     * When tags is sent it replaces the whole list.
     *
     * @param array{email?: string, attributes?: array<string, mixed>, tags?: list<string>} $body
     *
     * @return array<string, mixed>
     */
    public function update(string $idOrEmail, array $body): array
    {
        return $this->http->request('PATCH', '/contacts/' . self::enc($idOrEmail), [], self::maps($body, ['attributes']));
    }

    public function delete(string $idOrEmail): void
    {
        $this->http->request('DELETE', '/contacts/' . self::enc($idOrEmail));
    }

    /**
     * @param array{q?: string, limit?: int} $params
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterate(array $params = []): \Generator
    {
        return $this->paginate('/contacts', $params);
    }

    /**
     * Counts an audience: {total, subscribed, suppressed}.
     *
     * @param array<string, mixed>            $audience ['type' => 'all'] or ['type' => 'segment', 'match' => 'all', 'conditions' => [...]]
     * @param array{idempotency_key?: string} $options
     *
     * @return array{total: int, subscribed: int, suppressed: int}
     */
    public function count(array $audience = ['type' => 'all'], array $options = []): array
    {
        return $this->http->request('POST', '/contacts/count', [], ['audience' => $audience], $options);
    }
}
