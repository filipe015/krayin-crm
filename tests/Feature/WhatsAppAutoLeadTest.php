<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Webkul\Lead\Models\Lead;
use Webkul\WhatsApp\Models\Conversation;
use Webkul\WhatsApp\Support\AutoLead;
use Webkul\WhatsApp\Support\ConversationLead;

uses(DatabaseTransactions::class);

/**
 * WHATSAPP_AUTO_LEAD: a contact writing in a chat without a lead gets one, through a
 * signed WAHA webhook, like in production. Everything here is fake data.
 */
const ZZ_AUTO_SECRET = 'zz-auto-lead-hmac-secret-0123456789';

beforeEach(function () {
    Http::preventStrayRequests();

    if (! DB::table('lead_types')->exists()) {
        DB::table('lead_types')->insert(['name' => 'ZZ tipo', 'created_at' => now(), 'updated_at' => now()]);
    }

    $this->pipelineId = DB::table('lead_pipelines')->insertGetId(['name' => 'ZZ Funil '.uniqid(), 'is_default' => 0, 'rotten_days' => 30, 'created_at' => now(), 'updated_at' => now()]);

    $this->stages = [];

    foreach (['Entrada' => 'zz-entrada', 'Novo Lead' => 'zz-novo', 'Ganho' => 'won'] as $name => $code) {
        $this->stages[$name] = DB::table('lead_pipeline_stages')->insertGetId([
            'code' => $code.'-'.uniqid(), 'name' => $name, 'probability' => 10, 'sort_order' => count($this->stages) + 1, 'lead_pipeline_id' => $this->pipelineId,
        ]);
    }

    config([
        'whatsapp.waha.hmac_secret'       => ZZ_AUTO_SECRET,
        'whatsapp.waha.sessions'          => '',
        'whatsapp.auto_lead.mode'         => 'off',
        'whatsapp.auto_lead.pipeline_id'  => $this->pipelineId,
        'whatsapp.auto_lead.stage_id'     => null,
        'whatsapp.auto_lead.stage'        => 'Novo Lead',
        'whatsapp.auto_lead.source'       => null,
        'whatsapp.auto_lead.tags'         => '',
        'whatsapp.auto_lead.user_id'      => null,
    ]);
});

function zzAutoWebhook(array $envelope)
{
    $body = json_encode($envelope);

    return test()->call('POST', route('admin.whatsapp.waha.webhook'), [], [], [], [
        'CONTENT_TYPE'        => 'application/json',
        'HTTP_ACCEPT'         => 'application/json',
        'HTTP_X_WEBHOOK_HMAC' => hash_hmac('sha512', $body, ZZ_AUTO_SECRET),
    ], $body);
}

function zzAutoMessage(string $phone, string $id, ?array $ad = null, string $session = 'zz-auto', bool $fromMe = false): array
{
    $text = 'Olá! Tenho interesse.';
    $message = $ad === null
        ? ['conversation' => $text]
        : ['extendedTextMessage' => ['text' => $text, 'contextInfo' => ['externalAdReply' => $ad]]];

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

function zzPhone(): string
{
    return '55119'.random_int(10000000, 99999999);
}

function zzConversation(string $phone): ?Conversation
{
    return Conversation::query()->where('contact_phone', $phone)->orderBy('id')->first();
}

function zzAd(array $overrides = []): array
{
    return $overrides + [
        'title' => 'Promoção ZZ', 'body' => 'Chame no WhatsApp', 'sourceType' => 'ad',
        'sourceID' => '120200000000000777', 'sourceURL' => 'https://fb.me/zz-teste', 'ctwaClid' => 'ARzzclid',
    ];
}

it('creates nothing when the mode is off, even for an ad', function () {
    $phone = zzPhone();

    zzAutoWebhook(zzAutoMessage($phone, '3EB0OFF1', zzAd()))->assertOk();

    expect(zzConversation($phone))->lead_id->toBeNull()
        ->and(zzConversation($phone)->ad_source_id)->toBe('120200000000000777');
});

it('in "ads" mode opens a lead only for a conversation that came from an ad', function () {
    config(['whatsapp.auto_lead.mode' => 'ads']);

    $plain = zzPhone();
    zzAutoWebhook(zzAutoMessage($plain, '3EB0ADS1'))->assertOk();
    expect(zzConversation($plain)->lead_id)->toBeNull();

    $fromAd = zzPhone();
    zzAutoWebhook(zzAutoMessage($fromAd, '3EB0ADS2', zzAd()))->assertOk();

    $conversation = zzConversation($fromAd);
    $lead = Lead::query()->with(['source', 'stage', 'person'])->find($conversation->lead_id);

    expect($lead)->not->toBeNull()
        ->and($lead->title)->toBe('ZZ Cliente - WhatsApp (Promoção ZZ)')
        ->and($lead->source->name)->toBe('WhatsApp Anúncio')
        ->and($lead->lead_pipeline_id)->toBe($this->pipelineId)
        ->and($lead->lead_pipeline_stage_id)->toBe($this->stages['Novo Lead'])
        ->and((float) $lead->lead_value)->toBe(0.0)
        ->and($lead->description)->toContain('120200000000000777', 'https://fb.me/zz-teste', 'Promoção ZZ', 'ARzzclid')
        ->and($lead->person->contact_numbers[0]['value'])->toBe($fromAd)
        ->and($conversation->ad_title)->toBe('Promoção ZZ')
        ->and($conversation->ad_ctwa_clid)->toBe('ARzzclid')
        ->and($conversation->ad_captured_at)->not->toBeNull();

    // A later message with another ad does not overwrite the origin.
    zzAutoWebhook(zzAutoMessage($fromAd, '3EB0ADS3', zzAd(['title' => 'Outro', 'sourceID' => '999'])))->assertOk();

    expect(zzConversation($fromAd))->ad_source_id->toBe('120200000000000777')->ad_title->toBe('Promoção ZZ');
});

it('in "all" mode opens a lead for any new contact, with the configured tags', function () {
    config(['whatsapp.auto_lead.mode' => 'all', 'whatsapp.auto_lead.tags' => 'ZZ Tag A, ZZ Tag B']);

    $phone = zzPhone();
    zzAutoWebhook(zzAutoMessage($phone, '3EB0ALL1'))->assertOk();

    $lead = Lead::query()->with(['source', 'tags'])->find(zzConversation($phone)->lead_id);

    expect($lead->title)->toBe('ZZ Cliente - WhatsApp')
        ->and($lead->source->name)->toBe('WhatsApp')
        ->and($lead->lead_pipeline_stage_id)->toBe($this->stages['Novo Lead'])
        ->and($lead->tags->pluck('name')->sort()->values()->all())->toBe(['ZZ Tag A', 'ZZ Tag B']);
});

it('never opens a lead for a message we sent', function () {
    config(['whatsapp.auto_lead.mode' => 'all']);

    $phone = zzPhone();
    zzAutoWebhook(zzAutoMessage($phone, '3EB0ME01', null, 'zz-auto', true))->assertOk();

    expect(zzConversation($phone)->lead_id)->toBeNull();
});

it('uses the source from the env and the stage by id, falling back to the first stage for an unknown name', function () {
    config(['whatsapp.auto_lead.mode' => 'all', 'whatsapp.auto_lead.source' => 'ZZ Origem', 'whatsapp.auto_lead.stage_id' => $this->stages['Ganho']]);

    $phone = zzPhone();
    zzAutoWebhook(zzAutoMessage($phone, '3EB0SRC1'))->assertOk();

    $lead = Lead::query()->with('source')->find(zzConversation($phone)->lead_id);
    expect($lead->source->name)->toBe('ZZ Origem')->and($lead->lead_pipeline_stage_id)->toBe($this->stages['Ganho']);

    config(['whatsapp.auto_lead.stage_id' => null, 'whatsapp.auto_lead.stage' => 'Não existe']);

    expect(app(AutoLead::class)->placement()[1]->id)->toBe($this->stages['Entrada']);

    config(['whatsapp.auto_lead.stage' => '  novo lead ']);

    expect(app(AutoLead::class)->placement()[1]->id)->toBe($this->stages['Novo Lead']);
});

it('links the lead that already exists for the number instead of opening another', function () {
    config(['whatsapp.auto_lead.mode' => 'all']);

    // A mobile number (9 then 6-9), the only kind WhatsApp shows with or without the ninth digit.
    $phone = '551198'.random_int(1000000, 9999999);
    zzAutoWebhook(zzAutoMessage($phone, '3EB0DUP1', null, 'zz-auto-a'))->assertOk();

    $leadId = zzConversation($phone)->lead_id;
    expect($leadId)->not->toBeNull();

    // Same contact, another number of the company, written without the ninth digit.
    $withoutNinth = '5511'.substr($phone, 5);
    zzAutoWebhook(zzAutoMessage($withoutNinth, '3EB0DUP2', zzAd(), 'zz-auto-b'))->assertOk();

    $other = Conversation::query()->where('contact_phone', $withoutNinth)->first();

    expect($other->lead_id)->toBe($leadId)
        ->and(Lead::query()->where('title', 'like', 'ZZ Cliente - WhatsApp%')->whereIn('id', Conversation::query()->whereIn('contact_phone', [$phone, $withoutNinth])->pluck('lead_id'))->count())->toBe(1);
});

it('opens a single lead when the same webhook arrives twice or two deliveries race', function () {
    config(['whatsapp.auto_lead.mode' => 'all']);

    $phone = zzPhone();
    $envelope = zzAutoMessage($phone, '3EB0REP1');

    zzAutoWebhook($envelope)->assertOk();
    zzAutoWebhook($envelope)->assertOk();

    $conversation = zzConversation($phone);

    expect(Conversation::query()->where('contact_phone', $phone)->count())->toBe(1)
        ->and(DB::table('whatsapp_messages')->where('conversation_id', $conversation->id)->count())->toBe(1);

    // Two stale copies of a conversation without a lead, handled one after the other (as
    // two workers would once the lock is released): only one lead.
    $racePhone = zzPhone();
    $race = Conversation::query()->create(['session_id' => $conversation->session_id, 'chat_id' => $racePhone.'@c.us', 'contact_phone' => $racePhone, 'contact_name' => 'ZZ Corrida', 'status' => 'open']);
    $copy = Conversation::query()->find($race->id);

    $first = app(AutoLead::class)->handle($race);
    $second = app(AutoLead::class)->handle($copy);

    expect($first)->not->toBeNull()->toBe($second)
        ->and(Lead::query()->where('title', 'ZZ Corrida - WhatsApp')->count())->toBe(1);
});

it('keeps the message when the lead cannot be created', function () {
    config(['whatsapp.auto_lead.mode' => 'all']);

    app()->bind(ConversationLead::class, fn ($app) => new class($app->make(\Webkul\Lead\Repositories\LeadRepository::class), $app->make(\Webkul\Lead\Repositories\PipelineRepository::class), $app->make(\Webkul\WhatsApp\Support\LeadPhoneFinder::class)) extends ConversationLead {
        public function open(Conversation $conversation, array $options): Lead
        {
            throw new RuntimeException('boom');
        }
    });

    $phone = zzPhone();
    zzAutoWebhook(zzAutoMessage($phone, '3EB0ERR1'))->assertOk()->assertJson(['received' => true]);

    $conversation = zzConversation($phone);

    expect($conversation->lead_id)->toBeNull()
        ->and(DB::table('whatsapp_messages')->where('conversation_id', $conversation->id)->count())->toBe(1);
});

it('shows the ad origin in the Inbox conversation data', function () {
    $phone = zzPhone();
    zzAutoWebhook(zzAutoMessage($phone, '3EB0UI01', zzAd(['sourceURL' => 'javascript:alert(1)'])))->assertOk();

    $row = collect(test()->actingAs(getDefaultAdmin(), 'user')->getJson(route('admin.whatsapp.inbox.conversations', ['tab' => 'all']))->assertOk()->json('data'))
        ->firstWhere('id', zzConversation($phone)->id);

    expect($row['ad'])->toMatchArray(['title' => 'Promoção ZZ', 'source_id' => '120200000000000777', 'url' => null]);

    test()->get(route('admin.whatsapp.inbox.index'))->assertOk()->assertSee('labels.ad_origin', false);
});

it('keeps the manual "Criar lead" button working through the shared service', function () {
    $phone = zzPhone();
    zzAutoWebhook(zzAutoMessage($phone, '3EB0MAN1'))->assertOk();

    $conversation = zzConversation($phone);
    expect($conversation->lead_id)->toBeNull();

    $response = test()->actingAs(getDefaultAdmin(), 'user')
        ->postJson(route('admin.whatsapp.inbox.lead.create', ['id' => $conversation->id]))
        ->assertCreated()
        ->assertJson(['created' => true, 'lead' => ['title' => 'WhatsApp - ZZ Cliente']]);

    $lead = Lead::query()->with('source')->find($response->json('lead.id'));

    expect($lead->source->name)->toBe('WhatsApp')
        ->and($lead->user_id)->toBe(getDefaultAdmin()->id)
        ->and($conversation->refresh()->lead_id)->toBe($lead->id);

    // A second conversation of the same number reuses that lead.
    $again = Conversation::query()->create(['session_id' => $conversation->session_id, 'chat_id' => 'zz-'.$phone.'@c.us', 'contact_phone' => $phone, 'status' => 'open']);

    test()->postJson(route('admin.whatsapp.inbox.lead.create', ['id' => $again->id]))->assertOk()->assertJson(['created' => false, 'lead' => ['id' => $lead->id]]);
});
