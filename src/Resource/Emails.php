<?php

declare(strict_types=1);

namespace Couryo\Resource;

/** /v1/emails */
final class Emails extends AbstractResource
{
    private const MAPS = ['variables', 'headers', 'tags', 'metadata'];

    /**
     * Queues one email (202 {id, status}). A POST retried by the SDK reuses the same Idempotency-Key.
     *
     * @param array<string, mixed>            $body    from, to, subject, html, text, template, variables, attachments...
     * @param array{idempotency_key?: string} $options
     *
     * @return array{id: string, status: string}
     */
    public function send(array $body, array $options = []): array
    {
        return $this->http->request('POST', '/emails', [], self::maps($body, self::MAPS), $options);
    }

    /**
     * Up to 100 emails; one result per item, in order: {data: [{id, status} | {error}]}.
     *
     * @param list<array<string, mixed>>      $emails
     * @param array{idempotency_key?: string} $options
     *
     * @return array{data: list<array<string, mixed>>}
     */
    public function batch(array $emails, array $options = []): array
    {
        $emails = array_map(static fn (array $email): array => self::maps($email, self::MAPS), array_values($emails));

        return $this->http->request('POST', '/emails/batch', [], ['emails' => $emails], $options);
    }

    /**
     * Checks content and sender before sending (nothing is sent): {score, issues}.
     *
     * @param array<string, mixed>            $body
     * @param array{idempotency_key?: string} $options
     *
     * @return array<string, mixed>
     */
    public function check(array $body, array $options = []): array
    {
        return $this->http->request('POST', '/emails/check', [], self::maps($body, self::MAPS), $options);
    }

    /** @return array<string, mixed> one email with its events */
    public function get(string $id): array
    {
        return $this->http->request('GET', '/emails/' . self::enc($id));
    }

    /**
     * @param array{limit?: int, starting_after?: string, status?: string, recipient?: string, tag?: string, period?: string} $params
     *
     * @return array{data: list<array<string, mixed>>, has_more: bool}
     */
    public function list(array $params = []): array
    {
        return $this->http->request('GET', '/emails', $params);
    }

    /**
     * Every email across all pages: foreach ($couryo->emails->iterate(['status' => 'bounced']) as $email).
     *
     * @param array{status?: string, recipient?: string, tag?: string, period?: string, limit?: int} $params
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterate(array $params = []): \Generator
    {
        return $this->paginate('/emails', $params);
    }
}
