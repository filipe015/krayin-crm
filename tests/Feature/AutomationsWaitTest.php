<?php

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Webkul\Automations\Jobs\ResumeAutomationWait;
use Webkul\Automations\Jobs\RunAutomationRule;
use Webkul\Automations\Models\AutomationRule;
use Webkul\Automations\Models\AutomationRun;
use Webkul\Automations\Models\AutomationWait;
use Webkul\Automations\Support\AutomationContext;
use Webkul\Automations\Support\FlowValidator;
use Webkul\Automations\Support\Recipes;
use Webkul\Automations\Support\TimeTriggers;
use Webkul\Automations\Support\WaitScheduler;
use Webkul\Lead\Models\Lead;
use Webkul\Lead\Models\Pipeline;
use Webkul\User\Models\User;

uses(DatabaseTransactions::class);

/**
 * Fase 2 do construtor (tempo): bloco Esperar (pausa, retomada, cancelamentos, limites, Teste/Simular),
 * agendador (reserva atômica, recuperação), gatilhos de tempo (parado na etapa, sem atividade),
 * validação, purge e tela.
 */
beforeEach(function () {
    config(['automations.enabled' => true, 'automations.max_depth' => 3, 'automations.max_nodes' => 30, 'automations.max_wait_days' => 30, 'automations.max_pending_waits' => 10000]);
    app()->setLocale('pt_BR');
    AutomationContext::reset();
    Carbon::setTestNow(Carbon::parse('2026-11-20 10:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

function atwPipeline(int $open = 3): array
{
    $pipelineId = DB::table('lead_pipelines')->insertGetId(['name' => 'ZZ tempo '.uniqid(), 'type' => 'sales', 'counts_in_reports' => 1, 'rotten_days' => 30, 'is_default' => 0, 'created_at' => now(), 'updated_at' => now()]);
    $stage = fn (string $label, string $code, int $order) => DB::table('lead_pipeline_stages')->insertGetId(['name' => $label, 'code' => $code, 'probability' => 10, 'sort_order' => $order, 'lead_pipeline_id' => $pipelineId]);

    $stages = [];
    for ($i = 1; $i <= $open; $i++) {
        $stages[] = $stage('Etapa '.$i, 'zz-'.$i.'-'.uniqid(), $i);
    }

    Pipeline::forgetCounted();

    return ['pipeline' => $pipelineId, 'open' => $stages, 'won' => $stage('Ganho', 'won', 98), 'lost' => $stage('Perdido', 'lost', 99)];
}

function atwTag(): int
{
    return DB::table('tags')->insertGetId(['name' => 'zz-tempo-'.uniqid(), 'color' => '#000000', 'user_id' => DB::table('users')->min('id'), 'created_at' => now(), 'updated_at' => now()]);
}

function atwLead(array $p, int $stageIndex = 0, ?Carbon $enteredAt = null, ?Carbon $createdAt = null): Lead
{
    $id = DB::table('leads')->insertGetId([
        'title' => 'ZZ lead tempo '.uniqid(), 'lead_value' => 100, 'status' => 1, 'user_id' => null,
        'lead_pipeline_id' => $p['pipeline'], 'lead_pipeline_stage_id' => $p['open'][$stageIndex],
        'stage_entered_at' => $enteredAt ?? now(), 'created_at' => $createdAt ?? now(), 'updated_at' => now(),
    ]);

    return Lead::query()->withoutGlobalScopes()->findOrFail($id);
}

function atwHasTag(Lead $lead, int $tag): bool
{
    return DB::table('lead_tags')->where('lead_id', $lead->id)->where('tag_id', $tag)->exists();
}

function atwNode(string $id, string $type, array $config): array
{
    return ['id' => $id, 'type' => $type, 'config' => $config, 'position' => ['x' => 0, 'y' => 0]];
}

function atwEdge(string $source, string $target, ?string $branch = null): array
{
    return ['id' => 'e-'.$source.'-'.$target.'-'.($branch ?? 'x'), 'source' => $source, 'target' => $target, 'branch' => $branch];
}

function atwWait(string $id, int $amount, string $unit = 'hours', bool $cancel = true): array
{
    return atwNode($id, 'wait', ['amount' => $amount, 'unit' => $unit, 'cancel_on_stage_change' => $cancel]);
}

function atwRule(array $nodes, array $edges, string $status = 'on', bool $once = false): AutomationRule
{
    $data = FlowValidator::validateRule(['name' => 'ZZ tempo '.uniqid(), 'status' => $status, 'run_once_per_lead' => $once, 'flow' => ['nodes' => $nodes, 'edges' => $edges]]);

    return AutomationRule::query()->create($data);
}

/** Gatilho "entrou na etapa 2" → Esperar → etiqueta. */
function atwWaitRule(array $p, int $tag, int $hours = 2, bool $cancel = true, string $status = 'on'): AutomationRule
{
    return atwRule([
        atwNode('t', 'trigger', ['trigger_type' => 'stage_entered', 'pipeline_id' => $p['pipeline'], 'stage_id' => $p['open'][1], 'from_stage_id' => null]),
        atwWait('w', $hours, 'hours', $cancel),
        atwNode('a', 'action', ['type' => 'add_tag', 'tag_id' => $tag]),
    ], [atwEdge('t', 'w'), atwEdge('w', 'a')], $status);
}

function atwEnter(Lead $lead, int $stageId): void
{
    $lead->refresh()->update(['lead_pipeline_stage_id' => $stageId, 'stage_entered_at' => now()]);
}

function atwRuns(AutomationRule $rule)
{
    return AutomationRun::query()->where('rule_id', $rule->id)->orderBy('id')->get();
}

function atwWaits(AutomationRule $rule)
{
    return AutomationWait::query()->where('rule_id', $rule->id)->orderBy('id')->get();
}

function atwTick(): void
{
    Artisan::call('automations:tick');
}

function atwAdmin(): User
{
    return User::query()->findOrFail(DB::table('users')->where('role_id', DB::table('roles')->where('permission_type', 'all')->value('id'))->min('id'));
}

function atwStageStaleRule(array $p, int $days = 3, string $status = 'on', bool $once = false, array $extra = []): AutomationRule
{
    return atwRule([
        atwNode('t', 'trigger', ['trigger_type' => 'stage_stale', 'pipeline_id' => $p['pipeline'], 'stage_id' => $p['open'][0], 'amount' => $days, 'unit' => 'days'] + $extra),
        atwNode('a', 'action', ['type' => 'create_task', 'title' => 'Parado: {{lead}}', 'due_in_days' => 0, 'assignee' => 'owner']),
    ], [atwEdge('t', 'a')], $status, $once);
}

function atwTasks(Lead $lead): int
{
    return DB::table('lead_activities')->join('activities', 'activities.id', '=', 'lead_activities.activity_id')
        ->where('lead_activities.lead_id', $lead->id)->where('activities.type', 'task')->count();
}

// ---------------------------------------------------------------- pausing and resuming

it('pauses the flow at a wait block and resumes it when the time comes', function () {
    $p = atwPipeline();
    $tag = atwTag();
    $rule = atwWaitRule($p, $tag, 2);
    $lead = atwLead($p);

    atwEnter($lead, $p['open'][1]);

    $wait = atwWaits($rule)->sole();
    $run = atwRuns($rule)->sole();

    expect($wait->status)->toBe('pending')
        ->and($wait->resume_at->toDateTimeString())->toBe('2026-11-20 12:00:00')
        ->and($wait->run_id)->toBe($run->id)
        ->and($run->status)->toBe('waiting')
        ->and(collect($run->steps)->last()['action'])->toBe('wait')
        ->and(collect($run->steps)->last()['detail'])->toContain('Esperando até 20/11/2026 12:00')
        ->and(atwHasTag($lead, $tag))->toBeFalse();

    // Before the time: nothing.
    Carbon::setTestNow(now()->addMinutes(119));
    atwTick();

    expect($wait->refresh()->status)->toBe('pending')->and(atwHasTag($lead, $tag))->toBeFalse();

    // On time: resumed, the rest of the flow ran, in the SAME run.
    Carbon::setTestNow(now()->addMinutes(2));
    atwTick();

    $run->refresh();

    expect($wait->refresh()->status)->toBe('resumed')
        ->and(atwHasTag($lead, $tag))->toBeTrue()
        ->and($run->status)->toBe('ok')
        ->and(collect($run->steps)->pluck('action')->all())->toBe(['trigger', 'wait', 'wait_resumed', 'add_tag'])
        ->and(atwRuns($rule))->toHaveCount(1);

    // Ticking again never runs it twice.
    Carbon::setTestNow(now()->addDay());
    atwTick();

    expect($run->refresh()->steps)->toHaveCount(4)->and(atwWaits($rule))->toHaveCount(1);
});

it('chains several waits in one flow, after actions and conditions', function () {
    $p = atwPipeline();
    $first = atwTag();
    $second = atwTag();

    $rule = atwRule([
        atwNode('t', 'trigger', ['trigger_type' => 'stage_entered', 'pipeline_id' => $p['pipeline'], 'stage_id' => $p['open'][1], 'from_stage_id' => null]),
        atwWait('w1', 30, 'minutes'),
        atwNode('a1', 'action', ['type' => 'add_tag', 'tag_id' => $first]),
        atwNode('c', 'condition', ['match' => 'all', 'rules' => [['type' => 'tag', 'tag_id' => $first]]]) + ['end' => ['no']],
        atwWait('w2', 1, 'days'),
        atwNode('a2', 'action', ['type' => 'add_tag', 'tag_id' => $second]),
    ], [atwEdge('t', 'w1'), atwEdge('w1', 'a1'), atwEdge('a1', 'c'), atwEdge('c', 'w2', 'yes'), atwEdge('w2', 'a2')]);

    $lead = atwLead($p);
    atwEnter($lead, $p['open'][1]);

    Carbon::setTestNow(now()->addMinutes(31));
    atwTick();

    expect(atwHasTag($lead, $first))->toBeTrue()->and(atwHasTag($lead, $second))->toBeFalse()
        ->and(atwWaits($rule)->pluck('status')->all())->toBe(['resumed', 'pending'])
        ->and(atwRuns($rule)->sole()->status)->toBe('waiting');

    Carbon::setTestNow(now()->addDay()->addMinute());
    atwTick();

    expect(atwHasTag($lead, $second))->toBeTrue()
        ->and(atwWaits($rule)->pluck('status')->all())->toBe(['resumed', 'resumed'])
        ->and(atwRuns($rule)->sole()->status)->toBe('ok');
});

it('claims a due wait atomically: never queued twice, a stale or repeated job does nothing', function () {
    $p = atwPipeline();
    $tag = atwTag();
    $rule = atwWaitRule($p, $tag, 1);
    $lead = atwLead($p);
    atwEnter($lead, $p['open'][1]);

    Carbon::setTestNow(now()->addHours(2));
    Queue::fake();

    expect(WaitScheduler::dispatchDue())->toBe(1)
        ->and(WaitScheduler::dispatchDue())->toBe(0);

    Queue::assertPushed(ResumeAutomationWait::class, 1);

    $wait = atwWaits($rule)->sole();
    expect($wait->status)->toBe('queued')->and($wait->attempts)->toBe(1);

    // A job with another token (an old delivery) does nothing.
    (new ResumeAutomationWait($wait->id, 'not-the-token'))->handle(app(\Webkul\Automations\Support\Engine::class));
    expect($wait->refresh()->status)->toBe('queued')->and(atwHasTag($lead, $tag))->toBeFalse();

    // The right one runs it; the same job delivered again does nothing.
    $job = new ResumeAutomationWait($wait->id, $wait->claim_token);
    $job->handle(app(\Webkul\Automations\Support\Engine::class));
    $job->handle(app(\Webkul\Automations\Support\Engine::class));

    expect($wait->refresh()->status)->toBe('resumed')
        ->and(DB::table('lead_tags')->where('lead_id', $lead->id)->where('tag_id', $tag)->count())->toBe(1)
        ->and(collect(atwRuns($rule)->sole()->steps)->where('action', 'add_tag'))->toHaveCount(1);
});

it('re-queues a wait whose job got lost, with backoff, and gives up after the last attempt', function () {
    $p = atwPipeline();
    $rule = atwWaitRule($p, atwTag(), 1);
    $lead = atwLead($p);
    atwEnter($lead, $p['open'][1]);

    Carbon::setTestNow(now()->addHours(2));
    Queue::fake();
    WaitScheduler::dispatchDue();

    $wait = atwWaits($rule)->sole();

    // The job never ran (queue down): after 10 minutes the tick gives it back, 1 minute later.
    Carbon::setTestNow(now()->addMinutes(11));
    expect(WaitScheduler::recover())->toBe(1);

    $wait->refresh();
    expect($wait->status)->toBe('pending')->and($wait->claim_token)->toBeNull()
        ->and($wait->resume_at->toDateTimeString())->toBe(now()->addMinute()->toDateTimeString());

    // Last attempt lost too: expired, written in the history.
    $wait->forceFill(['status' => 'queued', 'attempts' => AutomationWait::MAX_ATTEMPTS, 'updated_at' => now()->subMinutes(20)])->save();
    WaitScheduler::recover();

    expect($wait->refresh()->status)->toBe('expired')
        ->and(atwRuns($rule)->sole()->status)->toBe('skipped')
        ->and(collect(atwRuns($rule)->sole()->steps)->last()['action'])->toBe('wait_cancelled');
});

// ---------------------------------------------------------------- safety when resuming

it('cancels the wait when the lead changes stage, unless the block says not to', function () {
    $p = atwPipeline();
    $tag = atwTag();
    $cancelling = atwWaitRule($p, $tag, 1, true);
    $keeping = atwWaitRule($p, $tag, 1, false);
    $lead = atwLead($p);

    atwEnter($lead, $p['open'][1]);
    atwEnter($lead, $p['open'][2]);

    Carbon::setTestNow(now()->addHours(2));
    atwTick();

    $run = atwRuns($cancelling)->sole();

    expect(atwWaits($cancelling)->sole()->status)->toBe('cancelled')
        ->and(atwWaits($cancelling)->sole()->reason)->toContain('mudou de etapa')
        ->and($run->status)->toBe('skipped')
        ->and(collect($run->steps)->last()['action'])->toBe('wait_cancelled')
        ->and(atwWaits($keeping)->sole()->status)->toBe('resumed')
        ->and(atwRuns($keeping)->sole()->status)->toBe('ok')
        ->and(atwHasTag($lead, $tag))->toBeTrue();
});

it('cancels the wait when the lead goes to won (a stage change) by default', function () {
    $p = atwPipeline();
    $tag = atwTag();
    $rule = atwWaitRule($p, $tag, 1);
    $lead = atwLead($p);

    atwEnter($lead, $p['open'][1]);
    atwEnter($lead, $p['won']);

    Carbon::setTestNow(now()->addHours(2));
    atwTick();

    expect(atwWaits($rule)->sole()->status)->toBe('cancelled')->and(atwHasTag($lead, $tag))->toBeFalse();
});

it('drops the waits of a deleted lead and runs nothing', function () {
    $p = atwPipeline();
    $tag = atwTag();
    $rule = atwWaitRule($p, $tag, 1);
    $lead = atwLead($p);
    atwEnter($lead, $p['open'][1]);

    expect(atwWaits($rule))->toHaveCount(1);

    DB::table('leads')->where('id', $lead->id)->delete();

    Carbon::setTestNow(now()->addHours(2));
    atwTick();

    expect(AutomationWait::query()->where('lead_id', $lead->id)->whereIn('status', AutomationWait::OPEN)->count())->toBe(0)
        ->and(DB::table('lead_tags')->where('tag_id', $tag)->count())->toBe(0);
});

it('cancels the waits when the rule is turned off, from the list or directly', function () {
    $p = atwPipeline();
    $tag = atwTag();
    $rule = atwWaitRule($p, $tag, 1);
    $other = atwWaitRule($p, $tag, 1);
    $lead = atwLead($p);
    atwEnter($lead, $p['open'][1]);

    // From the list: cancelled at once.
    $this->actingAs(atwAdmin(), 'user')->patchJson(route('admin.automations.status', ['id' => $rule->id]), ['status' => 'off'])
        ->assertOk()->assertJsonPath('cancelled_waits', 1);

    expect(atwWaits($rule)->sole()->status)->toBe('cancelled')
        ->and(atwRuns($rule)->sole()->error)->toContain('desligada');

    // Changed without the screen (e.g. put in Test): the resume checks it.
    $other->update(['status' => 'test']);

    Carbon::setTestNow(now()->addHours(2));
    atwTick();

    expect(atwWaits($other)->sole()->status)->toBe('cancelled')->and(atwHasTag($lead, $tag))->toBeFalse();
});

it('cancels the waits when the flow is edited, but not when only blocks were moved', function () {
    $p = atwPipeline();
    $tag = atwTag();
    $admin = atwAdmin();
    $rule = atwWaitRule($p, $tag, 1);
    $lead = atwLead($p);
    atwEnter($lead, $p['open'][1]);

    $flow = $rule->flow;
    $flow['nodes'][1]['position'] = ['x' => 300, 'y' => 300];
    $flow['nodes'][2]['label'] = 'Só um nome';

    $this->actingAs($admin, 'user')->putJson(route('admin.automations.update', ['id' => $rule->id]), ['name' => $rule->name, 'status' => 'on', 'run_once_per_lead' => false, 'flow' => $flow])
        ->assertOk()->assertJsonPath('cancelled_waits', 0)->assertJsonPath('data.pending_waits', 1);

    $flow['nodes'][1]['config']['amount'] = 5;

    $this->actingAs($admin, 'user')->putJson(route('admin.automations.update', ['id' => $rule->id]), ['name' => $rule->name, 'status' => 'on', 'run_once_per_lead' => false, 'flow' => $flow])
        ->assertOk()->assertJsonPath('cancelled_waits', 1)->assertJsonPath('data.pending_waits', 0);

    expect(atwWaits($rule)->sole()->reason)->toContain('fluxo foi alterado');

    // Safety net: a flow changed without the screen is cancelled when resuming.
    $other = atwWaitRule($p, $tag, 1);
    $second = atwLead($p);
    atwEnter($second, $p['open'][1]);

    $changed = $other->flow;
    $changed['nodes'][2]['config']['tag_id'] = atwTag();
    $other->update(['flow' => $changed]);

    Carbon::setTestNow(now()->addHours(2));
    atwTick();

    expect(atwWaits($other)->sole()->status)->toBe('cancelled')->and(atwHasTag($second, $tag))->toBeFalse();
});

it('checks the conditions after a wait with the lead as it is when it resumes', function () {
    $p = atwPipeline();
    $gate = atwTag();
    $result = atwTag();

    $rule = atwRule([
        atwNode('t', 'trigger', ['trigger_type' => 'stage_entered', 'pipeline_id' => $p['pipeline'], 'stage_id' => $p['open'][1], 'from_stage_id' => null]),
        atwWait('w', 1),
        atwNode('c', 'condition', ['match' => 'all', 'rules' => [['type' => 'tag', 'tag_id' => $gate]]]) + ['end' => ['no']],
        atwNode('a', 'action', ['type' => 'add_tag', 'tag_id' => $result]),
    ], [atwEdge('t', 'w'), atwEdge('w', 'c'), atwEdge('c', 'a', 'yes')]);

    $lead = atwLead($p);
    atwEnter($lead, $p['open'][1]);

    // The tag shows up during the wait.
    DB::table('lead_tags')->insert(['lead_id' => $lead->id, 'tag_id' => $gate]);

    Carbon::setTestNow(now()->addHours(2));
    atwTick();

    expect(atwHasTag($lead, $result))->toBeTrue();
});

// ---------------------------------------------------------------- test mode, simulation, limits

it('never really waits in test mode or in a simulation', function () {
    $p = atwPipeline();
    $tag = atwTag();
    $rule = atwWaitRule($p, $tag, 3, true, 'test');
    $lead = atwLead($p);

    atwEnter($lead, $p['open'][1]);

    $run = atwRuns($rule)->sole();

    expect($run->status)->toBe('dry_run')
        ->and(collect($run->steps)->firstWhere('action', 'wait')['detail'])->toContain('Esperaria 3 horas')
        ->and(collect($run->steps)->pluck('action')->all())->toBe(['trigger', 'wait', 'add_tag'])
        ->and(atwWaits($rule))->toHaveCount(0)
        ->and(atwHasTag($lead, $tag))->toBeFalse();

    $live = atwWaitRule($p, $tag, 3);

    $response = $this->actingAs(atwAdmin(), 'user')->postJson(route('admin.automations.simulate', ['id' => $live->id]), ['lead_id' => $lead->id])->assertOk();

    expect($response->json('data.status'))->toBe('dry_run')
        ->and(collect($response->json('data.steps'))->pluck('action')->all())->toBe(['trigger', 'wait', 'add_tag'])
        ->and(atwWaits($live))->toHaveCount(0)
        ->and(atwHasTag($lead, $tag))->toBeFalse();
});

it('refuses a new wait past the pending limit of the CRM and says so in the history', function () {
    config(['automations.max_pending_waits' => 1]);

    $p = atwPipeline();
    $rule = atwWaitRule($p, atwTag(), 1);
    $first = atwLead($p);
    $second = atwLead($p);

    // Whatever else is pending in this database counts too: start from a clean slate for the test.
    AutomationWait::query()->whereIn('status', AutomationWait::OPEN)->where('rule_id', '!=', $rule->id)->update(['status' => 'cancelled']);

    atwEnter($first, $p['open'][1]);
    atwEnter($second, $p['open'][1]);

    $runs = atwRuns($rule);

    expect(atwWaits($rule))->toHaveCount(1)
        ->and($runs[0]->status)->toBe('waiting')
        ->and($runs[1]->status)->toBe('failed')
        ->and($runs[1]->error)->toContain('Limite de 1 esperas pendentes');
});

// ---------------------------------------------------------------- time triggers

it('fires "parado na etapa" once per stay, and again after re-entering the stage', function () {
    $p = atwPipeline();
    $rule = atwStageStaleRule($p, 3);
    $stale = atwLead($p, 0, now()->subDays(4));
    $fresh = atwLead($p, 0, now()->subDays(1));
    $elsewhere = atwLead($p, 1, now()->subDays(10));

    atwTick();
    atwTick();

    expect(atwRuns($rule)->where('lead_id', $stale->id))->toHaveCount(1)
        ->and(atwRuns($rule)->where('lead_id', $stale->id)->first()->status)->toBe('ok')
        ->and(atwRuns($rule)->where('lead_id', $stale->id)->first()->trigger)->toBe('stage_stale')
        ->and(atwTasks($stale))->toBe(1)
        ->and(atwRuns($rule)->whereIn('lead_id', [$fresh->id, $elsewhere->id]))->toHaveCount(0);

    // Leaves and comes back: a new stay, fires again 3 days later.
    atwEnter($stale, $p['open'][1]);
    atwEnter($stale, $p['open'][0]);

    Carbon::setTestNow(now()->addDays(3)->addMinute());
    atwTick();
    atwTick();

    expect(atwRuns($rule)->where('lead_id', $stale->id))->toHaveCount(2)
        ->and(atwTasks($stale))->toBe(2)
        ->and(atwRuns($rule)->where('lead_id', $fresh->id))->toHaveCount(1);
});

it('does not fire "parado na etapa" for won/lost leads, nor when the lead moved before the job ran', function () {
    $p = atwPipeline();
    $rule = atwStageStaleRule($p, 3);
    $lead = atwLead($p, 0, now()->subDays(4));

    Queue::fake();
    atwTick();

    Queue::assertPushed(RunAutomationRule::class, fn ($job) => $job->leadId === $lead->id);

    // Moved between the tick and the job: nothing runs.
    atwEnter($lead, $p['open'][1]);

    $job = collect(Queue::pushed(RunAutomationRule::class))->first(fn ($job) => $job->leadId === $lead->id);
    $job->handle(app(\Webkul\Automations\Support\Engine::class));

    expect(atwRuns($rule))->toHaveCount(0);

    $all = atwRule([
        atwNode('t', 'trigger', ['trigger_type' => 'stage_stale', 'pipeline_id' => $p['pipeline'], 'stage_id' => null, 'amount' => 1, 'unit' => 'days']),
        atwNode('a', 'action', ['type' => 'add_tag', 'tag_id' => atwTag()]),
    ], [atwEdge('t', 'a')]);

    $won = atwLead($p, 0, now()->subDays(5));
    DB::table('leads')->where('id', $won->id)->update(['lead_pipeline_stage_id' => $p['won']]);

    expect(TimeTriggers::candidates($all, 100, false)->pluck('lead_id')->all())->not->toContain($won->id);
});

it('fires "sem atividade" once per silence, and again after a new contact', function () {
    $p = atwPipeline();
    $rule = atwRule([
        atwNode('t', 'trigger', ['trigger_type' => 'no_activity', 'pipeline_id' => $p['pipeline'], 'stage_id' => null, 'amount' => 24, 'unit' => 'hours']),
        atwNode('a', 'action', ['type' => 'create_task', 'title' => 'Sem contato: {{lead}}', 'due_in_days' => 0, 'assignee' => 'owner']),
    ], [atwEdge('t', 'a')]);

    $quiet = atwLead($p, 0, null, now()->subDays(2));
    $talking = atwLead($p, 0, null, now()->subDays(2));
    $new = atwLead($p, 0, null, now()->subHours(3));

    $call = DB::table('activities')->insertGetId(['title' => 'Ligação', 'type' => 'call', 'is_done' => 1, 'user_id' => DB::table('users')->min('id'), 'created_at' => now()->subHours(2), 'updated_at' => now()]);
    DB::table('lead_activities')->insert(['lead_id' => $talking->id, 'activity_id' => $call]);

    atwTick();
    atwTick();

    // The task the automation itself created does not count as contact (no loop).
    expect(atwRuns($rule)->where('lead_id', $quiet->id))->toHaveCount(1)
        ->and(atwTasks($quiet))->toBe(1)
        ->and(atwRuns($rule)->whereIn('lead_id', [$talking->id, $new->id]))->toHaveCount(0);

    // A new contact opens a new silence; 24h later it fires again.
    Carbon::setTestNow(now()->addHour());
    $note = DB::table('activities')->insertGetId(['title' => 'Respondeu', 'type' => 'note', 'is_done' => 1, 'user_id' => DB::table('users')->min('id'), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('lead_activities')->insert(['lead_id' => $quiet->id, 'activity_id' => $note]);

    Carbon::setTestNow(now()->addHours(12));
    atwTick();

    expect(atwRuns($rule)->where('lead_id', $quiet->id))->toHaveCount(1);

    Carbon::setTestNow(now()->addHours(13));
    atwTick();
    atwTick();

    expect(atwRuns($rule)->where('lead_id', $quiet->id))->toHaveCount(2)
        ->and(atwRuns($rule)->where('lead_id', $talking->id))->toHaveCount(1);
});

it('fires time triggers in test mode without blocking the real firing later', function () {
    $p = atwPipeline();
    $rule = atwStageStaleRule($p, 3, 'test');
    $lead = atwLead($p, 0, now()->subDays(4));

    atwTick();

    expect(atwRuns($rule)->sole()->status)->toBe('dry_run')->and(atwTasks($lead))->toBe(0);

    $rule->update(['status' => 'on']);
    atwTick();

    expect(atwRuns($rule)->pluck('status')->all())->toBe(['dry_run', 'ok'])->and(atwTasks($lead))->toBe(1);
});

it('simulates a time trigger on a chosen lead, saying whether it would fire now', function () {
    $p = atwPipeline();
    $rule = atwStageStaleRule($p, 3);
    $lead = atwLead($p, 0, now()->subDays(1));

    $response = $this->actingAs(atwAdmin(), 'user')->postJson(route('admin.automations.simulate', ['id' => $rule->id]), ['lead_id' => $lead->id])->assertOk();

    $check = collect($response->json('data.steps'))->firstWhere('action', 'time_check');

    expect($response->json('data.status'))->toBe('dry_run')
        ->and($check['status'])->toBe('skipped')
        ->and($check['detail'])->toContain('ainda NÃO dispararia')
        ->and(atwTasks($lead))->toBe(0);
});

it('does nothing in the tick while the module is off', function () {
    $p = atwPipeline();
    $rule = atwStageStaleRule($p, 3);
    atwLead($p, 0, now()->subDays(4));

    config(['automations.enabled' => false]);
    atwTick();

    expect(atwRuns($rule))->toHaveCount(0);
});

// ---------------------------------------------------------------- validation

it('validates the wait block and the time triggers', function () {
    $p = atwPipeline();
    $tag = atwTag();
    $trigger = atwNode('t', 'trigger', ['trigger_type' => 'stage_entered', 'pipeline_id' => $p['pipeline'], 'stage_id' => $p['open'][1], 'from_stage_id' => null]);
    $action = atwNode('a', 'action', ['type' => 'add_tag', 'tag_id' => $tag]);

    $check = fn (array $nodes, array $edges) => FlowValidator::sanitize(['nodes' => $nodes, 'edges' => $edges]);

    $ok = $check([$trigger, atwWait('w', 2), $action], [atwEdge('t', 'w'), atwEdge('w', 'a')]);
    expect($ok['errors'])->toBe([])->and($ok['general'])->toBe([])
        ->and($ok['flow']['nodes'][1]['config'])->toBe(['amount' => 2, 'unit' => 'hours', 'cancel_on_stage_change' => true]);

    $zero = $check([$trigger, atwWait('w', 0), $action], [atwEdge('t', 'w'), atwEdge('w', 'a')]);
    $tooLong = $check([$trigger, atwWait('w', 31, 'days'), $action], [atwEdge('t', 'w'), atwEdge('w', 'a')]);
    $noExit = $check([$trigger, $action, atwWait('w', 2)], [atwEdge('t', 'a'), atwEdge('a', 'w')]);
    $cycle = $check([$trigger, atwWait('w', 2), $action], [atwEdge('t', 'w'), atwEdge('w', 'a'), atwEdge('a', 'w')]);

    expect($zero['errors']['w'])->toContain(trans('automations::app.validation.amount'))
        ->and($tooLong['errors']['w'])->toContain(trans('automations::app.validation.amount_max', ['days' => 30]))
        ->and($noExit['errors']['w'])->toContain(trans('automations::app.flow.errors.wait_exit'))
        ->and($cycle['errors']['w'])->toContain(trans('automations::app.flow.errors.wait_cycle'))
        ->and($cycle['general'])->toContain(trans('automations::app.flow.errors.cycle'));

    // Wait blocks count for the block limit.
    config(['automations.max_nodes' => 3]);
    expect(fn () => $check([$trigger, atwWait('w', 2), atwWait('w2', 2), $action], []))->toThrow(ValidationException::class);
    config(['automations.max_nodes' => 30]);

    // Time triggers: amount required, only open stages, at most 365 days.
    $stale = fn (array $config) => $check([atwNode('t', 'trigger', ['trigger_type' => 'stage_stale'] + $config), $action], [atwEdge('t', 'a')])['errors']['t'] ?? [];

    expect($stale(['pipeline_id' => $p['pipeline'], 'stage_id' => $p['open'][0], 'amount' => 3, 'unit' => 'days']))->toBe([])
        ->and($stale(['amount' => 0, 'unit' => 'days']))->toContain(trans('automations::app.validation.amount'))
        ->and($stale(['amount' => 400, 'unit' => 'days']))->toContain(trans('automations::app.validation.amount_max', ['days' => 365]))
        ->and($stale(['pipeline_id' => $p['pipeline'], 'stage_id' => $p['won'], 'amount' => 3, 'unit' => 'days']))->toContain(trans('automations::app.validation.time_stage'));

    // A draft may keep problems; turning it on may not.
    expect(fn () => atwRule([$trigger, atwWait('w', 0), $action], [atwEdge('t', 'w'), atwEdge('w', 'a')]))->toThrow(ValidationException::class);
    expect(atwRule([$trigger, atwWait('w', 0), $action], [atwEdge('t', 'w'), atwEdge('w', 'a')], 'off')->status)->toBe('off');
});

it('ships the two time recipes as valid flows', function () {
    $pipelines = collect([['id' => 1, 'name' => 'Pré-Vendas', 'stages' => []]]);
    $recipes = collect(Recipes::all($pipelines))->keyBy('key');

    expect($recipes->keys()->all())->toContain('no_reply_follow_up', 'stage_stale_alert');

    $wait = FlowValidator::sanitize($recipes['no_reply_follow_up']['rule']['flow']);
    $stale = FlowValidator::sanitize($recipes['stage_stale_alert']['rule']['flow']);

    expect(collect($wait['flow']['nodes'])->pluck('type')->all())->toBe(['trigger', 'wait', 'action', 'action'])
        ->and($wait['general'])->toBe([])
        ->and($stale['flow']['nodes'][0]['config'])->toMatchArray(['trigger_type' => 'stage_stale', 'amount' => 3, 'unit' => 'days'])
        ->and($recipes['stage_stale_alert']['rule']['run_once_per_lead'])->toBeFalse();
});

// ---------------------------------------------------------------- purge and screen

it('purges finished waits after the retention and keeps the pending ones', function () {
    $p = atwPipeline();
    $rule = atwWaitRule($p, atwTag(), 1);
    $lead = atwLead($p);
    atwEnter($lead, $p['open'][1]);
    atwEnter(atwLead($p), $p['open'][1]);

    [$old, $pending] = atwWaits($rule)->all();
    $old->forceFill(['status' => 'cancelled'])->save();
    DB::table('automation_waits')->where('id', $old->id)->update(['updated_at' => now()->subDays(31)]);
    DB::table('automation_waits')->where('id', $pending->id)->update(['updated_at' => now()->subDays(31)]);

    Artisan::call('automations:purge');

    expect(AutomationWait::query()->find($old->id))->toBeNull()
        ->and(AutomationWait::query()->find($pending->id))->not->toBeNull()
        ->and(AutomationRun::query()->find($pending->run_id))->not->toBeNull();
});

it('shows the pending waits per flow and cancels them with the edit permission only', function () {
    $p = atwPipeline();
    $rule = atwWaitRule($p, atwTag(), 1);
    atwEnter(atwLead($p), $p['open'][1]);
    atwEnter(atwLead($p), $p['open'][1]);

    $this->actingAs(atwAdmin(), 'user')->get(route('admin.automations.index'))->assertOk()
        ->assertSee('no_reply_follow_up', false)
        ->assertSee('\\u0022pending_waits\\u0022:2', false);

    $roleId = DB::table('roles')->insertGetId(['name' => 'ZZ só ver', 'description' => 'x', 'permission_type' => 'custom', 'permissions' => json_encode(['settings', 'settings.automation', 'settings.automation.automations']), 'created_at' => now(), 'updated_at' => now()]);
    $viewer = User::query()->findOrFail(DB::table('users')->insertGetId(['name' => 'ZZ', 'email' => 'zzw-'.uniqid().'@example.test', 'password' => 'x', 'status' => 1, 'role_id' => $roleId, 'view_permission' => 'global', 'created_at' => now(), 'updated_at' => now()]));

    $this->actingAs($viewer, 'user')->postJson(route('admin.automations.waits.cancel', ['id' => $rule->id]))->assertStatus(401);
    expect(atwWaits($rule)->where('status', 'pending'))->toHaveCount(2);

    $this->actingAs(atwAdmin(), 'user')->postJson(route('admin.automations.waits.cancel', ['id' => $rule->id]))
        ->assertOk()->assertJsonPath('cancelled_waits', 2)->assertJsonPath('data.pending_waits', 0);

    expect(atwWaits($rule)->pluck('status')->unique()->all())->toBe(['cancelled'])
        ->and(atwRuns($rule)->pluck('status')->unique()->all())->toBe(['skipped']);
});

it('lists waiting runs in the history with the "Esperando até" step', function () {
    $p = atwPipeline();
    $rule = atwWaitRule($p, atwTag(), 1);
    $lead = atwLead($p);
    atwEnter($lead, $p['open'][1]);

    $response = $this->actingAs(atwAdmin(), 'user')->getJson(route('admin.automations.runs', ['rule_id' => $rule->id, 'status' => 'waiting']))->assertOk();

    expect($response->json('data.0.status'))->toBe('waiting')
        ->and(collect($response->json('data.0.steps'))->last()['detail'])->toContain('Esperando até');
});
