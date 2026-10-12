# couryo/couryo-php

SDK oficial em PHP da API de e-mail do [Couryo](https://couryo.com). [Read in English](./README.md).

- `Idempotency-Key` automático em todo POST, repetido nas novas tentativas: um envio que deu tempo esgotado mas passou nunca sai duplicado.
- Novas tentativas com espera crescente para erro de rede, `5xx` e `rate_limited` (respeita o `Retry-After`). Erros que falhariam de novo (`domain_not_verified`, `daily_limit_reached`...) voltam na hora.
- Exceções tipadas com o `code` do erro da API.
- Verificação de webhook (Standard Webhooks) numa chamada.
- Transporte de e-mail para Laravel (`MAIL_MAILER=couryo`), que também funciona com o Symfony Mailer.
- PHP 8.1 ou mais novo com `ext-curl` e `ext-json`. Sem dependências em produção; qualquer cliente PSR-18 pode substituir o curl.

> Ainda não está no Packagist. Dentro do monorepo do Couryo, fica em `packages/sdk-php`; até a publicação, instale por um repositório do tipo path (veja abaixo).

## Instalar

```bash
composer require couryo/couryo-php
```

Antes de sair no Packagist, aponte o Composer para a pasta:

```json
{
    "repositories": [{ "type": "path", "url": "../couryo/packages/sdk-php" }],
    "require": { "couryo/couryo-php": "*@dev" }
}
```

## Enviar um e-mail

```php
use Couryo\Couryo;

$couryo = new Couryo(getenv('COURYO_API_KEY')); // ck_live_... ou ck_test_...

$resultado = $couryo->emails->send([
    'from' => 'Loja <pedidos@exemplo.com.br>',
    'to' => 'ana@exemplo.com.br',
    'subject' => 'Pedido 1042 confirmado',
    'html' => '<p>Obrigado!</p>',
    'text' => 'Obrigado!',
    'tags' => ['tipo' => 'pedido'],
]);

echo $resultado['id'], ' ', $resultado['status']; // msg_... queued
```

As respostas são arrays associativos com os mesmos campos do JSON da API.

Passe a sua própria chave quando o mesmo evento do seu sistema puder disparar duas vezes: `$couryo->emails->send($corpo, ['idempotency_key' => 'pedido-1042-confirmacao'])`. Todo método que faz POST aceita esse array de opções.

## Templates salvos

```php
$couryo->templates->create(['name' => 'boas-vindas', 'subject' => 'Bem-vinda, {{nome}}!', 'html' => '<h1>Olá, {{nome}}</h1>']);
$couryo->emails->send(['from' => 'Loja <oi@exemplo.com.br>', 'to' => 'ana@exemplo.com.br', 'template' => 'boas-vindas', 'variables' => ['nome' => 'Ana']]);
```

## Contatos, eventos e campanhas

```php
// Cria o contato ou junta os atributos ao que já existe (mesmo e-mail).
$couryo->contacts->create(['email' => 'ana@exemplo.com.br', 'attributes' => ['plano' => 'pro'], 'tags' => ['cliente']]);

// Evento de contato: encerra conversões e inscreve o contato nas sequências ativas com esse gatilho.
$couryo->events->track(
    ['contact' => ['email' => 'ana@exemplo.com.br'], 'name' => 'pedido.pago', 'properties' => ['total' => 199.9]],
    ['idempotency_key' => 'pedido-1042-pago'],
);

// Campanha para um segmento: confira e depois dispare ou agende.
$campanha = $couryo->campaigns->create([
    'name' => 'Novidades de outubro',
    'audience' => ['type' => 'segment', 'match' => 'all', 'conditions' => [['field' => 'tag', 'op' => 'eq', 'value' => 'cliente']]],
    'from' => 'Loja <novidades@exemplo.com.br>',
    'subject' => 'O que chegou em outubro',
    'html' => '<p>Olá!</p>',
]);
$checagem = $couryo->campaigns->preflight($campanha['id']);
if ($checagem['can_send']) {
    $couryo->campaigns->schedule($campanha['id'], new DateTimeImmutable('2026-10-20 09:00', new DateTimeZone('America/Sao_Paulo')));
}
```

## Erros

```php
use Couryo\Exception\CouryoException;

try {
    $couryo->emails->send($corpo);
} catch (CouryoException $e) {
    echo $e->getStatus(), ' ', $e->getErrorCode(), ' ', $e->getMessage(), ' ', $e->getParam(), ' ', $e->getRequestId();
}
```

O PHP reserva `Exception::$code` para um inteiro, então o código da API (`domain_not_verified`, `rate_limited`...) fica em `getErrorCode()` e na propriedade `$e->errorCode`, e `getCode()` devolve o status HTTP (`0` para erro de rede). Também existem `getType()`, `getDocUrl()`, `getRetryAfter()` e `getError()` (o objeto `error` inteiro).

Cada `type` tem a sua classe, todas filhas de `CouryoException`: `InvalidRequestError`, `AuthenticationError`, `PermissionError`, `NotFoundError`, `ConflictError`, `RateLimitError`, `TierLimitError`, `ApiError` e `NetworkError` (falha de rede ou tempo esgotado, status `0`).

A `message` segue a opção `language` (`pt-BR` por padrão, ou `en`).

## Webhooks

Use o corpo cru, exatamente como chegou, nunca um JSON decodificado e codificado de novo.

PHP puro:

```php
use Couryo\Exception\WebhookVerificationError;
use Couryo\Webhook;

try {
    $evento = Webhook::verify(getenv('COURYO_WEBHOOK_SECRET'), $_SERVER, file_get_contents('php://input'));
} catch (WebhookVerificationError $e) {
    http_response_code(400);
    exit;
}

if ($evento['type'] === 'bounced') {
    // $evento['data']['email_id'], $evento['data']['recipient'], $evento['data']['explanation']...
}
http_response_code(200);
```

Laravel (numa rota sem proteção CSRF, como `routes/api.php`):

```php
use Couryo\Exception\WebhookVerificationError;
use Couryo\Webhook;
use Illuminate\Http\Request;

Route::post('/webhooks/couryo', function (Request $request) {
    try {
        $evento = Webhook::verify(config('couryo.webhook_secret'), $request->headers->all(), $request->getContent());
    } catch (WebhookVerificationError $e) {
        abort(400);
    }

    if ($evento['type'] === 'bounced') {
        // ...
    }

    return response()->noContent();
});
```

`Webhook::verify` aceita qualquer array de cabeçalhos (`getallheaders()`, `$_SERVER`, `$request->headers->all()`), procura sem diferenciar maiúsculas e lança `WebhookVerificationError` quando a assinatura não bate ou o horário tem mais de 5 minutos (`$toleranceSeconds`, o quarto argumento). `Webhook::sign()` gera uma assinatura válida para os seus testes.

## Laravel

O service provider é descoberto sozinho. Ele lê `COURYO_API_KEY` (ou `config('services.couryo.key')`), registra `Couryo\Couryo` como singleton (também `app('couryo')`) e cria o transporte de e-mail `couryo`.

`.env`:

```dotenv
MAIL_MAILER=couryo
COURYO_API_KEY=ck_live_...
COURYO_WEBHOOK_SECRET=whsec_...
MAIL_FROM_ADDRESS=pedidos@exemplo.com.br
MAIL_FROM_NAME="Loja"
```

`config/mail.php`, dentro de `mailers`:

```php
'couryo' => [
    'transport' => 'couryo',
],
```

Depois envie como sempre:

```php
use Illuminate\Support\Facades\Mail;

$enviado = Mail::to($user)->send(new OrderShipped($order));
$enviado?->getMessageId(); // msg_... (também no cabeçalho X-Couryo-Email-ID da mensagem)
```

Remetente, destinatários, cc, bcc, reply-to, assunto, HTML, texto, anexos e imagens embutidas vão para a API. As tags (`tags: [...]` no envelope do Mailable ou `->tag()`) viram `tags` do Couryo (assim como um cabeçalho `X-Couryo-Tags: tipo=pedido, loja=centro`), `metadata` vira `metadata`, os outros cabeçalhos `X-*` vão em `headers`, e um cabeçalho `X-Couryo-Stream: marketing` escolhe o fluxo. `php artisan vendor:publish --tag=couryo-config` copia o `config/couryo.php` para o seu app.

O transporte é um transporte comum do Symfony Mailer, então também funciona fora do Laravel: `new Symfony\Component\Mailer\Mailer(new Couryo\Laravel\CouryoTransport($couryo))`.

### Ou SMTP puro

Sem pacote nenhum, com o mailer `smtp` do Laravel:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp.couryo.com
MAIL_PORT=587
MAIL_USERNAME=couryo
MAIL_PASSWORD=ck_live_...
MAIL_ENCRYPTION=tls
```

As portas 587 e 2525 usam STARTTLS, a 465 usa TLS. A senha é uma chave de API com escopo `send` ou `admin`.

## Cliente PSR-18 no lugar do curl

```php
use Couryo\Couryo;
use Couryo\Http\Psr18Transport;

$factory = new GuzzleHttp\Psr7\HttpFactory(); // qualquer fábrica PSR-17 de request e stream
$couryo = new Couryo([
    'api_key' => getenv('COURYO_API_KEY'),
    'transport' => new Psr18Transport(new GuzzleHttp\Client(['timeout' => 30]), $factory, $factory),
]);
```

Precisa de `psr/http-client` e `psr/http-factory`. O PSR-18 não tem tempo limite por requisição, então configure no seu cliente. Você também pode escrever o seu próprio `Couryo\Http\Transport` (um método `send()`).

## Todo o resto

| Recurso | Métodos |
|---|---|
| `emails` | `send`, `batch` (até 100), `check`, `get`, `list`, `iterate` |
| `domains` | `list`, `create`, `get`, `delete`, `verify`, `health` (diagnóstico de entregabilidade) |
| `webhooks` | `list`, `create`, `get`, `update`, `delete` |
| `suppressions` | `list`, `add`, `remove` |
| `events` | `list`, `iterate` (eventos de entrega), `track` (evento de contato) |
| `templates` | `list`, `create`, `get`, `update`, `delete`, `versions`, `preview` |
| `contacts` | `create` (upsert), `list`, `get`, `update`, `delete`, `iterate`, `count` |
| `campaigns` | `create`, `list`, `get`, `update`, `delete`, `preflight`, `send`, `schedule`, `pause`, `resume`, `cancel`, `test`, `stats` |

`iterate` percorre todas as páginas: `foreach ($couryo->emails->iterate(['status' => 'bounced']) as $email) { ... }`.

Opções: `api_key`, `base_url`, `max_retries` (2), `timeout` (30 s por tentativa), `language`, `transport`, `sleep`.

Docs: https://couryo.com/docs
