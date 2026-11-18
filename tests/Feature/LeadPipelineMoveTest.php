<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Webkul\Automations\Models\AutomationRule;
use Webkul\Automations\Models\AutomationRun;
use Webkul\Automations\Support\AutomationContext;
use Webkul\Lead\Models\Lead;
use Webkul\Lead\Models\Pipeline;
use Webkul\User\Models\User;

uses(DatabaseTransactions::class);

/**
 * Troca rápida de funil/etapa (menu do card no Kanban e seletor da página do lead):
 * permissão, visibilidade, validação, histórico, stage_entered_at, eventos e Ganho/Perdido.
 */
beforeEach(function () {
    app()->setLocale('pt_BR');
    AutomationContext::reset();
});

function mvPipeline(string $name): array
{
    $pipelineId = DB::table('lead_pipelines')->insertGetId(['name' => 'ZZ '.$name.' '.uniqid(), 'type' => 'sales', 'counts_in_reports' => 1, 'rotten_days' => 30, 'is_default' => 0, 'created_at' => now(), 'updated_at' => now()]);
    $stage = fn (string $label, string $code, int $order) => DB::table('lead_pipeline_stages')->insertGetId(['name' => $label, 'code' => $code, 'probability' => 10, 'sort_order' => $order, 'lead_pipeline_id' => $pipelineId]);

    Pipeline::forgetCounted();

    // Criadas fora de ordem de propósito: a lista sai por sort_order.
    $second = $stage('Proposta '.$name, 'zz-b-'.uniqid(), 2);
    $first = $stage('Novo '.$name, 'zz-a-'.uniqid(), 1);

    return ['pipeline' => $pipelineId, 'open' => [$first, $second], 'won' => $stage('Ganho', 'won', 98), 'lost' => $stage('Perdido', 'lost', 99)];
}

function mvUser(array $permissions = ['leads', 'leads.view', 'leads.edit'], string $view = 'global', array $pipelines = [], bool $restricted = false): User
{
    $roleId = DB::table('roles')->insertGetId([
        'name' => 'ZZ mover '.uniqid(), 'description' => 'teste', 'permission_type' => 'custom',
        'permissions' => json_encode($permissions), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $id = DB::table('users')->insertGetId([
        'name' => 'ZZ Mover', 'email' => 'zz-'.uniqid().'@example.test', 'password' => Hash::make('secret-123'),
        'status' => 1, 'role_id' => $roleId, 'view_permission' => $view, 'pipelines_restricted' => $restricted,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    foreach ($pipelines as $pipelineId) {
        DB::table('user_pipelines')->insert(['user_id' => $id, 'lead_pipeline_id' => $pipelineId]);
    }

    return User::query()->findOrFail($id);
}

function mvLead(array $pipeline, ?int $owner = null, ?int $stageId = null): Lead
{
    $id = DB::table('leads')->insertGetId([
        'title' => 'ZZ negócio '.uniqid(), 'lead_value' => 500, 'status' => 1, 'user_id' => $owner,
        'lead_pipeline_id' => $pipeline['pipeline'], 'lead_pipeline_stage_id' => $stageId ?? $pipeline['open'][0],
        'stage_entered_at' => now()->subDays(3), 'created_at' => now()->subDays(3), 'updated_at' => now()->subDays(3),
    ]);

    return Lead::query()->withoutGlobalScopes()->findOrFail($id);
}

function mvMove(User $user, Lead $lead, array $payload)
{
    return test()->actingAs($user, 'user')->putJson(route('admin.leads.pipeline_move.update', $lead->id), $payload);
}

function mvHistory(Lead $lead)
{
    return DB::table('activities')->join('lead_activities', 'lead_activities.activity_id', '=', 'activities.id')
        ->where('lead_activities.lead_id', $lead->id)->where('activities.type', 'system')
        ->whereNotNull('activities.additional')->orderBy('activities.id')->get(['activities.*'])
        ->map(fn ($row) => (array) $row + ['data' => json_decode($row->additional, true)])
        ->filter(fn ($row) => in_array($row['data']['source'] ?? null, ['quick_move'], true))
        ->values();
}

it('lista só os funis que o usuário pode usar, com etapas por sort_order e os códigos de Ganho/Perdido', function () {
    $mine = mvPipeline('meu');
    $other = mvPipeline('outro');
    $user = mvUser(pipelines: [$mine['pipeline']], restricted: true);
    $lead = mvLead($mine);

    $data = test()->actingAs($user, 'user')->getJson(route('admin.leads.pipeline_move.options', $lead->id))->assertOk()->json('data');

    $pipelines = collect($data['pipelines']);

    expect($pipelines->pluck('id')->all())->toBe([$mine['pipeline']])
        ->and(collect($pipelines->first()['stages'])->pluck('id')->all())->toBe([$mine['open'][0], $mine['open'][1], $mine['won'], $mine['lost']])
        ->and(collect($pipelines->first()['stages'])->pluck('code')->slice(2)->values()->all())->toBe(['won', 'lost'])
        ->and($data['lead']['stage_id'])->toBe($mine['open'][0]);
});

it('muda só a etapa dentro do mesmo funil, carimba stage_entered_at e grava o histórico em nome de quem fez', function () {
    $p = mvPipeline('a');
    $user = mvUser();
    $lead = mvLead($p);
    $before = $lead->stage_entered_at;

    mvMove($user, $lead, ['lead_pipeline_id' => $p['pipeline'], 'lead_pipeline_stage_id' => $p['open'][1]])
        ->assertOk()
        ->assertJson(['changed' => true, 'still_visible' => true]);

    $lead->refresh();
    $history = mvHistory($lead);

    expect((int) $lead->lead_pipeline_stage_id)->toBe($p['open'][1])
        ->and((int) $lead->lead_pipeline_id)->toBe($p['pipeline'])
        ->and($lead->stage_entered_at->gt($before))->toBeTrue()
        ->and($lead->closed_at)->toBeNull()
        ->and($history)->toHaveCount(1)
        ->and($history[0]['data']['old']['label'])->toBe('Novo a')
        ->and($history[0]['data']['new']['label'])->toBe('Proposta a')
        ->and((int) $history[0]['user_id'])->toBe($user->id);
});

it('muda funil e etapa: o mesmo lead vai para o outro funil, com "Funil X → Y" e "Etapa A → B" no histórico', function () {
    $from = mvPipeline('origem');
    $to = mvPipeline('destino');
    $user = mvUser();
    $lead = mvLead($from);

    $response = mvMove($user, $lead, ['lead_pipeline_id' => $to['pipeline'], 'lead_pipeline_stage_id' => $to['open'][1]])->assertOk();

    $lead->refresh();
    $history = mvHistory($lead);

    expect($response->json('move.pipeline_changed'))->toBeTrue()
        ->and((int) $lead->lead_pipeline_id)->toBe($to['pipeline'])
        ->and((int) $lead->lead_pipeline_stage_id)->toBe($to['open'][1])
        ->and(Lead::query()->withoutGlobalScopes()->whereKey($lead->id)->count())->toBe(1)
        ->and($history)->toHaveCount(2)
        ->and($history[0]['data']['old']['value'])->toBe($from['pipeline'])
        ->and($history[0]['data']['new']['value'])->toBe($to['pipeline'])
        ->and($history[1]['data']['old']['label'])->toBe('Novo origem')
        ->and($history[1]['data']['new']['label'])->toBe('Proposta destino')
        // Os valores de atributo de funil/etapa acompanham, sem gerar outra linha no histórico.
        ->and((int) DB::table('attribute_values')->where('entity_type', 'leads')->where('entity_id', $lead->id)
            ->where('attribute_id', DB::table('attributes')->where('entity_type', 'leads')->where('code', 'lead_pipeline_stage_id')->value('id'))
            ->value('integer_value'))->toBe($to['open'][1]);
});

it('recusa uma etapa que não pertence ao funil escolhido e não muda nada', function () {
    $a = mvPipeline('a');
    $b = mvPipeline('b');
    $lead = mvLead($a);

    mvMove(mvUser(), $lead, ['lead_pipeline_id' => $b['pipeline'], 'lead_pipeline_stage_id' => $a['open'][1]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('lead_pipeline_stage_id');

    expect((int) $lead->refresh()->lead_pipeline_stage_id)->toBe($a['open'][0]);
});

it('recusa um lead fora da visão do usuário, um funil que ele não enxerga e quem não pode editar', function () {
    $mine = mvPipeline('meu');
    $other = mvPipeline('outro');
    $owner = mvUser();

    // Visão individual: o lead de outra pessoa não é dele.
    $individual = mvUser(view: 'individual');
    mvMove($individual, mvLead($mine, $owner->id), ['lead_pipeline_id' => $mine['pipeline'], 'lead_pipeline_stage_id' => $mine['open'][1]])->assertForbidden();
    test()->actingAs($individual, 'user')->getJson(route('admin.leads.pipeline_move.options', mvLead($mine, $owner->id)->id))->assertForbidden();

    // Acesso por funil: lead de outro funil não existe para ele, e o funil de destino também não.
    $restricted = mvUser(pipelines: [$mine['pipeline']], restricted: true);
    mvMove($restricted, mvLead($other), ['lead_pipeline_id' => $mine['pipeline'], 'lead_pipeline_stage_id' => $mine['open'][1]])->assertNotFound();

    $lead = mvLead($mine);
    mvMove($restricted, $lead, ['lead_pipeline_id' => $other['pipeline'], 'lead_pipeline_stage_id' => $other['open'][0]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('lead_pipeline_id');

    // Sem a permissão "Negócios → Editar".
    $viewer = mvUser(permissions: ['leads', 'leads.view']);
    mvMove($viewer, $lead, ['lead_pipeline_id' => $mine['pipeline'], 'lead_pipeline_stage_id' => $mine['open'][1]])->assertUnauthorized();

    expect((int) $lead->refresh()->lead_pipeline_id)->toBe($mine['pipeline'])
        ->and((int) $lead->lead_pipeline_stage_id)->toBe($mine['open'][0]);
});

it('não grava nada quando o destino é a etapa atual', function () {
    $p = mvPipeline('a');
    $lead = mvLead($p);
    $before = $lead->stage_entered_at->format('Y-m-d H:i:s');

    Event::fake(['lead.update.before', 'lead.update.after']);

    mvMove(mvUser(), $lead, ['lead_pipeline_id' => $p['pipeline'], 'lead_pipeline_stage_id' => $p['open'][0]])
        ->assertOk()
        ->assertJson(['changed' => false]);

    Event::assertNotDispatched('lead.update.after');

    expect($lead->refresh()->stage_entered_at->format('Y-m-d H:i:s'))->toBe($before)
        ->and(mvHistory($lead))->toHaveCount(0);
});

it('dispara os mesmos eventos da mudança de etapa normal e o gatilho "entrou na etapa" das Automações', function () {
    config(['automations.enabled' => true, 'automations.max_depth' => 3]);

    $from = mvPipeline('origem');
    $to = mvPipeline('destino');
    $tag = DB::table('tags')->insertGetId(['name' => 'zz-tag-'.uniqid(), 'color' => '#000000', 'user_id' => DB::table('users')->min('id'), 'created_at' => now(), 'updated_at' => now()]);

    $rule = AutomationRule::query()->create([
        'name' => 'ZZ regra '.uniqid(), 'status' => 'on', 'trigger_type' => 'stage_entered',
        'trigger_config' => ['pipeline_id' => $to['pipeline'], 'stage_id' => $to['open'][0]],
        'conditions' => [], 'actions' => [['type' => 'add_tag', 'tag_id' => $tag]], 'run_once_per_lead' => false,
    ]);

    $lead = mvLead($from);
    $fired = [];
    Event::listen('lead.update.after', function ($updated) use (&$fired) {
        $fired[] = (int) $updated->id;
    });

    mvMove(mvUser(), $lead, ['lead_pipeline_id' => $to['pipeline'], 'lead_pipeline_stage_id' => $to['open'][0]])->assertOk();

    expect($fired)->toContain($lead->id)
        ->and(AutomationRun::query()->where('rule_id', $rule->id)->where('lead_id', $lead->id)->value('status'))->toBe('ok')
        ->and(DB::table('lead_tags')->where('lead_id', $lead->id)->where('tag_id', $tag)->exists())->toBeTrue()
        ->and(DB::table('lead_events')->where('lead_id', $lead->id)->where('stage_id', $to['open'][0])->exists())->toBeTrue();
});

it('exige confirmação para entrar ou sair de Ganho/Perdido, e Perdido pede o motivo do funil de destino', function () {
    $p = mvPipeline('a');
    $user = mvUser();
    $lead = mvLead($p);

    mvMove($user, $lead, ['lead_pipeline_id' => $p['pipeline'], 'lead_pipeline_stage_id' => $p['won']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('confirm_closed');

    mvMove($user, $lead, ['lead_pipeline_id' => $p['pipeline'], 'lead_pipeline_stage_id' => $p['won'], 'confirm_closed' => true])->assertOk();

    expect($lead->refresh()->closed_at)->not->toBeNull();

    // Saindo de Ganho para outro funil também pede confirmação.
    $other = mvPipeline('b');
    $reason = DB::table('lead_lost_reasons')->insertGetId(['lead_pipeline_id' => $other['pipeline'], 'name' => 'ZZ preço', 'is_active' => 1, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]);

    mvMove($user, $lead, ['lead_pipeline_id' => $other['pipeline'], 'lead_pipeline_stage_id' => $other['open'][0]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('confirm_closed');

    mvMove($user, $lead, ['lead_pipeline_id' => $other['pipeline'], 'lead_pipeline_stage_id' => $other['lost'], 'confirm_closed' => true])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('lost_reason_id');

    mvMove($user, $lead, ['lead_pipeline_id' => $other['pipeline'], 'lead_pipeline_stage_id' => $other['lost'], 'confirm_closed' => true, 'lost_reason_id' => $reason])->assertOk();

    $lead->refresh();

    expect((int) $lead->lead_pipeline_stage_id)->toBe($other['lost'])
        ->and((int) $lead->lost_reason_id)->toBe($reason)
        ->and($lead->lost_reason)->toBe('ZZ preço');
});

it('avisa quando uma automação ligada pode tirar o lead da visão de um usuário com visão limitada', function () {
    config(['automations.enabled' => true]);

    $p = mvPipeline('a');
    $user = mvUser(view: 'individual');
    $lead = mvLead($p, $user->id);

    AutomationRule::query()->create([
        'name' => 'ZZ troca dono '.uniqid(), 'status' => 'on', 'trigger_type' => 'stage_entered',
        'trigger_config' => ['pipeline_id' => $p['pipeline'], 'stage_id' => $p['open'][1]],
        'conditions' => [], 'actions' => [['type' => 'assign_owner', 'user_id' => mvUser()->id]], 'run_once_per_lead' => false,
    ]);

    $risky = test()->actingAs($user, 'user')->getJson(route('admin.leads.pipeline_move.options', $lead->id))->assertOk()->json('data.visibility_risk_stage_ids');

    expect($risky)->toBe([$p['open'][1]]);

    // Depois da mudança a automação troca o dono: a resposta diz que o lead saiu da visão.
    mvMove($user, $lead, ['lead_pipeline_id' => $p['pipeline'], 'lead_pipeline_stage_id' => $p['open'][1]])
        ->assertOk()
        ->assertJson(['changed' => true, 'still_visible' => false]);
});
