<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Webkul\WhatsApp\Models\Conversation;
use Webkul\WhatsApp\Support\AgentNotifier;

uses(DatabaseTransactions::class);

/**
 * WHATSAPP_NOTIFY_*: a WhatsApp notice to the team (number or group) through WAHA on a new
 * automatic lead and on new messages of conversations with a lead. All data is fake.
 */
const ZZN_SECRET = 'zz-notify-hmac-secret-0123456789';

beforeEach(function () {
    Http::preventStrayRequests();
    $this->wahaFails = null;
    Http::fake(['https://waha-notify.test/*' => fn () => match ($this->wahaFails) {
        'timeout' => throw new \Illuminate\Http\Client\ConnectionException('timeout'),
        'error'   => Http::response(['error' => 'x'], 500),
        default   => Http::response(['id' => 'ok']),
    }]);

    if (! DB::table('lead_types')->exists()) {
        DB::table('lead_types')->insert(['name' => 'ZZ tipo', 'created_at' => now(), 'updated_at' => now()]);
    }

    $this->pipelineId = DB::table('lead_pipelines')->insertGetId(['name' => 'ZZ Funil '.uniqid(), 'is_default' => 0, 'rotten_days' => 30, 'created_at' => now(), 'updated_at' => now()]);

    DB::table('lead_pipeline_stages')->insert([
        'code' => 'zz-novo-'.uniqid(), 'name' => 'Novo Lead', 'probability' => 10, 'sort_order' => 1, 'lead_pipeline_id' => $this->pipelineId,
    ]);

    config([
        'app.url'                         => 'https://crm.zz.test',
        'whatsapp.waha.url'               => 'https://waha-notify.test',
        'whatsapp.waha.api_key'           => 'zz-notify-key',
        'whatsapp.waha.hmac_secret'       => ZZN_SECRET,
        'whatsapp.waha.sessions'          => '',
        'whatsapp.auto_lead.mode'         => 'all',
        'whatsapp.auto_lead.pipeline_id'  => $this->pipelineId,
        'whatsapp.auto_lead.stage_id'     => null,
        'whatsapp.auto_lead.stage'        => null,
        'whatsapp.auto_lead.source'       => null,
        'whatsapp.auto_lead.tags'         => '',
        'whatsapp.auto_lead.user_id'      => null,
        'whatsapp.notify.to'              => '',
        'whatsapp.notify.session'         => '',
        'whatsapp.notify.events'          => '',
        'whatsapp.notify.cooldown_minutes' => 30,
        'whatsapp.notify.waha_url'        => null,
        'whatsapp.notify.waha_api_key'    => null,
    ]);
});

function zznOn(string $events = 'new_lead,new_message', string $to = '11988887777, 120363000000000001@g.us'): void
{
    config([
        'whatsapp.notify.to'      => $to,
        'whatsapp.notify.session' => 'zz-avisos',
        'whatsapp.notify.events'  => $events,
    ]);
}

function zznWebhook(array $envelope)
{
    $body = json_encode($envelope);

    return test()->call('POST', route('admin.whatsapp.waha.webhook'), [], [], [], [
        'CONTENT_TYPE'        => 'application/json',
        'HTTP_ACCEPT'         => 'application/json',
        'HTTP_X_WEBHOOK_HMAC' => hash_hmac('sha512', $body, ZZN_SECRET),
    ], $body);
}

function zznMessage(string $phone, string $id, array $options = []): array
{
    $session = $options['session'] ?? 'zz-atendimento';
    $fromMe = $options['fromMe'] ?? false;
    $text = $options['text'] ?? 'Olá! Tenho interesse no produto.';
    $message = isset($options['ad'])
        ? ['extendedTextMessage' => ['text' => $text, 'contextInfo' => ['externalAdReply' => $options['ad']]]]
        : ['conversation' => $text];

    return [
        'event'   => 'message.any',
        'session' => $session,
        'payload' => [
            'id' => ($fromMe ? 'true_' : 'false_').$phone.'@c.us_'.$id, 'timestamp' => time(), 'from' => $phone.'@c.us', 'fromMe' => $fromMe,
            'source' => 'app', 'body' => $text, 'hasMedia' => false,
            '_data' => [
                'Info'    => ['Chat' => $phone.'@s.whatsapp.net', 'IsGroup' => false, 'ID' => $id, 'PushName' => 'ZZ Cliente', 'MediaType' => ''],
                'Message' => $message,
            ],
        ],
    ];
}

function zznPhone(): string
{
    return '55119'.random_int(10000000, 99999999);
}

function zznSent(): array
{
    return Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/api/sendText'))
        ->map(fn (array $pair) => $pair[0]->data())
        ->all();
}

it('sends nothing while it is off (default)', function () {
    zznWebhook(zznMessage(zznPhone(), '3EBN0FF1'))->assertOk();

    expect(zznSent())->toBe([]);
});

it('sends nothing when the session or the destinations are missing', function () {
    config(['whatsapp.notify.to' => '11988887777', 'whatsapp.notify.session' => '', 'whatsapp.notify.events' => 'new_lead,new_message']);
    zznWebhook(zznMessage(zznPhone(), '3EBN0FF2'))->assertOk();

    config(['whatsapp.notify.to' => '', 'whatsapp.notify.session' => 'zz-avisos']);
    zznWebhook(zznMessage(zznPhone(), '3EBN0FF3'))->assertOk();

    expect(zznSent())->toBe([]);
});

it('notifies a new automatic lead once per destination, through the notice session', function () {
    zznOn();
    $phone = zznPhone();

    zznWebhook(zznMessage($phone, '3EBNLEAD1', ['ad' => ['title' => 'Promo ZZ', 'sourceType' => 'ad', 'sourceID' => '1202']]))->assertOk();

    $conversation = Conversation::query()->where('contact_phone', $phone)->first();
    $sent = zznSent();

    expect($conversation->lead_id)->not->toBeNull()
        ->and($sent)->toHaveCount(2)
        ->and(array_column($sent, 'chatId'))->toBe(['5511988887777@c.us', '120363000000000001@g.us'])
        ->and(array_unique(array_column($sent, 'session')))->toBe(['zz-avisos'])
        ->and($sent[0]['text'])->toBe('Novo lead no CRM: ZZ Cliente (+'.$phone.') — origem: Promo ZZ. Abrir: https://crm.zz.test/admin/whatsapp/inbox?c='.$conversation->id);

    Http::assertSent(fn (Request $request) => $request->hasHeader('X-Api-Key', 'zz-notify-key'));
});

it('says "mensagem direta" when the lead did not come from an ad', function () {
    zznOn('new_lead', '11988887777');

    zznWebhook(zznMessage(zznPhone(), '3EBNLEAD2'))->assertOk();

    expect(zznSent())->toHaveCount(1)
        ->and(zznSent()[0]['text'])->toContain('— origem: mensagem direta.');
});

it('notifies new messages of a conversation with a lead, once per cooldown', function () {
    zznOn('new_message', '11988887777');
    $phone = zznPhone();

    // First message opens the lead: no "new message" notice for it.
    zznWebhook(zznMessage($phone, '3EBNMSG1'))->assertOk();
    expect(zznSent())->toBe([]);

    zznWebhook(zznMessage($phone, '3EBNMSG2', ['text' => 'Quanto custa?']))->assertOk();
    zznWebhook(zznMessage($phone, '3EBNMSG3', ['text' => 'Alô?']))->assertOk();

    $conversation = Conversation::query()->where('contact_phone', $phone)->first();
    $sent = zznSent();

    expect($sent)->toHaveCount(1)
        ->and($sent[0]['text'])->toBe('Nova mensagem de ZZ Cliente (+'.$phone.'): «Quanto custa?». Responder: https://crm.zz.test/admin/whatsapp/inbox?c='.$conversation->id);

    // After the cooldown a new message notifies again.
    $this->travel(31)->minutes();
    zznWebhook(zznMessage($phone, '3EBNMSG4', ['text' => 'Ainda aí?']))->assertOk();

    expect(zznSent())->toHaveCount(2);
});

it('does not send both notices for the message that created the lead', function () {
    zznOn('new_lead,new_message', '11988887777');

    zznWebhook(zznMessage(zznPhone(), '3EBNBOTH1'))->assertOk();

    expect(zznSent())->toHaveCount(1)
        ->and(zznSent()[0]['text'])->toStartWith('Novo lead no CRM:');
});

it('ignores outbound messages and events of the notice session', function () {
    zznOn('new_lead,new_message', '11988887777');
    $phone = zznPhone();

    zznWebhook(zznMessage($phone, '3EBNOUT1'))->assertOk();
    $before = count(zznSent());

    zznWebhook(zznMessage($phone, '3EBNOUT2', ['fromMe' => true, 'text' => 'Oi, tudo bem?']))->assertOk();

    $other = zznPhone();
    zznWebhook(zznMessage($other, '3EBNOUT3', ['session' => 'zz-avisos']))->assertOk();

    expect(zznSent())->toHaveCount($before)
        ->and(Conversation::query()->where('contact_phone', $other)->exists())->toBeFalse();
});

it('describes media without caption', function () {
    $message = new \Webkul\WhatsApp\Models\Message(['content' => null, 'message_type' => 'audio']);

    expect(AgentNotifier::excerpt($message))->toBe('[áudio]')
        ->and(AgentNotifier::excerpt(new \Webkul\WhatsApp\Models\Message(['content' => 'Veja', 'message_type' => 'image'])))->toBe('[imagem] Veja')
        ->and(AgentNotifier::excerpt(new \Webkul\WhatsApp\Models\Message(['content' => str_repeat('a', 200), 'message_type' => 'text'])))->toHaveLength(121);
});

it('never breaks the webhook when WAHA fails', function () {
    \Illuminate\Support\Facades\Log::spy();
    $this->wahaFails = 'timeout';
    zznOn('new_lead', '11988887777');
    $phone = zznPhone();

    zznWebhook(zznMessage($phone, '3EBNFAIL1'))->assertOk();

    $this->wahaFails = 'error';
    zznWebhook(zznMessage(zznPhone(), '3EBNFAIL2'))->assertOk();

    expect(Conversation::query()->where('contact_phone', $phone)->first()->lead_id)->not->toBeNull();

    // Both failures (no answer, then HTTP 500) only became warnings.
    \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->with('WhatsApp notice could not be sent.', \Mockery::any())->once();
    \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->with('WhatsApp notice was refused by WAHA.', \Mockery::any())->once();
});

it('notifies a lead that came through the Cloud API, but always sends through WAHA', function () {
    zznOn('new_lead', '11988887777');
    config(['whatsapp.cloud.app_secret' => 'zz-cloud-secret-0123456789']);

    \Webkul\WhatsApp\Models\Session::query()->create([
        'channel' => 'cloud', 'name' => 'ZZ Oficial', 'external_id' => '990000000000077', 'status' => 'WORKING', 'phone' => '5561999990000',
        'credentials' => ['access_token' => 'zz-token', 'waba_id' => '880000000000077'],
    ]);

    $phone = zznPhone();
    $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => '880000000000077', 'changes' => [['field' => 'messages', 'value' => [
        'messaging_product' => 'whatsapp',
        'metadata'          => ['display_phone_number' => '5561999990000', 'phone_number_id' => '990000000000077'],
        'contacts'          => [['profile' => ['name' => 'ZZ Cloud'], 'wa_id' => $phone]],
        'messages'          => [['from' => $phone, 'id' => 'wamid.ZZN1', 'timestamp' => (string) time(), 'type' => 'text', 'text' => ['body' => 'Oi']]],
    ]]]]]]);

    test()->call('POST', route('admin.whatsapp.cloud.webhook'), [], [], [], [
        'CONTENT_TYPE'             => 'application/json',
        'HTTP_ACCEPT'              => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'zz-cloud-secret-0123456789'),
    ], $body)->assertOk();

    expect(zznSent())->toHaveCount(1)
        ->and(zznSent()[0]['session'])->toBe('zz-avisos')
        ->and(zznSent()[0]['text'])->toStartWith('Novo lead no CRM: ZZ Cloud (+'.$phone.')');

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'graph.facebook.com'));
});
