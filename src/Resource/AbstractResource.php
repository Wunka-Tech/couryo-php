<?php

declare(strict_types=1);

namespace Couryo\Resource;

use Couryo\HttpClient;

/** Shared helpers of the API resources. */
abstract class AbstractResource
{
    public function __construct(protected readonly HttpClient $http)
    {
    }

    /** URL-encodes one path segment (ids, names and email addresses). */
    protected static function enc(string $value): string
    {
        return rawurlencode($value);
    }

    /**
     * Walks every page (cursor pagination, newest first): limit 100, then starting_after = last id while has_more.
     *
     * @param array<string, mixed> $params
     *
     * @return \Generator<int, array<string, mixed>>
     */
    protected function paginate(string $path, array $params): \Generator
    {
        $after = null;
        while (true) {
            $page = $this->http->request('GET', $path, array_merge(['limit' => 100], $params, ['starting_after' => $after]));
            $data = is_array($page) && is_array($page['data'] ?? null) ? $page['data'] : [];
            foreach ($data as $item) {
                yield $item;
            }
            if (empty($page['has_more']) || $data === []) {
                return;
            }
            $last = $data[array_key_last($data)];
            $after = is_array($last) ? ($last['id'] ?? null) : null;
            if ($after === null) {
                return;
            }
        }
    }

    /**
     * JSON maps must go out as objects: an empty PHP array would become [] instead of {}.
     *
     * @param array<string, mixed> $body
     * @param list<string>         $keys
     *
     * @return array<string, mixed>
     */
    protected static function maps(array $body, array $keys): array
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $body) && $body[$key] === []) {
                $body[$key] = new \stdClass();
            }
        }

        return $body;
    }
}
