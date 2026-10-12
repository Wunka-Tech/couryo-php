<?php

declare(strict_types=1);

namespace Couryo\Resource;

/** /v1/templates (by id tpl_... or by name) */
final class Templates extends AbstractResource
{
    /** @return array{data: list<array<string, mixed>>, has_more: bool} */
    public function list(): array
    {
        return $this->http->request('GET', '/templates');
    }

    /**
     * @param array{name: string, format?: string, subject: string, html: string, text?: string} $body
     * @param array{idempotency_key?: string} $options
     *
     * @return array<string, mixed>
     */
    public function create(array $body, array $options = []): array
    {
        return $this->http->request('POST', '/templates', [], $body, $options);
    }

    /** @return array<string, mixed> */
    public function get(string $idOrName): array
    {
        return $this->http->request('GET', '/templates/' . self::enc($idOrName));
    }

    /**
     * Content changes create a new version.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function update(string $idOrName, array $body): array
    {
        return $this->http->request('PATCH', '/templates/' . self::enc($idOrName), [], $body);
    }

    public function delete(string $idOrName): void
    {
        $this->http->request('DELETE', '/templates/' . self::enc($idOrName));
    }

    /** @return array{data: list<array<string, mixed>>, has_more: bool} versions, newest first */
    public function versions(string $idOrName): array
    {
        return $this->http->request('GET', '/templates/' . self::enc($idOrName) . '/versions');
    }

    /**
     * Renders the template without sending anything.
     *
     * @param array{variables?: array<string, mixed>, version?: int} $body
     * @param array{idempotency_key?: string}                        $options
     *
     * @return array<string, mixed>
     */
    public function preview(string $idOrName, array $body = [], array $options = []): array
    {
        $body = self::maps(array_merge(['variables' => []], $body), ['variables']);

        return $this->http->request('POST', '/templates/' . self::enc($idOrName) . '/preview', [], $body, $options);
    }
}
