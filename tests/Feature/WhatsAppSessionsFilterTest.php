<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Webkul\WhatsApp\Models\Session;

uses(DatabaseTransactions::class);

/**
 * WAHA_SESSIONS: one WAHA server, several CRM installations. Each one sees, controls and
 * receives only its own sessions. All data is fake.
 */
const ZZ_WAHA_SECRET = 'zz-test-hmac-secret-0123456789';

beforeEach(function () {
    config([
        'whatsapp.waha.url'         => 'https://waha.test',
        'whatsapp.waha.api_key'     => 'zz-key',
        'whatsapp.waha.hmac_secret' => ZZ_WAHA_SECRET,
        'whatsapp.waha.sessions'    => '',
        'whatsapp.auto_lead.mode'   => 'off',
    ]);

    Http::preventStrayRequests();
});

function zzSignedWebhook(array $envelope)
{
    $body = json_encode($envelope);

    return test()->call('POST', route('admin.whatsapp.waha.webhook'), [], [], [], [
        'CONTENT_TYPE'        => 'application/json',
        'HTTP_ACCEPT'         => 'application/json',
        'HTTP_X_WEBHOOK_HMAC' => hash_hmac('sha512', $body, ZZ_WAHA_SECRET),
    ], $body);
}

function zzIncoming(string $session, string $phone, string $id, array $message = []): array
{
    return [
        'event'   => 'message.any',
        'session' => $session,
        'payload' => [
            'id' => 'false_'.$phone.'@c.us_'.$id, 'timestamp' => time(), 'from' => $phone.'@c.us', 'fromMe' => false,
            'source' => 'app', 'body' => 'Oi, vim pelo anúncio', 'hasMedia' => false,
            '_data' => [
                'Info'    => ['Chat' => $phone.'@s.whatsapp.net', 'IsGroup' => false, 'ID' => $id, 'PushName' => 'ZZ Cliente', 'MediaType' => ''],
                'Message' => $message ?: ['conversation' => 'Oi, vim pelo anúncio'],
            ],
        ],
    ];
}

function zzWahaSession(string $name, string $status = 'WORKING'): int
{
    return DB::table('whatsapp_sessions')->insertGetId(['name' => $name, 'channel' => 'waha', 'external_id' => $name, 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
}

it('accepts webhooks of every session when WAHA_SESSIONS is empty', function () {
    zzSignedWebhook(['event' => 'session.status', 'session' => 'zz-any', 'payload' => ['status' => 'WORKING']])->assertOk()->assertJsonMissing(['ignored' => true]);

    expect(Session::query()->where('channel', 'waha')->where('external_id', 'zz-any')->exists())->toBeTrue();
});

it('ignores, with a 200, the webhooks of a session that belongs to another installation', function () {
    config(['whatsapp.waha.sessions' => 'zz-crm, zz-assertiva']);

    $phone = '55119'.random_int(10000000, 99999999);

    zzSignedWebhook(['event' => 'session.status', 'session' => 'zz-agencia', 'payload' => ['status' => 'WORKING']])
        ->assertOk()->assertJson(['received' => true, 'ignored' => true]);

    zzSignedWebhook(zzIncoming('zz-agencia', $phone, '3EB0ZZ01'))->assertOk()->assertJson(['ignored' => true]);

    expect(Session::query()->where('external_id', 'zz-agencia')->exists())->toBeFalse()
        ->and(DB::table('whatsapp_conversations')->where('contact_phone', $phone)->exists())->toBeFalse();

    // Its own sessions keep working.
    zzSignedWebhook(zzIncoming('zz-assertiva', $phone, '3EB0ZZ02'))->assertOk()->assertJsonMissing(['ignored' => true]);

    expect(DB::table('whatsapp_conversations')->where('contact_phone', $phone)->count())->toBe(1);
});

it('still rejects an unsigned webhook before looking at the session', function () {
    config(['whatsapp.waha.sessions' => 'zz-crm']);

    test()->postJson(route('admin.whatsapp.waha.webhook'), ['event' => 'session.status', 'session' => 'zz-agencia', 'payload' => ['status' => 'WORKING']])
        ->assertUnauthorized();
});

it('lists and controls only the allowed sessions on the Connections screen', function () {
    config(['whatsapp.waha.sessions' => 'zz-crm']);

    $foreign = zzWahaSession('zz-agencia');

    Http::fake([
        'waha.test/api/sessions?*' => Http::response([
            ['name' => 'zz-crm', 'status' => 'WORKING', 'me' => ['id' => '5511900000001@c.us']],
            ['name' => 'zz-assertiva', 'status' => 'WORKING'],
        ]),
        'waha.test/*' => Http::response(['ok' => true]),
    ]);

    $admin = getDefaultAdmin();

    $names = collect(test()->actingAs($admin, 'user')->getJson(route('admin.whatsapp.connections.list'))->assertOk()->json('data'))->pluck('name');

    expect($names->all())->toBe(['zz-crm'])
        ->and(Session::query()->where('external_id', 'zz-assertiva')->exists())->toBeFalse();

    $own = Session::query()->where('external_id', 'zz-crm')->value('id');

    test()->postJson(route('admin.whatsapp.connections.action', ['id' => $own, 'action' => 'restart']))->assertOk();
    test()->postJson(route('admin.whatsapp.connections.action', ['id' => $foreign, 'action' => 'logout']))->assertNotFound();
    test()->getJson(route('admin.whatsapp.connections.qr', ['id' => $foreign]))->assertNotFound();
    test()->postJson(route('admin.whatsapp.connections.code', ['id' => $foreign]), ['phone' => '5511999999999'])->assertNotFound();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'zz-agencia'));
});

it('shows every session on the Connections screen when the list is empty', function () {
    Http::fake([
        'waha.test/api/sessions?*' => Http::response([['name' => 'zz-crm', 'status' => 'WORKING'], ['name' => 'zz-assertiva', 'status' => 'STOPPED']]),
    ]);

    $names = collect(test()->actingAs(getDefaultAdmin(), 'user')->getJson(route('admin.whatsapp.connections.list'))->assertOk()->json('data'))->pluck('name');

    expect($names->all())->toContain('zz-crm', 'zz-assertiva');
});

it('opens a new chat from a lead only on an allowed session', function () {
    config(['whatsapp.waha.sessions' => 'zz-mine', 'whatsapp.auto_lead.mode' => 'all']);

    // Another installation's session, created earlier and WORKING: must not be chosen.
    zzWahaSession('zz-foreign');
    $mine = zzWahaSession('zz-mine', 'STOPPED');

    $phone = '55119'.random_int(10000000, 99999999);

    if (! DB::table('lead_types')->exists()) {
        DB::table('lead_types')->insert(['name' => 'ZZ tipo', 'created_at' => now(), 'updated_at' => now()]);
    }

    // The automatic lead gives us a lead with that phone; then the chat is removed.
    zzSignedWebhook(zzIncoming('zz-mine', $phone, '3EB0ZZ10'))->assertOk();

    $conversation = DB::table('whatsapp_conversations')->where('contact_phone', $phone)->first();
    expect($conversation->lead_id)->not->toBeNull();

    DB::table('whatsapp_messages')->where('conversation_id', $conversation->id)->delete();
    DB::table('whatsapp_conversations')->where('id', $conversation->id)->delete();

    test()->actingAs(getDefaultAdmin(), 'user')->get(route('admin.whatsapp.inbox.open-lead', ['leadId' => $conversation->lead_id]))->assertRedirect();

    expect(DB::table('whatsapp_conversations')->where('lead_id', $conversation->lead_id)->value('session_id'))->toBe($mine);
});
