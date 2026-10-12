<?php

declare(strict_types=1);

namespace Couryo\Resource;

/** /v1/campaigns */
final class Campaigns extends AbstractResource
{
    /**
     * Creates a draft campaign.
     *
     * @param array<string, mixed>            $body    name, audience, from, reply_to, subject, html, text, template_id, variables
     * @param array{idempotency_key?: string} $options
     *
     * @return array<string, mixed>
     */
    public function create(array $body, array $options = []): array
    {
        return $this->http->request('POST', '/campaigns', [], self::maps($body, ['variables']), $options);
    }

    /**
     * @param array{limit?: int, starting_after?: string, status?: string} $params
     *
     * @return array{data: list<array<string, mixed>>, has_more: bool}
     */
    public function list(array $params = []): array
    {
        return $this->http->request('GET', '/campaigns', $params);
    }

    /** @return array<string, mixed> */
    public function get(string $id): array
    {
        return $this->http->request('GET', '/campaigns/' . self::enc($id));
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function update(string $id, array $body): array
    {
        return $this->http->request('PATCH', '/campaigns/' . self::enc($id), [], self::maps($body, ['variables']));
    }

    /** Only drafts, cancelled or sent campaigns. */
    public function delete(string $id): void
    {
        $this->http->request('DELETE', '/campaigns/' . self::enc($id));
    }

    /**
     * Checks before sending: recipients, suppressed, warnings, review and can_send.
     *
     * @param array{idempotency_key?: string} $options
     *
     * @return array<string, mixed>
     */
    public function preflight(string $id, array $options = []): array
    {
        return $this->action($id, 'preflight', null, $options);
    }

    /**
     * @param array{idempotency_key?: string} $options
     *
     * @return array<string, mixed>
     */
    public function send(string $id, array $options = []): array
    {
        return $this->action($id, 'send', null, $options);
    }

    /**
     * @param string|\DateTimeInterface       $scheduledAt ISO 8601 with offset, for example 2026-10-20T09:00:00-03:00
     * @param array{idempotency_key?: string} $options
     *
     * @return array<string, mixed>
     */
    public function schedule(string $id, string|\DateTimeInterface $scheduledAt, array $options = []): array
    {
        $at = $scheduledAt instanceof \DateTimeInterface ? $scheduledAt->format(DATE_ATOM) : $scheduledAt;

        return $this->action($id, 'schedule', ['scheduled_at' => $at], $options);
    }

    /**
     * @param array{idempotency_key?: string} $options
     *
     * @return array<string, mixed>
     */
    public function pause(string $id, array $options = []): array
    {
        return $this->action($id, 'pause', null, $options);
    }

    /**
     * @param array{idempotency_key?: string} $options
     *
     * @return array<string, mixed>
     */
    public function resume(string $id, array $options = []): array
    {
        return $this->action($id, 'resume', null, $options);
    }

    /**
     * @param array{idempotency_key?: string} $options
     *
     * @return array<string, mixed>
     */
    public function cancel(string $id, array $options = []): array
    {
        return $this->action($id, 'cancel', null, $options);
    }

    /**
     * Sends a test to the given addresses (up to 5) or, without $to, to the account emails.
     *
     * @param string|list<string>|null        $to
     * @param array{idempotency_key?: string} $options
     *
     * @return array<string, mixed>
     */
    public function test(string $id, string|array|null $to = null, array $options = []): array
    {
        $body = $to === null ? [] : ['to' => array_values((array) $to)];

        return $this->action($id, 'test', $body, $options);
    }

    /** @return array<string, mixed> */
    public function stats(string $id): array
    {
        return $this->http->request('GET', '/campaigns/' . self::enc($id) . '/stats');
    }

    /**
     * @param array<string, mixed>|null       $body
     * @param array{idempotency_key?: string} $options
     *
     * @return array<string, mixed>
     */
    private function action(string $id, string $action, ?array $body, array $options): array
    {
        return $this->http->request('POST', '/campaigns/' . self::enc($id) . '/' . $action, [], $body, $options) ?? [];
    }
}
