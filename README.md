# couryo/couryo-php

Official PHP SDK for the [Couryo](https://couryo.com) email API. [Leia em português](./README.pt-BR.md).

- Automatic `Idempotency-Key` on every POST, reused on retries: a send that timed out but went through is never duplicated.
- Retries with backoff for network errors, `5xx` and `rate_limited` (honors `Retry-After`). Errors that would fail again (`domain_not_verified`, `daily_limit_reached`...) are thrown right away.
- Typed exceptions with the API error `code`.
- Webhook verification (Standard Webhooks) in one call.
- Laravel mail transport (`MAIL_MAILER=couryo`), also usable with Symfony Mailer.
- PHP 8.1 or newer with `ext-curl` and `ext-json`. No runtime dependencies; any PSR-18 client can replace curl.

> Not on Packagist yet. Inside the Couryo monorepo it lives in `packages/sdk-php`; until it is published, install it from a path repository (see below).

## Install

```bash
composer require couryo/couryo-php
```

Before it is on Packagist, point Composer to the folder:

```json
{
    "repositories": [{ "type": "path", "url": "../couryo/packages/sdk-php" }],
    "require": { "couryo/couryo-php": "*@dev" }
}
```

## Send an email

```php
use Couryo\Couryo;

$couryo = new Couryo(getenv('COURYO_API_KEY')); // ck_live_... or ck_test_...

$result = $couryo->emails->send([
    'from' => 'Shop <orders@example.com>',
    'to' => 'ana@example.com',
    'subject' => 'Order 1042 confirmed',
    'html' => '<p>Thanks!</p>',
    'text' => 'Thanks!',
    'tags' => ['kind' => 'order'],
]);

echo $result['id'], ' ', $result['status']; // msg_... queued
```

Responses are associative arrays with the same fields as the API JSON.

Pass your own key when the same business event may be sent twice: `$couryo->emails->send($body, ['idempotency_key' => 'order-1042-confirmation'])`. Every POST method accepts this options array.

## Saved templates

```php
$couryo->templates->create(['name' => 'welcome', 'subject' => 'Welcome, {{name}}!', 'html' => '<h1>Hi, {{name}}</h1>']);
$couryo->emails->send(['from' => 'Shop <hi@example.com>', 'to' => 'ana@example.com', 'template' => 'welcome', 'variables' => ['name' => 'Ana']]);
```

## Contacts, events and campaigns

```php
// Creates the contact or merges the attributes of the existing one (same email).
$couryo->contacts->create(['email' => 'ana@example.com', 'attributes' => ['plan' => 'pro'], 'tags' => ['customer']]);

// A contact event: ends conversions and enrolls the contact in the active sequences with this trigger.
$couryo->events->track(
    ['contact' => ['email' => 'ana@example.com'], 'name' => 'order.paid', 'properties' => ['total' => 199.9]],
    ['idempotency_key' => 'order-1042-paid'],
);

// A campaign for a segment: check it, then send or schedule it.
$campaign = $couryo->campaigns->create([
    'name' => 'October news',
    'audience' => ['type' => 'segment', 'match' => 'all', 'conditions' => [['field' => 'tag', 'op' => 'eq', 'value' => 'customer']]],
    'from' => 'Shop <news@example.com>',
    'subject' => 'What is new in October',
    'html' => '<p>Hi!</p>',
]);
$check = $couryo->campaigns->preflight($campaign['id']);
if ($check['can_send']) {
    $couryo->campaigns->schedule($campaign['id'], new DateTimeImmutable('2026-10-20 09:00', new DateTimeZone('America/New_York')));
}
```

## Errors

```php
use Couryo\Exception\CouryoException;

try {
    $couryo->emails->send($body);
} catch (CouryoException $e) {
    echo $e->getStatus(), ' ', $e->getErrorCode(), ' ', $e->getMessage(), ' ', $e->getParam(), ' ', $e->getRequestId();
}
```

PHP reserves `Exception::$code` for an integer, so the API code (`domain_not_verified`, `rate_limited`...) is in `getErrorCode()` and in the `$e->errorCode` property, and `getCode()` returns the HTTP status (`0` for network errors). Also available: `getType()`, `getDocUrl()`, `getRetryAfter()` and `getError()` (the whole `error` object).

Each `type` has its own class, all extending `CouryoException`: `InvalidRequestError`, `AuthenticationError`, `PermissionError`, `NotFoundError`, `ConflictError`, `RateLimitError`, `TierLimitError`, `ApiError` and `NetworkError` (network failure or timeout, status `0`).

`message` follows the `language` option (`pt-BR` by default, or `en`).

## Webhooks

Use the raw body exactly as it arrived, never JSON that was decoded and encoded again.

Plain PHP:

```php
use Couryo\Exception\WebhookVerificationError;
use Couryo\Webhook;

try {
    $event = Webhook::verify(getenv('COURYO_WEBHOOK_SECRET'), $_SERVER, file_get_contents('php://input'));
} catch (WebhookVerificationError $e) {
    http_response_code(400);
    exit;
}

if ($event['type'] === 'bounced') {
    // $event['data']['email_id'], $event['data']['recipient'], $event['data']['explanation']...
}
http_response_code(200);
```

Laravel (a route without CSRF protection, such as `routes/api.php`):

```php
use Couryo\Exception\WebhookVerificationError;
use Couryo\Webhook;
use Illuminate\Http\Request;

Route::post('/webhooks/couryo', function (Request $request) {
    try {
        $event = Webhook::verify(config('couryo.webhook_secret'), $request->headers->all(), $request->getContent());
    } catch (WebhookVerificationError $e) {
        abort(400);
    }

    if ($event['type'] === 'bounced') {
        // ...
    }

    return response()->noContent();
});
```

`Webhook::verify` accepts any header array (`getallheaders()`, `$_SERVER`, `$request->headers->all()`), looks them up without case sensitivity and throws `WebhookVerificationError` for a wrong signature or a timestamp older than 5 minutes (`$toleranceSeconds`, the fourth argument). `Webhook::sign()` builds a valid signature for your tests.

## Laravel

The service provider is discovered automatically. It reads `COURYO_API_KEY` (or `config('services.couryo.key')`), binds `Couryo\Couryo` as a singleton (also `app('couryo')`) and registers the `couryo` mail transport.

`.env`:

```dotenv
MAIL_MAILER=couryo
COURYO_API_KEY=ck_live_...
COURYO_WEBHOOK_SECRET=whsec_...
MAIL_FROM_ADDRESS=orders@example.com
MAIL_FROM_NAME="Shop"
```

`config/mail.php`, inside `mailers`:

```php
'couryo' => [
    'transport' => 'couryo',
],
```

Then send as usual:

```php
use Illuminate\Support\Facades\Mail;

$sent = Mail::to($user)->send(new OrderShipped($order));
$sent?->getMessageId(); // msg_... (also in the X-Couryo-Email-ID header of the message)
```

From, to, cc, bcc, reply-to, subject, HTML, text, attachments and inline images go to the API. Tags (`tags: [...]` in the Mailable envelope or `->tag()`) become Couryo `tags` (as does an `X-Couryo-Tags: kind=order, store=downtown` header), `metadata` becomes `metadata`, other `X-*` headers go in `headers`, and an `X-Couryo-Stream: marketing` header picks the stream. `php artisan vendor:publish --tag=couryo-config` copies `config/couryo.php` to your app.

The transport is a regular Symfony Mailer transport, so it also works outside Laravel: `new Symfony\Component\Mailer\Mailer(new Couryo\Laravel\CouryoTransport($couryo))`.

### Or plain SMTP

No package needed, with Laravel's `smtp` mailer:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp.couryo.com
MAIL_PORT=587
MAIL_USERNAME=couryo
MAIL_PASSWORD=ck_live_...
MAIL_ENCRYPTION=tls
```

Ports 587 and 2525 use STARTTLS, 465 uses TLS. The password is an API key with the `send` or `admin` scope.

## PSR-18 client instead of curl

```php
use Couryo\Couryo;
use Couryo\Http\Psr18Transport;

$factory = new GuzzleHttp\Psr7\HttpFactory(); // any PSR-17 request and stream factory
$couryo = new Couryo([
    'api_key' => getenv('COURYO_API_KEY'),
    'transport' => new Psr18Transport(new GuzzleHttp\Client(['timeout' => 30]), $factory, $factory),
]);
```

Needs `psr/http-client` and `psr/http-factory`. PSR-18 has no per-request timeout, so set it on your client. You can also write your own `Couryo\Http\Transport` (one `send()` method).

## Everything else

| Resource | Methods |
|---|---|
| `emails` | `send`, `batch` (up to 100), `check`, `get`, `list`, `iterate` |
| `domains` | `list`, `create`, `get`, `delete`, `verify`, `health` (deliverability diagnosis) |
| `webhooks` | `list`, `create`, `get`, `update`, `delete` |
| `suppressions` | `list`, `add`, `remove` |
| `events` | `list`, `iterate` (delivery events), `track` (contact event) |
| `templates` | `list`, `create`, `get`, `update`, `delete`, `versions`, `preview` |
| `contacts` | `create` (upsert), `list`, `get`, `update`, `delete`, `iterate`, `count` |
| `campaigns` | `create`, `list`, `get`, `update`, `delete`, `preflight`, `send`, `schedule`, `pause`, `resume`, `cancel`, `test`, `stats` |

`iterate` walks every page: `foreach ($couryo->emails->iterate(['status' => 'bounced']) as $email) { ... }`.

Options: `api_key`, `base_url`, `max_retries` (2), `timeout` (30 s per attempt), `language`, `transport`, `sleep`.

Docs: https://couryo.com/en/docs
