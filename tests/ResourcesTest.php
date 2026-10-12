<?php

declare(strict_types=1);

namespace Couryo\Tests;

use Couryo\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class ResourcesTest extends TestCase
{
    public function testDomainsWebhooksSuppressionsEventsTemplatesAndBatch(): void
    {
        [$couryo, $fake] = FakeTransport::client([FakeTransport::json(200, ['ok' => true])]);

        $couryo->domains->create('loja.com.br');
        $couryo->domains->health('dom_1', true);
        $couryo->domains->health('dom_1');
        $couryo->domains->verify('dom_1');
        $couryo->webhooks->update('whk_1', ['enabled' => false]);
        $couryo->suppressions->remove('a+b@gmail.com');
        $couryo->suppressions->add(['email' => 'x@y.com']);
        $couryo->events->list(['type' => 'bounced', 'limit' => 10]);
        $couryo->templates->preview('boas-vindas', ['variables' => ['nome' => 'Ana']]);
        $couryo->templates->preview('boas vindas');
        $couryo->emails->batch([['from' => 'a@b.com', 'to' => 'c@d.com', 'subject' => 'x', 'text' => 'y', 'tags' => []]]);
        $couryo->emails->check(['from' => 'a@b.com', 'to' => 'c@d.com', 'subject' => 'x', 'html' => '<p>y</p>']);

        $this->assertSame([
            'POST /domains',
            'GET /domains/dom_1/health?refresh=1',
            'GET /domains/dom_1/health',
            'POST /domains/dom_1/verify',
            'PATCH /webhooks/whk_1',
            'DELETE /suppressions/a%2Bb%40gmail.com',
            'POST /suppressions',
            'GET /events?type=bounced&limit=10',
            'POST /templates/boas-vindas/preview',
            'POST /templates/boas%20vindas/preview',
            'POST /emails/batch',
            'POST /emails/check',
        ], $fake->routes());
        $this->assertSame(['name' => 'loja.com.br'], $fake->body(0));
        $this->assertNull($fake->calls[3]['body']);
        $this->assertArrayHasKey('Idempotency-Key', $fake->calls[3]['headers']);
        $this->assertSame('{"enabled":false}', $fake->calls[4]['body']);
        $this->assertArrayNotHasKey('Idempotency-Key', $fake->calls[4]['headers']);
        $this->assertSame(['stream' => 'all', 'email' => 'x@y.com'], $fake->body(6));
        $this->assertSame(['variables' => ['nome' => 'Ana']], $fake->body(8));
        $this->assertSame('{"variables":{}}', $fake->calls[9]['body']);
        $this->assertSame('{"emails":[{"from":"a@b.com","to":"c@d.com","subject":"x","text":"y","tags":{}}]}', $fake->calls[10]['body']);
    }

    public function testContacts(): void
    {
        [$couryo, $fake] = FakeTransport::client([FakeTransport::json(201, ['id' => 'con_1'])]);

        $couryo->contacts->create(['email' => 'ana@exemplo.com.br', 'attributes' => ['plano' => 'pro'], 'tags' => ['vip']]);
        $couryo->contacts->list(['q' => 'ana', 'limit' => 50, 'starting_after' => null]);
        $couryo->contacts->get('ana@exemplo.com.br');
        $couryo->contacts->update('con_1', ['tags' => []]);
        $couryo->contacts->delete('con_1');
        $couryo->contacts->count(['type' => 'segment', 'match' => 'all', 'conditions' => [['field' => 'tag', 'op' => 'eq', 'value' => 'vip']]]);
        $couryo->contacts->count();

        $this->assertSame([
            'POST /contacts',
            'GET /contacts?q=ana&limit=50',
            'GET /contacts/ana%40exemplo.com.br',
            'PATCH /contacts/con_1',
            'DELETE /contacts/con_1',
            'POST /contacts/count',
            'POST /contacts/count',
        ], $fake->routes());
        $this->assertSame(['email' => 'ana@exemplo.com.br', 'attributes' => ['plano' => 'pro'], 'tags' => ['vip']], $fake->body(0));
        $this->assertSame('{"tags":[]}', $fake->calls[3]['body']);
        $this->assertSame(['audience' => ['type' => 'segment', 'match' => 'all', 'conditions' => [['field' => 'tag', 'op' => 'eq', 'value' => 'vip']]]], $fake->body(5));
        $this->assertSame('{"audience":{"type":"all"}}', $fake->calls[6]['body']);
    }

    public function testContactsIterate(): void
    {
        [$couryo, $fake] = FakeTransport::client([
            FakeTransport::json(200, ['data' => [['id' => 'con_2']], 'has_more' => true]),
            FakeTransport::json(200, ['data' => [['id' => 'con_1']], 'has_more' => false]),
        ]);

        $ids = array_map(static fn (array $c): string => $c['id'], iterator_to_array($couryo->contacts->iterate(['q' => 'gmail'])));

        $this->assertSame(['con_2', 'con_1'], $ids);
        $this->assertSame(['GET /contacts?limit=100&q=gmail', 'GET /contacts?limit=100&q=gmail&starting_after=con_2'], $fake->routes());
    }

    public function testEventsTrack(): void
    {
        [$couryo, $fake] = FakeTransport::client([FakeTransport::json(201, ['id' => 'cev_1', 'contact_id' => 'con_1', 'name' => 'order.paid'])]);

        $result = $couryo->events->track([
            'contact' => ['email' => 'ana@exemplo.com.br', 'attributes' => []],
            'name' => 'order.paid',
            'properties' => ['total' => 199.9],
        ], ['idempotency_key' => 'pedido-1042-pago']);

        $this->assertSame('cev_1', $result['id']);
        $this->assertSame(['POST /events'], $fake->routes());
        $this->assertSame('pedido-1042-pago', $fake->calls[0]['headers']['Idempotency-Key']);
        $this->assertSame('{"contact":{"email":"ana@exemplo.com.br","attributes":{}},"name":"order.paid","properties":{"total":199.9}}', $fake->calls[0]['body']);
    }

    public function testCampaigns(): void
    {
        [$couryo, $fake] = FakeTransport::client([FakeTransport::json(200, ['id' => 'cmp_1'])]);

        $couryo->campaigns->create(['name' => 'Novidades', 'audience' => ['type' => 'all'], 'subject' => 'Oi', 'html' => '<p>Oi</p>']);
        $couryo->campaigns->list(['status' => 'draft']);
        $couryo->campaigns->get('cmp_1');
        $couryo->campaigns->update('cmp_1', ['subject' => 'Olá']);
        $couryo->campaigns->delete('cmp_1');
        $couryo->campaigns->preflight('cmp_1');
        $couryo->campaigns->send('cmp_1', ['idempotency_key' => 'disparo-1']);
        $couryo->campaigns->schedule('cmp_1', new \DateTimeImmutable('2026-10-20 09:00:00', new \DateTimeZone('America/Sao_Paulo')));
        $couryo->campaigns->schedule('cmp_1', '2026-10-20T12:00:00Z');
        $couryo->campaigns->pause('cmp_1');
        $couryo->campaigns->resume('cmp_1');
        $couryo->campaigns->cancel('cmp_1');
        $couryo->campaigns->test('cmp_1');
        $couryo->campaigns->test('cmp_1', ['eu@loja.com.br']);
        $couryo->campaigns->test('cmp_1', 'outro@loja.com.br');
        $couryo->campaigns->stats('cmp_1');

        $this->assertSame([
            'POST /campaigns',
            'GET /campaigns?status=draft',
            'GET /campaigns/cmp_1',
            'PATCH /campaigns/cmp_1',
            'DELETE /campaigns/cmp_1',
            'POST /campaigns/cmp_1/preflight',
            'POST /campaigns/cmp_1/send',
            'POST /campaigns/cmp_1/schedule',
            'POST /campaigns/cmp_1/schedule',
            'POST /campaigns/cmp_1/pause',
            'POST /campaigns/cmp_1/resume',
            'POST /campaigns/cmp_1/cancel',
            'POST /campaigns/cmp_1/test',
            'POST /campaigns/cmp_1/test',
            'POST /campaigns/cmp_1/test',
            'GET /campaigns/cmp_1/stats',
        ], $fake->routes());
        $this->assertSame('disparo-1', $fake->calls[6]['headers']['Idempotency-Key']);
        $this->assertSame(['scheduled_at' => '2026-10-20T09:00:00-03:00'], $fake->body(7));
        $this->assertSame(['scheduled_at' => '2026-10-20T12:00:00Z'], $fake->body(8));
        $this->assertSame('{}', $fake->calls[12]['body']);
        $this->assertSame(['to' => ['eu@loja.com.br']], $fake->body(13));
        $this->assertSame(['to' => ['outro@loja.com.br']], $fake->body(14));
        foreach ([5, 9, 10, 11] as $i) {
            $this->assertNull($fake->calls[$i]['body']);
            $this->assertArrayHasKey('Idempotency-Key', $fake->calls[$i]['headers']);
        }
    }

    public function testTemplatesAndWebhooks(): void
    {
        [$couryo, $fake] = FakeTransport::client([FakeTransport::json(200, ['id' => 'x'])]);

        $couryo->templates->list();
        $couryo->templates->create(['name' => 'boas-vindas', 'subject' => 'Oi, {{nome}}', 'html' => '<h1>Oi</h1>']);
        $couryo->templates->get('boas-vindas');
        $couryo->templates->update('tpl_1', ['subject' => 'Olá']);
        $couryo->templates->delete('tpl_1');
        $couryo->templates->versions('tpl_1');
        $couryo->webhooks->list();
        $couryo->webhooks->create(['url' => 'https://loja.com.br/webhooks/couryo', 'events' => ['bounced', 'complained']]);
        $couryo->webhooks->get('whk_1');
        $couryo->webhooks->delete('whk_1');
        $couryo->suppressions->list(['q' => 'gmail', 'reason' => 'bounce']);
        $couryo->emails->get('msg_1');
        $couryo->domains->get('dom_1');

        $this->assertSame([
            'GET /templates',
            'POST /templates',
            'GET /templates/boas-vindas',
            'PATCH /templates/tpl_1',
            'DELETE /templates/tpl_1',
            'GET /templates/tpl_1/versions',
            'GET /webhooks',
            'POST /webhooks',
            'GET /webhooks/whk_1',
            'DELETE /webhooks/whk_1',
            'GET /suppressions?q=gmail&reason=bounce',
            'GET /emails/msg_1',
            'GET /domains/dom_1',
        ], $fake->routes());
        $this->assertSame('{"url":"https://loja.com.br/webhooks/couryo","events":["bounced","complained"]}', $fake->calls[7]['body']);
    }
}
