<?php

declare(strict_types=1);

namespace Couryo\Resource;

/** /v1/events: email delivery events (list, iterate) and contact events (track). */
final class Events extends AbstractResource
{
    /**
     * Email delivery events, for whoever missed a webhook.
     *
     * @param array{limit?: int, starting_after?: string, type?: string, email_id?: string} $params
     *
     * @return array{data: list<array<string, mixed>>, has_more: bool}
     */
    public function list(array $params = []): array
    {
        return $this->http->request('GET', '/events', $params);
    }

    /**
     * @param array{type?: string, email_id?: string, limit?: int} $params
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterate(array $params = []): \Generator
    {
        return $this->paginate('/events', $params);
    }

    /**
     * Records a contact event (creates or updates the contact, ends conversions, enrolls in sequences): 201.
     *
     * @param array{contact: array{email: string, attributes?: array<string, mixed>}, name: string, properties?: array<string, mixed>} $body
     * @param array{idempotency_key?: string} $options
     *
     * @return array<string, mixed> {id, contact_id, name, enrolled, converted, created_at}
     */
    public function track(array $body, array $options = []): array
    {
        $body = self::maps($body, ['properties']);
        if (isset($body['contact']) && is_array($body['contact'])) {
            $body['contact'] = self::maps($body['contact'], ['attributes']);
        }

        return $this->http->request('POST', '/events', [], $body, $options);
    }
}
