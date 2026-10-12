<?php

declare(strict_types=1);

namespace Couryo\Resource;

/** /v1/domains */
final class Domains extends AbstractResource
{
    /** @return array{data: list<array<string, mixed>>, has_more: bool} */
    public function list(): array
    {
        return $this->http->request('GET', '/domains');
    }

    /**
     * Adds a domain (201, with the DNS records to create).
     *
     * @param array{idempotency_key?: string} $options
     *
     * @return array<string, mixed>
     */
    public function create(string $name, array $options = []): array
    {
        return $this->http->request('POST', '/domains', [], ['name' => $name], $options);
    }

    /** @return array<string, mixed> */
    public function get(string $id): array
    {
        return $this->http->request('GET', '/domains/' . self::enc($id));
    }

    public function delete(string $id): void
    {
        $this->http->request('DELETE', '/domains/' . self::enc($id));
    }

    /**
     * Checks the DNS now.
     *
     * @param array{idempotency_key?: string} $options
     *
     * @return array<string, mixed>
     */
    public function verify(string $id, array $options = []): array
    {
        return $this->http->request('POST', '/domains/' . self::enc($id) . '/verify', [], null, $options);
    }

    /**
     * Deliverability diagnosis (read only). With $refresh = true every check runs now.
     *
     * @param bool|array{refresh?: bool} $refresh
     *
     * @return array<string, mixed>
     */
    public function health(string $id, bool|array $refresh = false): array
    {
        $refresh = is_array($refresh) ? !empty($refresh['refresh']) : $refresh;

        return $this->http->request('GET', '/domains/' . self::enc($id) . '/health', $refresh ? ['refresh' => 1] : []);
    }
}
