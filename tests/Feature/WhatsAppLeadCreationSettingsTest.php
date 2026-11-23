<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Webkul\Lead\Models\Lead;
use Webkul\Lead\Repositories\PipelineRepository;
use Webkul\User\Models\User;
use Webkul\WhatsApp\Models\Conversation;
use Webkul\WhatsApp\Models\LeadSetting;
use Webkul\WhatsApp\Support\AutoLead;
use Webkul\WhatsApp\Support\LeadCreationSettings;

uses(DatabaseTransactions::class);

/**
 * Configurações → Criação de leads pelo WhatsApp: the saved row wins over WHATSAPP_AUTO_LEAD*,
 * the env applies while nothing was saved, and "Criar lead" in the Inbox lets the operator pick
 * the pipeline and stage. Everything here is fake data.
 */
const ZZL_SECRET = 'zz-lead-settings-hmac-secret-0123456789';

beforeEach(function () {
    Http::preventStrayRequests();

    LeadSetting::query()->delete();
    LeadCreationSettings::forget();

    if (! DB::table('lead_types')->exists()) {
        DB::table('lead_types')->insert(['name' => 'ZZ tipo', 'created_at' => now(), 'updated_at' => now()]);
    }

    $this->pipelineId = DB::table('lead_pipelines')->insertGetId(['name' => 'ZZL Funil '.uniqid(), 'is_default' => 0, 'rotten_days' => 30, 'created_at' => now(), 'updated_at' => now()]);
    $this->otherPipelineId = DB::table('lead_pipelines')->insertGetId(['name' => 'ZZL Outro '.uniqid(), 'is_default' => 0, 'rotten_days' => 30, 'created_at' => now(), 'updated_at' => now()]);

    $this->stages = [];

    foreach (['Entrada' => 'zz-entrada', 'Qualificado' => 'zz-qualif', 'Ganho' => 'won'] as $name => $code) {
        $this->stages[$name] = DB::table('lead_pipeline_stages')->insertGetId([
            'code' => $code.'-'.uniqid(), 'name' => $name, 'probability' => 10, 'sort_order' => count($this->stages) + 1, 'lead_pipeline_id' => $this->pipelineId,
        ]);
    }

    // A real "won" stage (code exactly "won") is never offered.
    $this->wonStage = DB::table('lead_pipeline_stages')->insertGetId(['code' => 'won', 'name' => 'Ganho real', 'probability' => 100, 'sort_order' => 9, 'lead_pipeline_id' => $this->pipelineId]);
    $this->otherStage = DB::table('lead_pipeline_stages')->insertGetId(['code' => 'zz-o-'.uniqid(), 'name' => 'Outra etapa', 'probability' => 10, 'sort_order' => 1, 'lead_pipeline_id' => $this->otherPipelineId]);

    config([
        'whatsapp.waha.hmac_secret'       => ZZL_SECRET,
        'whatsapp.waha.sessions'          => '',
        'whatsapp.auto_lead.mode'         => 'off',
        'whatsapp.auto_lead.pipeline_id'  => null,
        'whatsapp.auto_lead.stage_id'     => null,
        'whatsapp.auto_lead.stage'        => null,
        'whatsapp.auto_lead.source'       => null,
        'whatsapp.auto_lead.tags'         => '',
        'whatsapp.auto_lead.user_id'      => null,
    ]);
});

function zzlWebhook(string $phone, string $id, bool $fromAd = false)
{
    $text = 'Olá! Quero saber mais.';
    $message = ! $fromAd
        ? ['conversation' => $text]
        : ['extendedTextMessage' => ['text' => $text, 'contextInfo' => ['externalAdReply' => [
            'title' => 'Anúncio ZZL', 'sourceType' => 'ad', 'sourceID' => '120200000000000888', 'sourceURL' => 'https://fb.me/zzl', 'ctwaClid' => 'ARzzl',
        ]]]];

    $body = json_encode([
        'event'   => 'message.any',
        'session' => 'zz-lead-settings',
        'payload' => [
            'id' => 'false_'.$phone.'@c.us_'.$id, 'timestamp' => time(), 'from' => $phone.'@c.us', 'fromMe' => false,
            'source' => 'app', 'body' => $text, 'hasMedia' => false,
            '_data' => [
                'Info'    => ['Chat' => $phone.'@s.whatsapp.net', 'IsGroup' => false, 'ID' => $id, 'PushName' => 'ZZL Cliente', 'MediaType' => ''],
                'Message' => $message,
            ],
        ],
    ]);

    return test()->call('POST', route('admin.whatsapp.waha.webhook'), [], [], [], [
        'CONTENT_TYPE'        => 'application/json',
        'HTTP_ACCEPT'         => 'application/json',
        'HTTP_X_WEBHOOK_HMAC' => hash_hmac('sha512', $body, ZZL_SECRET),
    ], $body);
}

function zzlPhone(): string
{
    return '55119'.random_int(10000000, 99999999);
}

function zzlConversation(string $phone): ?Conversation
{
    return Conversation::query()->where('contact_phone', $phone)->orderBy('id')->first();
}

/**
 * A conversation without a lead, on a WhatsApp number, as the Inbox shows it.
 */
function zzlInboxConversation(?string $phone = null): Conversation
{
    $session = DB::table('whatsapp_sessions')->value('id') ?? DB::table('whatsapp_sessions')->insertGetId(['name' => 'ZZL sessão', 'channel' => 'waha', 'external_id' => 'zzl-'.uniqid(), 'status' => 'WORKING', 'created_at' => now(), 'updated_at' => now()]);
    $phone ??= zzlPhone();

    return Conversation::query()->create(['session_id' => $session, 'chat_id' => uniqid('zzl-').'-'.$phone.'@c.us', 'contact_phone' => $phone, 'contact_name' => 'ZZL Contato', 'status' => 'open']);
}

function zzlUser(array $permissions): User
{
    $roleId = DB::table('roles')->insertGetId([
        'name' => 'ZZL papel '.uniqid(), 'description' => 'teste', 'permission_type' => 'custom',
        'permissions' => json_encode($permissions), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $id = DB::table('users')->insertGetId([
        'name' => 'ZZL Usuário', 'email' => 'zzl-'.uniqid().'@example.test', 'password' => Hash::make('secret-123'),
        'status' => 1, 'role_id' => $roleId, 'view_permission' => 'global', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return User::query()->findOrFail($id);
}

function zzlSave(array $values): LeadSetting
{
    return LeadSetting::query()->create($values + ['mode' => 'manual']);
}

// ---------------------------------------------------------------------------------------------
// Precedence: saved row > env.
// ---------------------------------------------------------------------------------------------

it('falls back to the env while nothing was saved, reading "off" as manual', function () {
    expect(LeadCreationSettings::current())->toMatchArray(['saved' => false, 'mode' => 'manual'])
        ->and(AutoLead::mode())->toBe('manual');

    config(['whatsapp.auto_lead.mode' => 'ads']);
    expect(AutoLead::mode())->toBe('ads');

    config(['whatsapp.auto_lead.mode' => 'ALL ']);
    expect(AutoLead::mode())->toBe('all');

    config(['whatsapp.auto_lead.mode' => 'nonsense']);
    expect(AutoLead::mode())->toBe('manual');
});

it('in manual mode (env "off") creates no lead and the conversation still shows in the Inbox', function () {
    $phone = zzlPhone();
    zzlWebhook($phone, '3EBLMAN1', true)->assertOk();

    $conversation = zzlConversation($phone);
    expect($conversation->lead_id)->toBeNull();

    $ids = collect(test()->actingAs(getDefaultAdmin(), 'user')->getJson(route('admin.whatsapp.inbox.conversations', ['tab' => 'all']))->assertOk()->json('data'))->pluck('id');

    expect($ids)->toContain($conversation->id);
});

it('lets the saved row win over the env: saved "manual" stops an env "all"', function () {
    config(['whatsapp.auto_lead.mode' => 'all']);
    zzlSave(['mode' => 'manual']);

    $phone = zzlPhone();
    zzlWebhook($phone, '3EBLPRE1')->assertOk();

    expect(zzlConversation($phone)->lead_id)->toBeNull()
        ->and(AutoLead::mode())->toBe('manual');
});

it('uses the saved pipeline, stage, source, tags and owner in "all" mode, ignoring the env', function () {
    $owner = getDefaultAdmin();

    config(['whatsapp.auto_lead.mode' => 'off', 'whatsapp.auto_lead.source' => 'Origem do env', 'whatsapp.auto_lead.tags' => 'Tag do env']);
    zzlSave(['mode' => 'all', 'pipeline_id' => $this->pipelineId, 'stage_id' => $this->stages['Qualificado'], 'source' => 'ZZL Origem', 'tags' => 'ZZL A, ZZL B', 'user_id' => $owner->id]);

    $phone = zzlPhone();
    zzlWebhook($phone, '3EBLALL1')->assertOk();

    $lead = Lead::query()->with(['source', 'tags'])->find(zzlConversation($phone)->lead_id);

    expect($lead)->not->toBeNull()
        ->and($lead->lead_pipeline_id)->toBe($this->pipelineId)
        ->and($lead->lead_pipeline_stage_id)->toBe($this->stages['Qualificado'])
        ->and($lead->source->name)->toBe('ZZL Origem')
        ->and($lead->user_id)->toBe($owner->id)
        ->and($lead->tags->pluck('name')->sort()->values()->all())->toBe(['ZZL A', 'ZZL B']);
});

it('in saved "ads" mode opens a lead only for a conversation that came from an ad', function () {
    zzlSave(['mode' => 'ads', 'pipeline_id' => $this->pipelineId]);

    $plain = zzlPhone();
    zzlWebhook($plain, '3EBLADS1')->assertOk();
    expect(zzlConversation($plain)->lead_id)->toBeNull();

    $fromAd = zzlPhone();
    zzlWebhook($fromAd, '3EBLADS2', true)->assertOk();

    $lead = Lead::query()->with('source')->find(zzlConversation($fromAd)->lead_id);

    expect($lead)->not->toBeNull()
        ->and($lead->source->name)->toBe('WhatsApp Anúncio')
        ->and($lead->lead_pipeline_id)->toBe($this->pipelineId)
        ->and($lead->lead_pipeline_stage_id)->toBe($this->stages['Entrada']);
});

it('forgets the cached settings when the row is saved', function () {
    expect(AutoLead::mode())->toBe('manual');

    $setting = zzlSave(['mode' => 'all']);
    expect(AutoLead::mode())->toBe('all');

    $setting->update(['mode' => 'ads']);
    expect(AutoLead::mode())->toBe('ads');
});

it('falls back to the env and does not throw when the settings cannot be read', function () {
    config(['whatsapp.auto_lead.mode' => 'ads']);

    Cache::shouldReceive('remember')->andThrow(new RuntimeException('no table'));
    Cache::shouldReceive('forget')->andReturnTrue();

    expect(LeadCreationSettings::current())->toMatchArray(['saved' => false, 'mode' => 'ads']);
});

// ---------------------------------------------------------------------------------------------
// Settings screen.
// ---------------------------------------------------------------------------------------------

it('shows the settings screen and saves the single row, normalizing "off"', function () {
    test()->actingAs(getDefaultAdmin(), 'user')
        ->get(route('admin.whatsapp.lead-settings.index'))
        ->assertOk()
        ->assertSee('v-whatsapp-lead-settings', false);

    test()->putJson(route('admin.whatsapp.lead-settings.update'), [
        'mode' => 'all', 'pipeline_id' => $this->pipelineId, 'stage_id' => $this->stages['Qualificado'],
        'source' => ' ZZL Origem ', 'tags' => 'X, Y, X,, ', 'user_id' => getDefaultAdmin()->id,
    ])->assertOk()->assertJson(['saved' => true]);

    test()->putJson(route('admin.whatsapp.lead-settings.update'), ['mode' => 'off'])->assertOk();

    expect(LeadSetting::query()->count())->toBe(1)
        ->and(LeadSetting::query()->first()->mode)->toBe('manual')
        ->and(AutoLead::mode())->toBe('manual');

    test()->putJson(route('admin.whatsapp.lead-settings.update'), [
        'mode' => 'ads', 'pipeline_id' => $this->pipelineId, 'stage_id' => $this->stages['Qualificado'], 'source' => ' ZZL Origem ', 'tags' => 'X, Y, X,, ',
    ])->assertOk();

    expect(LeadSetting::query()->first())
        ->source->toBe('ZZL Origem')
        ->tags->toBe('X, Y')
        ->stage_id->toBe($this->stages['Qualificado']);
});

it('rejects a stage that is not an open stage of the chosen pipeline', function () {
    test()->actingAs(getDefaultAdmin(), 'user');

    test()->putJson(route('admin.whatsapp.lead-settings.update'), ['mode' => 'all', 'pipeline_id' => $this->pipelineId, 'stage_id' => $this->otherStage])->assertStatus(422);
    test()->putJson(route('admin.whatsapp.lead-settings.update'), ['mode' => 'all', 'pipeline_id' => $this->pipelineId, 'stage_id' => $this->wonStage])->assertStatus(422);
    test()->putJson(route('admin.whatsapp.lead-settings.update'), ['mode' => 'all', 'stage_id' => $this->stages['Entrada']])->assertStatus(422);
    test()->putJson(route('admin.whatsapp.lead-settings.update'), ['mode' => 'sometimes'])->assertStatus(422);

    expect(LeadSetting::query()->count())->toBe(0);
});

it('lets a user who can only view the screen open it but not save it', function () {
    $viewer = zzlUser(['settings', 'settings.other_settings', 'settings.other_settings.whatsapp-leads']);

    test()->actingAs($viewer, 'user')->get(route('admin.whatsapp.lead-settings.index'))->assertOk();
    test()->actingAs($viewer, 'user')->putJson(route('admin.whatsapp.lead-settings.update'), ['mode' => 'all'])->assertStatus(401);

    $nobody = zzlUser(['leads']);
    test()->actingAs($nobody, 'user')->get(route('admin.whatsapp.lead-settings.index'))->assertStatus(401);

    expect(LeadSetting::query()->count())->toBe(0);
});

it('maps every new route in the ACL', function () {
    $roles = acl()->getRoles();

    expect($roles['admin.whatsapp.inbox.lead.options'] ?? null)->toBe('whatsapp.manage')
        ->and($roles['admin.whatsapp.inbox.lead.create'] ?? null)->toBe('whatsapp.manage')
        ->and($roles['admin.whatsapp.lead-settings.index'] ?? null)->toBe('settings.other_settings.whatsapp-leads')
        ->and($roles['admin.whatsapp.lead-settings.update'] ?? null)->toBe('settings.other_settings.whatsapp-leads.edit');
});

// ---------------------------------------------------------------------------------------------
// Inbox: "Criar lead" with a chosen pipeline and stage.
// ---------------------------------------------------------------------------------------------

it('offers the pipelines with their open stages in order, the default one marked', function () {
    $conversation = zzlInboxConversation();
    $defaultId = app(PipelineRepository::class)->getDefaultPipeline()?->id;

    $data = test()->actingAs(getDefaultAdmin(), 'user')
        ->getJson(route('admin.whatsapp.inbox.lead.options', ['id' => $conversation->id]))
        ->assertOk()
        ->json();

    $mine = collect($data['pipelines'])->firstWhere('id', $this->pipelineId);

    expect($data['default_pipeline_id'])->toBe($defaultId)
        ->and(collect($data['pipelines'])->firstWhere('id', $defaultId)['is_default'] ?? null)->toBeTrue()
        ->and($mine['is_default'])->toBeFalse()
        ->and(collect($mine['stages'])->pluck('id')->all())->toBe([$this->stages['Entrada'], $this->stages['Qualificado'], $this->stages['Ganho']])
        ->and(collect($mine['stages'])->pluck('id'))->not->toContain($this->wonStage);
});

it('requires leads.create for the options and for creating the lead', function () {
    $conversation = zzlInboxConversation();

    // Can manage conversations but not create leads: the controller refuses.
    $manager = zzlUser(['whatsapp', 'whatsapp.manage']);

    test()->actingAs($manager, 'user')->getJson(route('admin.whatsapp.inbox.lead.options', ['id' => $conversation->id]))->assertForbidden();
    test()->actingAs($manager, 'user')->postJson(route('admin.whatsapp.inbox.lead.create', ['id' => $conversation->id]), ['pipeline_id' => $this->pipelineId])->assertForbidden();

    // Without whatsapp.manage the ACL map (Bouncer) already stops the route.
    $reader = zzlUser(['whatsapp', 'leads', 'leads.create']);

    test()->actingAs($reader, 'user')->getJson(route('admin.whatsapp.inbox.lead.options', ['id' => $conversation->id]))->assertStatus(401);

    // With both, it works.
    $seller = zzlUser(['whatsapp', 'whatsapp.manage', 'leads', 'leads.view', 'leads.create']);

    test()->actingAs($seller, 'user')->getJson(route('admin.whatsapp.inbox.lead.options', ['id' => $conversation->id]))->assertOk();

    expect($conversation->refresh()->lead_id)->toBeNull();
});

it('creates the lead in the chosen pipeline and stage', function () {
    $conversation = zzlInboxConversation();

    $response = test()->actingAs(getDefaultAdmin(), 'user')
        ->postJson(route('admin.whatsapp.inbox.lead.create', ['id' => $conversation->id]), ['pipeline_id' => $this->pipelineId, 'stage_id' => $this->stages['Qualificado']])
        ->assertCreated()
        ->assertJson(['created' => true, 'lead' => ['stage' => 'Qualificado']]);

    $lead = Lead::query()->find($response->json('lead.id'));

    expect($lead->lead_pipeline_id)->toBe($this->pipelineId)
        ->and($lead->lead_pipeline_stage_id)->toBe($this->stages['Qualificado'])
        ->and($conversation->refresh()->lead_id)->toBe($lead->id);
});

it('uses the first open stage when only the pipeline is chosen, and the default pipeline when nothing is', function () {
    $first = zzlInboxConversation();

    $lead = Lead::query()->find(test()->actingAs(getDefaultAdmin(), 'user')
        ->postJson(route('admin.whatsapp.inbox.lead.create', ['id' => $first->id]), ['pipeline_id' => $this->pipelineId])
        ->assertCreated()->json('lead.id'));

    expect($lead->lead_pipeline_stage_id)->toBe($this->stages['Entrada']);

    $second = zzlInboxConversation();
    [$defaultPipeline, $defaultStage] = app(\Webkul\WhatsApp\Support\ConversationLead::class)->defaultPlacement();

    $lead = Lead::query()->find(test()->postJson(route('admin.whatsapp.inbox.lead.create', ['id' => $second->id]))->assertCreated()->json('lead.id'));

    expect($lead->lead_pipeline_id)->toBe($defaultPipeline->id)
        ->and($lead->lead_pipeline_stage_id)->toBe($defaultStage->id);
});

it('rejects a stage outside the chosen pipeline and creates nothing', function () {
    $conversation = zzlInboxConversation();
    $before = Lead::query()->count();

    test()->actingAs(getDefaultAdmin(), 'user');

    test()->postJson(route('admin.whatsapp.inbox.lead.create', ['id' => $conversation->id]), ['pipeline_id' => $this->pipelineId, 'stage_id' => $this->otherStage])
        ->assertStatus(422)->assertJson(['message' => trans('whatsapp::app.inbox.stage_invalid')]);

    test()->postJson(route('admin.whatsapp.inbox.lead.create', ['id' => $conversation->id]), ['pipeline_id' => $this->pipelineId, 'stage_id' => $this->wonStage])->assertStatus(422);
    test()->postJson(route('admin.whatsapp.inbox.lead.create', ['id' => $conversation->id]), ['stage_id' => $this->stages['Entrada']])->assertStatus(422);
    test()->postJson(route('admin.whatsapp.inbox.lead.create', ['id' => $conversation->id]), ['pipeline_id' => 999999999])->assertStatus(422);

    expect(Lead::query()->count())->toBe($before)
        ->and($conversation->refresh()->lead_id)->toBeNull();
});

it('refuses a conversation that already has a lead', function () {
    $conversation = zzlInboxConversation();

    test()->actingAs(getDefaultAdmin(), 'user')
        ->postJson(route('admin.whatsapp.inbox.lead.create', ['id' => $conversation->id]), ['pipeline_id' => $this->pipelineId])
        ->assertCreated();

    test()->postJson(route('admin.whatsapp.inbox.lead.create', ['id' => $conversation->id]), ['pipeline_id' => $this->otherPipelineId])
        ->assertStatus(422)->assertJson(['message' => trans('whatsapp::app.inbox.already_has_lead')]);
});

it('links the lead that already exists for the number instead of creating one in the chosen pipeline', function () {
    $phone = zzlPhone();
    $first = zzlInboxConversation($phone);

    $leadId = test()->actingAs(getDefaultAdmin(), 'user')
        ->postJson(route('admin.whatsapp.inbox.lead.create', ['id' => $first->id]), ['pipeline_id' => $this->pipelineId])
        ->assertCreated()->json('lead.id');

    $again = zzlInboxConversation($phone);

    test()->postJson(route('admin.whatsapp.inbox.lead.create', ['id' => $again->id]), ['pipeline_id' => $this->otherPipelineId, 'stage_id' => $this->otherStage])
        ->assertOk()
        ->assertJson(['created' => false, 'lead' => ['id' => $leadId]]);

    expect($again->refresh()->lead_id)->toBe($leadId)
        ->and(Lead::query()->find($leadId)->lead_pipeline_id)->toBe($this->pipelineId);
});
