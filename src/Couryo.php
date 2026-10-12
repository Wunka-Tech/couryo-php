<?php

declare(strict_types=1);

namespace Couryo;

use Couryo\Http\Transport;
use Couryo\Resource\Campaigns;
use Couryo\Resource\Contacts;
use Couryo\Resource\Domains;
use Couryo\Resource\Emails;
use Couryo\Resource\Events;
use Couryo\Resource\Suppressions;
use Couryo\Resource\Templates;
use Couryo\Resource\Webhooks;

/**
 * Couryo API client.
 *
 * ```php
 * $couryo = new Couryo\Couryo(getenv('COURYO_API_KEY'));
 * $result = $couryo->emails->send([
 *     'from' => 'Loja <oi@loja.com.br>',
 *     'to' => 'ana@gmail.com',
 *     'subject' => 'Oi',
 *     'text' => 'Olá!',
 * ]);
 * ```
 */
final class Couryo
{
    public const VERSION = HttpClient::VERSION;
    public const DEFAULT_BASE_URL = HttpClient::DEFAULT_BASE_URL;

    public readonly HttpClient $http;
    public readonly Emails $emails;
    public readonly Domains $domains;
    public readonly Webhooks $webhooks;
    public readonly Suppressions $suppressions;
    public readonly Events $events;
    public readonly Templates $templates;
    public readonly Contacts $contacts;
    public readonly Campaigns $campaigns;

    /**
     * @param string|array{api_key?: ?string, base_url?: ?string, max_retries?: ?int, timeout?: int|float|null, language?: ?string, transport?: ?Transport, sleep?: ?callable} $options
     *        The API key (ck_live_... or ck_test_...) or an options array. Without a key, COURYO_API_KEY is used.
     *        base_url (https://api.couryo.com/v1), max_retries (2), timeout in seconds per attempt (30),
     *        language of error messages (pt-BR or en), transport (CurlTransport), sleep (for tests).
     */
    public function __construct(string|array $options = [])
    {
        if (is_string($options)) {
            $options = ['api_key' => $options];
        }
        $this->http = new HttpClient($options);
        $this->emails = new Emails($this->http);
        $this->domains = new Domains($this->http);
        $this->webhooks = new Webhooks($this->http);
        $this->suppressions = new Suppressions($this->http);
        $this->events = new Events($this->http);
        $this->templates = new Templates($this->http);
        $this->contacts = new Contacts($this->http);
        $this->campaigns = new Campaigns($this->http);
    }
}
