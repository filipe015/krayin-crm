<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Webkul\User\Models\User;
use Webkul\WhatsApp\Models\Message;
use Webkul\WhatsApp\Models\Session;

uses(DatabaseTransactions::class);

/**
 * Official WhatsApp through Meta's Embedded Signup: connecting the customer's own number,
 * disconnecting it, message templates and the webhook (coexistence events included).
 * Nothing here talks to Meta: every Graph API call is faked.
 */
const ES_PHONE_ID = '990000000000001';
const ES_WABA_ID = '880000000000001';
const ES_TOKEN = 'test-access-token-0123456789abcdef';
const ES_SECRET = 'test-app-secret-0123456789';

beforeEach(function () {
    config([
        'whatsapp.cloud.app_id'           => '1234567890',
        'whatsapp.cloud.es_config_id'     => '5555555555',
        'whatsapp.cloud.app_secret'       => ES_SECRET,
        'whatsapp.cloud.verify_token'     => 'verify-token-0123456789',
        'whatsapp.cloud.graph_version'    => 'v23.0',
        'whatsapp.cloud.webhook_override' => true,
    ]);

    // Meta only takes an https callback, as every real installation has.
    URL::forceRootUrl('https://crm.example.test');
    URL::forceScheme('https');
});

function esFakeGraph(array $overrides = []): void
{
    Http::fake($overrides + [
        'graph.facebook.com/v23.0/oauth/access_token*'              => Http::response(['access_token' => ES_TOKEN, 'token_type' => 'bearer']),
        'graph.facebook.com/v23.0/'.ES_PHONE_ID.'/register'         => Http::response(['success' => true]),
        'graph.facebook.com/v23.0/'.ES_PHONE_ID.'/messages'         => Http::response(['messages' => [['id' => 'wamid.SENT1']]]),
        'graph.facebook.com/v23.0/'.ES_PHONE_ID.'?*'                => Http::response(['display_phone_number' => '+55 61 99999-0000', 'verified_name' => 'ZZ Clinica', 'quality_rating' => 'GREEN', 'platform_type' => 'NOT_APPLICABLE']),
        'graph.facebook.com/v23.0/'.ES_WABA_ID.'/subscribed_apps*'  => Http::response(['success' => true]),
        'graph.facebook.com/v23.0/'.ES_WABA_ID.'/message_templates*' => Http::response(['data' => []]),
    ]);
}

function esSent(string $method, string $path): \Illuminate\Support\Collection
{
    return Http::recorded(fn (Request $request) => $request->method() === $method && str_contains($request->url(), $path))->map(fn ($pair) => $pair[0])->values();
}

function esConnect(array $extra = []): \Illuminate\Testing\TestResponse
{
    return test()->actingAs(getDefaultAdmin(), 'user')->postJson(route('admin.whatsapp.connections.cloud.embedded'), $extra + [
        'code'            => 'one-time-code-from-the-popup',
        'waba_id'         => ES_WABA_ID,
        'phone_number_id' => ES_PHONE_ID,
        'event'           => 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING',
    ]);
}

function esSession(array $credentials = []): Session
{
    return Session::query()->create([
        'channel' => 'cloud', 'name' => 'ZZ Oficial', 'external_id' => ES_PHONE_ID, 'status' => 'WORKING', 'phone' => '5561999990000',
        'credentials' => $credentials + ['access_token' => ES_TOKEN, 'waba_id' => ES_WABA_ID],
    ]);
}

function esWebhook(array $payload, ?string $secret = ES_SECRET): \Illuminate\Testing\TestResponse
{
    $body = json_encode($payload);

    return test()->call('POST', route('admin.whatsapp.cloud.webhook'), [], [], [], [
        'CONTENT_TYPE'             => 'application/json',
        'HTTP_ACCEPT'              => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, (string) $secret),
    ], $body);
}

function esEnvelope(string $field, array $value): array
{
    return ['object' => 'whatsapp_business_account', 'entry' => [['id' => ES_WABA_ID, 'changes' => [['field' => $field, 'value' => $value + [
        'messaging_product' => 'whatsapp',
        'metadata'          => ['display_phone_number' => '5561999990000', 'phone_number_id' => ES_PHONE_ID],
    ]]]]]];
}

// ---------------------------------------------------------------------------- connect

it('trades the signup code for a token, stores it encrypted and subscribes the app with this installation as webhook', function () {
    esFakeGraph();

    $response = esConnect()->assertCreated();

    expect($response->json('data.connected'))->toBeTrue()
        ->and($response->json('data.phone'))->toBe('5561999990000')
        ->and($response->json('data.coexistence'))->toBeTrue()
        ->and($response->json('subscribed'))->toBeTrue()
        ->and($response->json('warnings'))->toBe([])
        ->and($response->getContent())->not->toContain(ES_TOKEN);

    $exchange = esSent('GET', '/oauth/access_token')->first();

    expect($exchange['client_id'])->toBe('1234567890')
        ->and($exchange['client_secret'])->toBe(ES_SECRET)
        ->and($exchange['code'])->toBe('one-time-code-from-the-popup');

    $session = Session::query()->where('channel', 'cloud')->where('external_id', ES_PHONE_ID)->firstOrFail();

    expect($session->credentials['access_token'])->toBe(ES_TOKEN)
        ->and($session->credentials['waba_id'])->toBe(ES_WABA_ID)
        ->and($session->credentials['webhook_override'])->toBeTrue()
        ->and($session->name)->toBe('ZZ Clinica')
        ->and($session->status)->toBe('WORKING')
        // Encrypted at rest: the raw column must not show the token.
        ->and((string) DB::table('whatsapp_sessions')->where('id', $session->id)->value('credentials'))->not->toContain(ES_TOKEN);

    $subscribe = esSent('POST', ES_WABA_ID.'/subscribed_apps')->first();

    expect($subscribe->hasHeader('Authorization', 'Bearer '.ES_TOKEN))->toBeTrue()
        ->and($subscribe['override_callback_uri'])->toBe('https://crm.example.test/admin/whatsapp/cloud/webhook')
        ->and($subscribe['verify_token'])->toBe('verify-token-0123456789');

    // A number that stays on the WhatsApp Business app is never registered.
    expect(esSent('POST', '/register'))->toHaveCount(0);
});

it('registers a number that is not on the app, with the given PIN or a generated one', function () {
    esFakeGraph();

    esConnect(['event' => 'FINISH', 'pin' => '123456'])->assertCreated()->assertJsonPath('registered', true)->assertJsonPath('data.coexistence', false);

    $register = esSent('POST', '/register')->first();

    expect($register['pin'])->toBe('123456')->and($register['messaging_product'])->toBe('whatsapp');

    esConnect(['event' => 'FINISH'])->assertCreated();

    expect(esSent('POST', '/register')->last()['pin'])->toMatch('/^\d{6}$/');
});

it('keeps the connection and explains in Portuguese when Meta refuses to register the number', function () {
    esFakeGraph(['graph.facebook.com/v23.0/'.ES_PHONE_ID.'/register' => Http::response(['error' => ['message' => 'Two step verification PIN Mismatch', 'code' => 133005]], 400)]);

    $response = esConnect(['event' => 'FINISH'])->assertCreated()->assertJsonPath('registered', false);

    expect($response->json('warnings.0'))->toContain('PIN')->toContain('133005')
        ->and($response->json('data.connected'))->toBeTrue()
        ->and(Session::query()->where('external_id', ES_PHONE_ID)->firstOrFail()->credentials)->not->toHaveKey('pin');
});

it('falls back to the app webhook when Meta refuses the per-account callback', function () {
    $calls = 0;

    esFakeGraph(['graph.facebook.com/v23.0/'.ES_WABA_ID.'/subscribed_apps*' => function (Request $request) use (&$calls) {
        $calls++;

        return isset($request['override_callback_uri'])
            ? Http::response(['error' => ['message' => 'Callback verification failed', 'code' => 2200]], 400)
            : Http::response(['success' => true]);
    }]);

    esConnect()->assertCreated()->assertJsonPath('subscribed', true);

    expect($calls)->toBe(2)
        ->and(Session::query()->where('external_id', ES_PHONE_ID)->firstOrFail()->credentials['webhook_override'])->toBeFalse();
});

it('stores nothing and answers in Portuguese when Meta refuses the code', function () {
    esFakeGraph(['graph.facebook.com/v23.0/oauth/access_token*' => Http::response(['error' => ['message' => 'This authorization code has expired.', 'code' => 100]], 400)]);

    $response = esConnect()->assertStatus(422);

    expect($response->json('message'))->toContain('A Meta não aceitou a autorização')
        ->and($response->getContent())->not->toContain(ES_SECRET)
        ->and(Session::query()->where('external_id', ES_PHONE_ID)->exists())->toBeFalse();
});

it('refuses the signup when the environment is not configured or the ids are missing', function () {
    esFakeGraph();

    esConnect(['phone_number_id' => ''])->assertStatus(422);

    config(['whatsapp.cloud.es_config_id' => null]);

    esConnect()->assertStatus(422);

    Http::assertNothingSent();
});

it('needs the connections permission', function () {
    esFakeGraph();

    $roleId = DB::table('roles')->insertGetId([
        'name' => 'ZZ es '.uniqid(), 'description' => 'teste', 'permission_type' => 'custom',
        'permissions' => json_encode(['whatsapp', 'whatsapp.send']), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $user = User::query()->findOrFail(DB::table('users')->insertGetId([
        'name' => 'ZZ ES', 'email' => 'zz-'.uniqid().'@example.test', 'password' => Hash::make('secret-123'),
        'status' => 1, 'role_id' => $roleId, 'view_permission' => 'global', 'created_at' => now(), 'updated_at' => now(),
    ]));

    $payload = ['code' => 'one-time-code-from-the-popup', 'waba_id' => ES_WABA_ID, 'phone_number_id' => ES_PHONE_ID];

    test()->actingAs($user, 'user')->postJson(route('admin.whatsapp.connections.cloud.embedded'), $payload)->assertStatus(401);
    test()->actingAs($user, 'user')->getJson(route('admin.whatsapp.templates.list'))->assertStatus(401);
    test()->actingAs($user, 'user')->postJson(route('admin.whatsapp.templates.store'), [])->assertStatus(401);

    Http::assertNothingSent();
});

// ---------------------------------------------------------------------------- disconnect

it('disconnects: cancels the subscription at Meta and erases the token, keeping the number', function () {
    esFakeGraph();

    $session = esSession(['pin' => '654321']);

    test()->actingAs(getDefaultAdmin(), 'user')
        ->postJson(route('admin.whatsapp.connections.cloud.disconnect', ['id' => $session->id]))
        ->assertOk()->assertJsonPath('data.connected', false)->assertJsonPath('unsubscribed', true);

    expect(esSent('DELETE', ES_WABA_ID.'/subscribed_apps'))->toHaveCount(1);

    $session->refresh();

    expect($session->status)->toBe('STOPPED')
        ->and($session->credentials)->not->toHaveKey('access_token')->not->toHaveKey('pin')
        ->and($session->credentials['waba_id'])->toBe(ES_WABA_ID);
});

it('keeps the subscription when another connected number uses the same account', function () {
    esFakeGraph();

    $session = esSession();

    Session::query()->create([
        'channel' => 'cloud', 'name' => 'ZZ Outro', 'external_id' => '990000000000002', 'status' => 'WORKING',
        'credentials' => ['access_token' => ES_TOKEN, 'waba_id' => ES_WABA_ID],
    ]);

    test()->actingAs(getDefaultAdmin(), 'user')
        ->postJson(route('admin.whatsapp.connections.cloud.disconnect', ['id' => $session->id]))
        ->assertOk()->assertJsonPath('unsubscribed', null);

    Http::assertNothingSent();
});

// ---------------------------------------------------------------------------- templates

it('creates a message template at Meta with the body variables and their examples', function () {
    esFakeGraph(['graph.facebook.com/v23.0/'.ES_WABA_ID.'/message_templates*' => Http::response(['id' => '777', 'status' => 'PENDING', 'category' => 'UTILITY'])]);

    $session = esSession();

    test()->actingAs(getDefaultAdmin(), 'user')->postJson(route('admin.whatsapp.templates.store'), [
        'session_id' => $session->id,
        'name'       => 'confirmacao_consulta',
        'category'   => 'UTILITY',
        'language'   => 'pt_BR',
        'body'       => 'Olá {{ 1 }}, sua consulta é dia {{2}}.',
        'footer'     => 'Clínica ZZ',
        'examples'   => ['Maria', '15/10 às 14h'],
    ])->assertCreated()->assertJsonPath('data.status', 'PENDING')->assertJsonPath('data.id', '777');

    $request = esSent('POST', ES_WABA_ID.'/message_templates')->first();

    expect($request->hasHeader('Authorization', 'Bearer '.ES_TOKEN))->toBeTrue()
        ->and($request['name'])->toBe('confirmacao_consulta')
        ->and($request['category'])->toBe('UTILITY')
        ->and($request['language'])->toBe('pt_BR')
        ->and($request['components'])->toBe([
            ['type' => 'BODY', 'text' => 'Olá {{1}}, sua consulta é dia {{2}}.', 'example' => ['body_text' => [['Maria', '15/10 às 14h']]]],
            ['type' => 'FOOTER', 'text' => 'Clínica ZZ'],
        ]);
});

it('validates a template before calling Meta and reports Meta\'s refusal in Portuguese', function () {
    esFakeGraph(['graph.facebook.com/v23.0/'.ES_WABA_ID.'/message_templates*' => Http::response(['error' => ['message' => 'Invalid parameter', 'code' => 100, 'error_data' => ['details' => 'There is already English content for this template']]], 400)]);

    $session = esSession();
    $post = fn (array $data) => test()->actingAs(getDefaultAdmin(), 'user')->postJson(route('admin.whatsapp.templates.store'), $data + [
        'session_id' => $session->id, 'name' => 'aviso_zz', 'category' => 'MARKETING', 'language' => 'pt_BR', 'body' => 'Olá!',
    ]);

    $post(['name' => 'Nome Inválido'])->assertStatus(422)->assertJsonValidationErrors('name');
    $post(['body' => 'Olá {{1}} e {{3}}', 'examples' => ['a', 'b']])->assertStatus(422)->assertJsonPath('message', trans('whatsapp::app.templates.variables_sequence'));
    $post(['body' => 'Olá {{1}}'])->assertStatus(422)->assertJsonPath('message', trans('whatsapp::app.templates.examples_required'));
    $post(['category' => 'AUTHENTICATION'])->assertStatus(422)->assertJsonValidationErrors('category');

    Http::assertNothingSent();

    expect($post([])->assertStatus(422)->json('message'))->toContain('A Meta recusou a criação do modelo')->toContain('already English content');
});

it('lists the templates with their review status', function () {
    esFakeGraph(['graph.facebook.com/v23.0/'.ES_WABA_ID.'/message_templates*' => Http::response(['data' => [
        ['id' => '1', 'name' => 'b_aprovado', 'language' => 'pt_BR', 'status' => 'APPROVED', 'category' => 'UTILITY', 'components' => [['type' => 'BODY', 'text' => 'Olá {{1}}']]],
        ['id' => '2', 'name' => 'a_recusado', 'language' => 'pt_BR', 'status' => 'REJECTED', 'category' => 'MARKETING', 'rejected_reason' => 'INVALID_FORMAT', 'components' => [['type' => 'BODY', 'text' => 'Promo']]],
        ['id' => '3', 'name' => 'c_analise', 'language' => 'pt_BR', 'status' => 'PENDING', 'category' => 'UTILITY', 'rejected_reason' => 'NONE', 'components' => [['type' => 'BODY', 'text' => 'Aviso']]],
    ]])]);

    $session = esSession();

    $response = test()->actingAs(getDefaultAdmin(), 'user')->getJson(route('admin.whatsapp.templates.list'))->assertOk();

    expect($response->json('session_id'))->toBe($session->id)
        ->and(collect($response->json('data'))->pluck('status', 'name')->all())->toBe(['a_recusado' => 'REJECTED', 'b_aprovado' => 'APPROVED', 'c_analise' => 'PENDING'])
        ->and($response->json('data.0.reason'))->toBe('INVALID_FORMAT')
        ->and($response->json('data.1.variables'))->toBe(1)
        ->and($response->json('data.2.reason'))->toBeNull();

    test()->actingAs(getDefaultAdmin(), 'user')->get(route('admin.whatsapp.templates.index'))->assertOk()->assertSee('v-whatsapp-templates', false);
});

// ---------------------------------------------------------------------------- webhook and Inbox

it('rejects a webhook that is not signed with the app secret', function () {
    $session = esSession();
    $payload = esEnvelope('messages', ['messages' => [['from' => '5561988887777', 'id' => 'wamid.FORGED', 'timestamp' => (string) time(), 'type' => 'text', 'text' => ['body' => 'forjada']]]]);

    esWebhook($payload, 'another-secret-0123456789')->assertStatus(401);

    test()->call('POST', route('admin.whatsapp.cloud.webhook'), [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload))->assertStatus(401);

    expect(Message::query()->where('external_message_id', 'wamid.FORGED')->exists())->toBeFalse()
        ->and($session->conversations()->count())->toBe(0);
});

it('receives a signed message for the right number and sends a text back through the official API', function () {
    esFakeGraph();

    $session = esSession();

    esWebhook(esEnvelope('messages', [
        'contacts' => [['profile' => ['name' => 'ZZ Maria'], 'wa_id' => '5561988887777']],
        'messages' => [['from' => '5561988887777', 'id' => 'wamid.IN1', 'timestamp' => (string) time(), 'type' => 'text', 'text' => ['body' => 'Olá, quero agendar']]],
    ]))->assertOk()->assertJson(['received' => true]);

    $conversation = $session->conversations()->firstOrFail();

    expect($conversation->contact_phone)->toBe('5561988887777')->and($conversation->contact_name)->toBe('ZZ Maria');

    test()->actingAs(getDefaultAdmin(), 'user')
        ->postJson(route('admin.whatsapp.inbox.send', ['id' => $conversation->id]), ['text' => 'Claro! Qual horário?'])
        ->assertCreated();

    $sent = esSent('POST', ES_PHONE_ID.'/messages')->first();

    expect($sent->hasHeader('Authorization', 'Bearer '.ES_TOKEN))->toBeTrue()
        ->and($sent['to'])->toBe('5561988887777')
        ->and($sent['text']['body'])->toBe('Claro! Qual horário?');

    // Meta confirms the delivery through the same webhook.
    esWebhook(esEnvelope('messages', ['statuses' => [['id' => 'wamid.SENT1', 'status' => 'delivered', 'timestamp' => (string) time(), 'recipient_id' => '5561988887777']]]))->assertOk();

    $thread = collect(test()->actingAs(getDefaultAdmin(), 'user')->getJson(route('admin.whatsapp.inbox.messages', ['id' => $conversation->id]))->assertOk()->json('data'));

    expect($thread->pluck('content')->all())->toBe(['Olá, quero agendar', 'Claro! Qual horário?'])
        ->and($thread->pluck('direction')->all())->toBe(['inbound', 'outbound'])
        ->and(Message::query()->where('external_message_id', 'wamid.SENT1')->value('status'))->toBe('delivered');
});

it('ignores a signed message for a number that is not connected here', function () {
    esSession();

    $payload = esEnvelope('messages', ['messages' => [['from' => '5561988887777', 'id' => 'wamid.OTHER', 'timestamp' => (string) time(), 'type' => 'text', 'text' => ['body' => 'oi']]]]);
    $payload['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'] = '111111111111111';

    esWebhook($payload)->assertOk();

    expect(Message::query()->where('external_message_id', 'wamid.OTHER')->exists())->toBeFalse();
});

it('shows in the conversation what was sent from the WhatsApp Business app (coexistence echo)', function () {
    $session = esSession(['coexistence' => true]);

    $echo = esEnvelope('smb_message_echoes', ['message_echoes' => [
        ['from' => '5561999990000', 'to' => '5561988887777', 'id' => 'wamid.ECHO1', 'timestamp' => (string) time(), 'type' => 'text', 'text' => ['body' => 'Respondi pelo celular']],
    ]]);

    esWebhook($echo)->assertOk();
    esWebhook($echo)->assertOk(); // Meta may deliver twice.

    $conversation = $session->conversations()->firstOrFail();
    $messages = Message::query()->where('conversation_id', $conversation->id)->get();

    expect($conversation->contact_phone)->toBe('5561988887777')
        ->and($conversation->last_inbound_at)->toBeNull()
        ->and($messages)->toHaveCount(1)
        ->and($messages[0]->direction)->toBe('outbound')
        ->and($messages[0]->status)->toBe('sent')
        ->and($messages[0]->content)->toBe('Respondi pelo celular');
});

it('acknowledges the coexistence syncs (history, contacts) without importing them', function () {
    $session = esSession(['coexistence' => true]);

    esWebhook(esEnvelope('history', ['history' => [[
        'metadata' => ['phase' => 0, 'chunk_order' => 1, 'progress' => 55],
        'threads'  => [['id' => '5561988887777', 'messages' => [['from' => '5561988887777', 'id' => 'wamid.OLD1', 'timestamp' => '1739230955', 'type' => 'text', 'text' => ['body' => 'antiga'], 'history_context' => ['status' => 'READ']]]]],
    ]]]))->assertOk();

    esWebhook(esEnvelope('smb_app_state_sync', ['state_sync' => [
        ['type' => 'contact', 'contact' => ['full_name' => 'ZZ Maria', 'first_name' => 'Maria', 'phone_number' => '5561988887777'], 'action' => 'add', 'metadata' => ['timestamp' => '1739230955']],
    ]]))->assertOk();

    esWebhook(esEnvelope('history', ['history' => [['errors' => [['code' => 2593109, 'title' => 'History sync is turned off by the business']]]]]))->assertOk();
    esWebhook(esEnvelope('account_update', ['event' => 'PARTNER_REMOVED']))->assertOk();

    expect($session->conversations()->count())->toBe(0);
});

it('answers Meta\'s webhook verification only with the right token', function () {
    test()->get(route('admin.whatsapp.cloud.webhook.verify', ['hub.mode' => 'subscribe', 'hub.verify_token' => 'verify-token-0123456789', 'hub.challenge' => '424242']))
        ->assertOk()->assertSee('424242');

    test()->get(route('admin.whatsapp.cloud.webhook.verify', ['hub.mode' => 'subscribe', 'hub.verify_token' => 'wrong', 'hub.challenge' => '424242']))
        ->assertStatus(403);
});

it('shows the connect button with the public ids only', function () {
    $html = test()->actingAs(getDefaultAdmin(), 'user')->get(route('admin.whatsapp.connections.index'))->assertOk()->getContent();

    expect($html)->toContain('1234567890')->toContain('5555555555')->toContain('whatsapp_business_app_onboarding')
        ->not->toContain(ES_SECRET);
});
