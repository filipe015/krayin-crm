<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Webkul\Activity\Models\Activity;
use Webkul\Automations\Models\AutomationRule;
use Webkul\Automations\Models\AutomationRun;
use Webkul\Automations\Support\AutomationContext;
use Webkul\Automations\Support\ConditionEvaluator;
use Webkul\Automations\Support\Engine;
use Webkul\Lead\Models\Lead;
use Webkul\Lead\Models\Pipeline;
use Webkul\WhatsApp\Jobs\SendAgentNotification;

uses(DatabaseTransactions::class);

/**
 * The automations engine: triggers, conditions, actions, idempotency, "once per lead", loop
 * protection, test mode, history, purge and the master switch.
 */
beforeEach(function () {
    config(['automations.enabled' => true, 'automations.max_depth' => 3]);
    app()->setLocale('pt_BR');
    AutomationContext::reset();
});

function auPipeline(string $name, int $open = 3): array
{
    $pipelineId = DB::table('lead_pipelines')->insertGetId(['name' => 'ZZ '.$name.' '.uniqid(), 'type' => 'sales', 'counts_in_reports' => 1, 'rotten_days' => 30, 'is_default' => 0, 'created_at' => now(), 'updated_at' => now()]);
    $stage = fn (string $label, string $code, int $order) => DB::table('lead_pipeline_stages')->insertGetId(['name' => $label, 'code' => $code, 'probability' => 10, 'sort_order' => $order, 'lead_pipeline_id' => $pipelineId]);

    $stages = [];
    for ($i = 1; $i <= $open; $i++) {
        $stages[] = $stage('Etapa '.$i, 'zz-'.$i.'-'.uniqid(), $i);
    }

    Pipeline::forgetCounted();

    return ['pipeline' => $pipelineId, 'open' => $stages, 'won' => $stage('Ganho', 'won', 98), 'lost' => $stage('Perdido', 'lost', 99)];
}

function auUser(): int
{
    return DB::table('users')->insertGetId([
        'name' => 'ZZ Pessoa '.uniqid(), 'email' => 'zz-'.uniqid().'@example.test', 'password' => 'x', 'status' => 1,
        'role_id' => DB::table('roles')->value('id'), 'view_permission' => 'global', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function auLead(array $pipeline, ?int $owner = null, int $stageIndex = 0): Lead
{
    $id = DB::table('leads')->insertGetId([
        'title' => 'ZZ lead '.uniqid(), 'lead_value' => 1000, 'status' => 1, 'user_id' => $owner,
        'lead_pipeline_id' => $pipeline['pipeline'], 'lead_pipeline_stage_id' => $pipeline['open'][$stageIndex], 'created_at' => now(), 'updated_at' => now(),
    ]);

    return Lead::query()->withoutGlobalScopes()->findOrFail($id);
}

function auRule(string $trigger, array $config, array $actions, array $extra = []): AutomationRule
{
    return AutomationRule::query()->create($extra + [
        'name' => 'ZZ regra '.uniqid(), 'status' => 'on', 'trigger_type' => $trigger, 'trigger_config' => $config,
        'conditions' => [], 'actions' => $actions, 'run_once_per_lead' => false,
    ]);
}

function auTag(): int
{
    return DB::table('tags')->insertGetId(['name' => 'zz-tag-'.uniqid(), 'color' => '#000000', 'user_id' => DB::table('users')->min('id'), 'created_at' => now(), 'updated_at' => now()]);
}

function auRuns(AutomationRule $rule)
{
    return AutomationRun::query()->where('rule_id', $rule->id)->orderBy('id')->get();
}

function auMove(Lead $lead, int $stageId): void
{
    $lead->refresh()->update(['lead_pipeline_stage_id' => $stageId]);
}

// ---------------------------------------------------------------- triggers

it('detects a stage change, with the stage it came from, and runs the rule', function () {
    $p = auPipeline('a');
    $tag = auTag();
    $rule = auRule('stage_entered', ['pipeline_id' => $p['pipeline'], 'stage_id' => $p['open'][1], 'from_stage_id' => $p['open'][0]], [['type' => 'add_tag', 'tag_id' => $tag]]);

    $lead = auLead($p);
    auMove($lead, $p['open'][1]);

    expect(auRuns($rule))->toHaveCount(1)
        ->and(auRuns($rule)->first()->status)->toBe('ok')
        ->and(DB::table('lead_tags')->where('lead_id', $lead->id)->where('tag_id', $tag)->exists())->toBeTrue();
});

it('does not fire when the lead came from another stage than the one asked, or nothing changed stage', function () {
    $p = auPipeline('a');
    $rule = auRule('stage_entered', ['pipeline_id' => $p['pipeline'], 'stage_id' => $p['open'][2], 'from_stage_id' => $p['open'][0]], [['type' => 'add_tag', 'tag_id' => auTag()]]);

    $lead = auLead($p, null, 1);
    auMove($lead, $p['open'][2]);
    $lead->refresh()->update(['title' => 'outro título']);

    expect(auRuns($rule))->toHaveCount(0);
});

it('fires on a new lead, filtered by source', function () {
    $p = auPipeline('a');
    $source = DB::table('lead_sources')->insertGetId(['name' => 'ZZ origem '.uniqid(), 'created_at' => now(), 'updated_at' => now()]);
    $other = DB::table('lead_sources')->insertGetId(['name' => 'ZZ outra '.uniqid(), 'created_at' => now(), 'updated_at' => now()]);
    $owner = auUser();
    $rule = auRule('lead_created', ['source_id' => $source], [['type' => 'assign_owner', 'user_id' => $owner]]);

    $match = auLead($p);
    $match->forceFill(['lead_source_id' => $source])->save();
    $miss = auLead($p);
    $miss->forceFill(['lead_source_id' => $other])->save();

    Event::dispatch('lead.create.after', $match->refresh());
    Event::dispatch('lead.create.after', $miss->refresh());

    expect((int) $match->refresh()->user_id)->toBe($owner)
        ->and($miss->refresh()->user_id)->toBeNull()
        ->and(auRuns($rule))->toHaveCount(1);
});

it('fires when a tag is added', function () {
    $p = auPipeline('a');
    $tag = auTag();
    $rule = auRule('tag_added', ['tag_id' => $tag], [['type' => 'move_stage', 'stage_id' => $p['open'][2]]]);

    $lead = auLead($p);
    $lead->tags()->attach($tag);

    expect((int) $lead->refresh()->lead_pipeline_stage_id)->toBe($p['open'][2])
        ->and(auRuns($rule)->first()->trigger)->toBe('tag_added');
});

it('fires when an activity of the chosen type is created, not for other types', function () {
    $p = auPipeline('a');
    $rule = auRule('activity_created', ['activity_type' => 'meeting'], [['type' => 'move_stage', 'stage_id' => $p['open'][1]]]);

    $lead = auLead($p);
    $call = Activity::query()->create(['type' => 'call', 'title' => 'ligação', 'is_done' => 0]);
    $lead->activities()->attach($call->id);

    expect((int) $lead->refresh()->lead_pipeline_stage_id)->toBe($p['open'][0]);

    $meeting = Activity::query()->create(['type' => 'meeting', 'title' => 'reunião', 'is_done' => 0, 'schedule_from' => now()->addDay(), 'schedule_to' => now()->addDay()->addHour()]);
    $lead->activities()->attach($meeting->id);

    expect((int) $lead->refresh()->lead_pipeline_stage_id)->toBe($p['open'][1])
        ->and(auRuns($rule))->toHaveCount(1);
});

// ---------------------------------------------------------------- conditions

it('skips (and records it) when the conditions do not hold', function () {
    $p = auPipeline('a');
    $tag = auTag();
    $needed = auTag();
    $rule = auRule('stage_entered', ['pipeline_id' => $p['pipeline'], 'stage_id' => $p['open'][1]], [['type' => 'add_tag', 'tag_id' => $tag]], [
        'conditions' => [['type' => 'tag', 'tag_id' => $needed], ['type' => 'pipeline', 'pipeline_id' => $p['pipeline']]],
    ]);

    $lead = auLead($p);
    auMove($lead, $p['open'][1]);

    expect(auRuns($rule)->first()->status)->toBe('skipped')
        ->and(DB::table('lead_tags')->where('lead_id', $lead->id)->where('tag_id', $tag)->exists())->toBeFalse();

    $lead->tags()->attach($needed);
    auMove($lead, $p['open'][0]);
    auMove($lead, $p['open'][1]);

    expect(DB::table('lead_tags')->where('lead_id', $lead->id)->where('tag_id', $tag)->exists())->toBeTrue();
});

it('checks owner, source and lead field conditions', function () {
    $p = auPipeline('a');
    $owner = auUser();
    $lead = auLead($p, $owner);
    $lead->forceFill(['title' => 'Empresa X'])->save();

    [$ok] = ConditionEvaluator::evaluate([
        ['type' => 'owner', 'user_id' => $owner],
        ['type' => 'field', 'attribute_code' => 'title', 'value' => ' empresa x '],
        ['type' => 'field', 'attribute_code' => 'lead_value', 'value' => '1000'],
        ['type' => 'stage', 'stage_id' => $p['open'][0]],
    ], $lead->refresh());

    [$notOk] = ConditionEvaluator::evaluate([['type' => 'field', 'attribute_code' => 'title', 'value' => 'Outra']], $lead);

    expect($ok)->toBeTrue()->and($notOk)->toBeFalse();
});

// ---------------------------------------------------------------- actions

it('transfers the same lead to another pipeline, keeps its activities and closing date, and writes a note', function () {
    $closer = auPipeline('closer');
    $onboarding = auPipeline('onboarding');
    $rule = auRule('stage_entered', ['pipeline_id' => $closer['pipeline'], 'stage_id' => $closer['won']], [
        ['type' => 'transfer_pipeline', 'pipeline_id' => $onboarding['pipeline'], 'stage_id' => $onboarding['open'][0]],
    ], ['name' => 'Ganho para onboarding']);

    $lead = auLead($closer);
    $call = Activity::query()->create(['type' => 'call', 'title' => 'ligação antiga', 'is_done' => 1]);
    $lead->activities()->attach($call->id);

    $closedAt = now()->subMinute()->startOfSecond();
    $lead->refresh()->update(['lead_pipeline_stage_id' => $closer['won'], 'closed_at' => $closedAt]);

    $lead->refresh();
    $note = DB::table('activities')->join('lead_activities', 'lead_activities.activity_id', '=', 'activities.id')
        ->where('lead_activities.lead_id', $lead->id)->where('activities.type', 'note')->first();

    expect((int) $lead->lead_pipeline_id)->toBe($onboarding['pipeline'])
        ->and((int) $lead->lead_pipeline_stage_id)->toBe($onboarding['open'][0])
        ->and($lead->closed_at?->format('Y-m-d H:i:s'))->toBe($closedAt->format('Y-m-d H:i:s'))
        ->and(DB::table('lead_activities')->where('lead_id', $lead->id)->where('activity_id', $call->id)->exists())->toBeTrue()
        ->and($note)->not->toBeNull()
        ->and($note->user_id)->toBeNull()
        ->and($note->title)->toContain("automação 'Ganho para onboarding'")
        ->and($note->comment)->toContain('Automação: Ganho para onboarding')
        ->and(Lead::query()->withoutGlobalScopes()->where('lead_pipeline_id', $onboarding['pipeline'])->count())->toBe(1);
});

it('moves stage only inside the current pipeline', function () {
    $a = auPipeline('a');
    $b = auPipeline('b');
    $tag = auTag();
    $rule = auRule('tag_added', ['tag_id' => $tag], [['type' => 'move_stage', 'stage_id' => $b['open'][1]]]);

    $lead = auLead($a);
    $lead->tags()->attach($tag);

    expect((int) $lead->refresh()->lead_pipeline_stage_id)->toBe($a['open'][0])
        ->and(auRuns($rule)->first()->status)->toBe('failed');
});

it('creates a follow-up task for the lead owner or a fixed user, signed by the automation', function () {
    $p = auPipeline('a');
    $owner = auUser();
    $fixed = auUser();
    $tag = auTag();
    auRule('tag_added', ['tag_id' => $tag], [
        ['type' => 'create_task', 'title' => 'Follow-up: {{lead}}', 'due_in_days' => 1, 'assignee' => 'owner'],
        ['type' => 'create_task', 'title' => 'Revisar', 'due_in_days' => 0, 'assignee' => 'fixed', 'user_id' => $fixed],
    ], ['name' => 'Tarefas']);

    $lead = auLead($p, $owner);
    $lead->tags()->attach($tag);

    $tasks = DB::table('activities')->join('lead_activities', 'lead_activities.activity_id', '=', 'activities.id')
        ->where('lead_activities.lead_id', $lead->id)->where('activities.type', 'task')->orderBy('activities.id')->get();

    expect($tasks)->toHaveCount(2)
        ->and($tasks[0]->title)->toBe('Follow-up: '.$lead->title)
        ->and((int) $tasks[0]->user_id)->toBe($owner)
        ->and($tasks[0]->comment)->toBe('Automação: Tarefas')
        ->and(substr($tasks[0]->schedule_from, 0, 10))->toBe(now()->addDay()->toDateString())
        ->and((int) $tasks[1]->user_id)->toBe($fixed);
});

it('queues a WhatsApp notice with the placeholders filled in', function () {
    Bus::fake([SendAgentNotification::class]);
    config(['whatsapp.notify.session' => 'avisos']);

    $p = auPipeline('a');
    $tag = auTag();
    auRule('tag_added', ['tag_id' => $tag], [['type' => 'notify_whatsapp', 'to' => '11 98888-7777, 1203630@g.us', 'message' => 'Lead {{lead}} na etapa {{etapa}}: {{link}}']]);

    $lead = auLead($p);
    $lead->tags()->attach($tag);

    Bus::assertDispatched(SendAgentNotification::class, 2);
    Bus::assertDispatched(SendAgentNotification::class, fn ($job) => $job->session === 'avisos'
        && $job->chatId === '5511988887777@c.us'
        && str_contains($job->text, $lead->title)
        && str_contains($job->text, 'Etapa 1')
        && str_contains($job->text, '/leads/view/'.$lead->id));
});

it('skips the WhatsApp notice silently when the notice number is not configured', function () {
    Bus::fake([SendAgentNotification::class]);
    config(['whatsapp.notify.session' => '']);

    $p = auPipeline('a');
    $tag = auTag();
    $rule = auRule('tag_added', ['tag_id' => $tag], [['type' => 'notify_whatsapp', 'to' => '11988887777', 'message' => 'oi']]);

    auLead($p)->tags()->attach($tag);

    $run = auRuns($rule)->first();

    Bus::assertNotDispatched(SendAgentNotification::class);
    expect($run->status)->toBe('ok')->and($run->steps[0]['status'])->toBe('skipped');
});

it('posts the lead to a webhook with the secret header, once, and records a failure', function () {
    Http::fake([
        'hooks.example.com/ok' => Http::response(['ok' => true], 200),
        'hooks.example.com/fail' => Http::response('erro', 500),
    ]);

    $p = auPipeline('a');
    $tag = auTag();
    $ok = auRule('tag_added', ['tag_id' => $tag], [['type' => 'webhook', 'url' => 'https://hooks.example.com/ok', 'secret' => 's3gr3d0']]);
    $fail = auRule('tag_added', ['tag_id' => $tag], [['type' => 'webhook', 'url' => 'https://hooks.example.com/fail', 'secret' => '']]);

    $lead = auLead($p);
    $lead->tags()->attach($tag);

    Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://hooks.example.com/ok'
        && $request->header('X-Automation-Secret')[0] === 's3gr3d0'
        && $request['lead']['id'] === $lead->id
        && $request['automation']['id'] === $ok->id);
    Http::assertSentCount(2);

    expect(auRuns($ok)->first()->status)->toBe('ok')
        ->and(auRuns($fail)->first()->status)->toBe('failed')
        ->and(auRuns($fail)->first()->error)->toContain('500');
});

it('marks the lead as lost with the chosen reason', function () {
    $p = auPipeline('a');
    $reason = DB::table('lead_lost_reasons')->insertGetId(['lead_pipeline_id' => $p['pipeline'], 'name' => 'Sem orçamento', 'sort_order' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $tag = auTag();
    auRule('tag_added', ['tag_id' => $tag], [['type' => 'mark_lost', 'reason_id' => $reason]]);

    $lead = auLead($p);
    $lead->tags()->attach($tag);
    $lead->refresh();

    expect((int) $lead->lead_pipeline_stage_id)->toBe($p['lost'])
        ->and($lead->lost_reason)->toBe('Sem orçamento')
        ->and((int) $lead->lost_reason_id)->toBe($reason)
        ->and($lead->closed_at)->not->toBeNull();
});

it('stops at the first failed action', function () {
    $p = auPipeline('a');
    $tag = auTag();
    $after = auTag();
    $rule = auRule('tag_added', ['tag_id' => $tag], [
        ['type' => 'assign_owner', 'user_id' => 999999],
        ['type' => 'add_tag', 'tag_id' => $after],
    ]);

    $lead = auLead($p);
    $lead->tags()->attach($tag);

    expect(auRuns($rule)->first()->status)->toBe('failed')
        ->and(auRuns($rule)->first()->steps)->toHaveCount(1)
        ->and(DB::table('lead_tags')->where('lead_id', $lead->id)->where('tag_id', $after)->exists())->toBeFalse();
});

// ---------------------------------------------------------------- engine guarantees

it('runs one event only once (idempotency)', function () {
    $p = auPipeline('a');
    $owner = auUser();
    $rule = auRule('lead_created', [], [['type' => 'create_task', 'title' => 'Ligar', 'due_in_days' => 0, 'assignee' => 'fixed', 'user_id' => $owner]]);
    $lead = auLead($p);

    $engine = app(Engine::class);
    $engine->runFromEvent($rule->id, $lead->id, 'lead_created', ['source_id' => null, 'type_id' => null]);
    $engine->runFromEvent($rule->id, $lead->id, 'lead_created', ['type_id' => null, 'source_id' => null]);

    expect(auRuns($rule))->toHaveCount(1)
        ->and(DB::table('lead_activities')->where('lead_id', $lead->id)->count())->toBe(1);
});

it('runs a "once per lead" rule only once, whatever the event', function () {
    $p = auPipeline('a');
    $tag = auTag();
    $rule = auRule('stage_entered', ['pipeline_id' => $p['pipeline'], 'stage_id' => $p['open'][1]], [['type' => 'create_task', 'title' => 'Tarefa', 'due_in_days' => 1, 'assignee' => 'owner']], ['run_once_per_lead' => true]);

    $lead = auLead($p);
    auMove($lead, $p['open'][1]);
    auMove($lead, $p['open'][0]);
    auMove($lead, $p['open'][1]);

    expect(auRuns($rule)->where('status', 'ok'))->toHaveCount(1)
        ->and(DB::table('lead_activities')->where('lead_id', $lead->id)->count())->toBe(1);

    // Another lead still gets it.
    $other = auLead($p);
    auMove($other, $p['open'][1]);

    expect(auRuns($rule)->where('status', 'ok'))->toHaveCount(2);
});

it('cuts a chain of automations at the maximum depth', function () {
    $p = auPipeline('a', 6);
    $s = $p['open'];
    $rules = [];

    for ($i = 1; $i <= 4; $i++) {
        $rules[$i] = auRule('stage_entered', ['pipeline_id' => $p['pipeline'], 'stage_id' => $s[$i]], [['type' => 'move_stage', 'stage_id' => $s[$i + 1]]]);
    }

    $lead = auLead($p);
    auMove($lead, $s[1]); // a person: rules 1, 2, 3 run (depths 1..3); rule 4 is cut

    expect((int) $lead->refresh()->lead_pipeline_stage_id)->toBe($s[4])
        ->and(auRuns($rules[3])->first()->status)->toBe('ok')
        ->and(auRuns($rules[3])->first()->depth)->toBe(2)
        ->and(auRuns($rules[4])->first()->status)->toBe('skipped')
        ->and(auRuns($rules[4])->first()->error)->toContain('3');
});

it('never fires the same rule twice on the same lead inside one chain', function () {
    $p = auPipeline('a');
    $s = $p['open'];
    $forth = auRule('stage_entered', ['pipeline_id' => $p['pipeline'], 'stage_id' => $s[1]], [['type' => 'move_stage', 'stage_id' => $s[2]]]);
    $back = auRule('stage_entered', ['pipeline_id' => $p['pipeline'], 'stage_id' => $s[2]], [['type' => 'move_stage', 'stage_id' => $s[1]]]);

    $lead = auLead($p);
    auMove($lead, $s[1]);

    expect(auRuns($forth)->where('status', 'ok'))->toHaveCount(1)
        ->and(auRuns($back)->where('status', 'ok'))->toHaveCount(1)
        ->and(auRuns($forth)->where('status', 'skipped'))->toHaveCount(1)
        ->and((int) $lead->refresh()->lead_pipeline_stage_id)->toBe($s[1]);
});

it('in test mode records what it would do and changes nothing', function () {
    $closer = auPipeline('closer');
    $onboarding = auPipeline('onboarding');
    $tag = auTag();
    $rule = auRule('stage_entered', ['pipeline_id' => $closer['pipeline'], 'stage_id' => $closer['won']], [
        ['type' => 'add_tag', 'tag_id' => $tag],
        ['type' => 'transfer_pipeline', 'pipeline_id' => $onboarding['pipeline'], 'stage_id' => $onboarding['open'][0]],
    ], ['status' => 'test', 'run_once_per_lead' => true]);

    $lead = auLead($closer);
    auMove($lead, $closer['won']);

    $run = auRuns($rule)->first();

    expect($run->status)->toBe('dry_run')
        ->and($run->steps)->toHaveCount(2)
        ->and($run->steps[1]['detail'])->toContain('Transferiria')
        ->and((int) $lead->refresh()->lead_pipeline_id)->toBe($closer['pipeline'])
        ->and(DB::table('lead_tags')->where('lead_id', $lead->id)->exists())->toBeFalse()
        ->and(DB::table('lead_activities')->where('lead_id', $lead->id)->count())->toBe(0);
});

it('simulates a rule on a lead without changing it and writes it in the history', function () {
    $p = auPipeline('a');
    $rule = auRule('tag_added', ['tag_id' => auTag()], [['type' => 'move_stage', 'stage_id' => $p['open'][2]]], ['status' => 'off']);
    $lead = auLead($p);

    $run = app(Engine::class)->simulate($rule, $lead);

    expect($run->status)->toBe('dry_run')
        ->and($run->trigger)->toBe('simulation')
        ->and($run->steps[0]['status'])->toBe('ok')
        ->and((int) $lead->refresh()->lead_pipeline_stage_id)->toBe($p['open'][0]);
});

it('records the history of a run with steps and duration', function () {
    $p = auPipeline('a');
    $tag = auTag();
    $rule = auRule('stage_entered', ['pipeline_id' => $p['pipeline'], 'stage_id' => $p['open'][1]], [['type' => 'add_tag', 'tag_id' => $tag]], [
        'conditions' => [['type' => 'pipeline', 'pipeline_id' => $p['pipeline']]],
    ]);

    $lead = auLead($p);
    auMove($lead, $p['open'][1]);

    $run = auRuns($rule)->first();

    expect($run->lead_id)->toBe($lead->id)
        ->and($run->trigger)->toBe('stage_entered')
        ->and($run->depth)->toBe(0)
        ->and($run->duration_ms)->toBeGreaterThanOrEqual(0)
        ->and(array_column($run->steps, 'action'))->toBe(['condition:pipeline', 'add_tag'])
        ->and($run->idempotency_key)->toStartWith('evt:');
});

it('purges history older than the retention, keeping the "once per lead" keys', function () {
    $p = auPipeline('a');
    $rule = auRule('tag_added', ['tag_id' => auTag()], [['type' => 'add_tag', 'tag_id' => auTag()]]);
    $lead = auLead($p);
    $row = fn (string $status, $at, ?string $key = null) => AutomationRun::query()->create(['rule_id' => $rule->id, 'lead_id' => $lead->id, 'trigger' => 'tag_added', 'status' => $status, 'idempotency_key' => $key, 'created_at' => $at]);

    $old = $row('ok', now()->subDays(91), 'evt:'.uniqid());
    $once = $row('ok', now()->subDays(200), 'once:'.$rule->id.':'.$lead->id);
    $recent = $row('failed', now()->subDays(10));

    $this->artisan('automations:purge')->assertSuccessful();

    expect(AutomationRun::query()->find($old->id))->toBeNull()
        ->and(AutomationRun::query()->find($once->id))->not->toBeNull()
        ->and(AutomationRun::query()->find($recent->id))->not->toBeNull();
});

it('does nothing at all while the module is off', function () {
    config(['automations.enabled' => false]);

    $p = auPipeline('a');
    $tag = auTag();
    $rule = auRule('stage_entered', ['pipeline_id' => $p['pipeline'], 'stage_id' => $p['open'][1]], [['type' => 'add_tag', 'tag_id' => $tag]]);

    $lead = auLead($p);
    auMove($lead, $p['open'][1]);
    $lead->tags()->attach(auTag());
    Event::dispatch('lead.create.after', $lead);

    expect(auRuns($rule))->toHaveCount(0)
        ->and(DB::table('lead_tags')->where('lead_id', $lead->id)->where('tag_id', $tag)->exists())->toBeFalse()
        ->and(app(Engine::class)->runFromEvent($rule->id, $lead->id, 'stage_entered', []))->toBeNull();
});

it('ignores rules that are off', function () {
    $p = auPipeline('a');
    $tag = auTag();
    $rule = auRule('stage_entered', ['pipeline_id' => $p['pipeline'], 'stage_id' => $p['open'][1]], [['type' => 'add_tag', 'tag_id' => $tag]], ['status' => 'off']);

    auMove(auLead($p), $p['open'][1]);

    expect(auRuns($rule))->toHaveCount(0);
});

it('never breaks the save of a lead, even when a webhook crashes', function () {
    $p = auPipeline('a');
    auRule('stage_entered', ['pipeline_id' => $p['pipeline'], 'stage_id' => $p['open'][1]], [['type' => 'webhook', 'url' => 'https://hooks.example.com/x', 'secret' => '']]);
    Http::fake(fn () => throw new RuntimeException('rede fora'));

    $lead = auLead($p);
    auMove($lead, $p['open'][1]);

    expect((int) $lead->refresh()->lead_pipeline_stage_id)->toBe($p['open'][1]);
});
