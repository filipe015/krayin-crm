<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Webkul\Automations\Models\AutomationRule;
use Webkul\Automations\Models\AutomationRun;
use Webkul\Automations\Support\AutomationContext;
use Webkul\Automations\Support\Engine;
use Webkul\Automations\Support\FlowConverter;
use Webkul\Automations\Support\FlowValidator;
use Webkul\Lead\Models\Lead;
use Webkul\Lead\Models\Pipeline;
use Webkul\User\Models\User;

uses(DatabaseTransactions::class);

/**
 * Construtor visual (fase 1): o motor de grafo (caminho, bifurcação, loop, idempotência, Teste), a
 * validação do grafo, a conversão das regras antigas (com equivalência) e a tela/API.
 */
beforeEach(function () {
    config(['automations.enabled' => true, 'automations.max_depth' => 3, 'automations.max_nodes' => 30]);
    app()->setLocale('pt_BR');
    AutomationContext::reset();
});

function aflPipeline(int $open = 3): array
{
    $pipelineId = DB::table('lead_pipelines')->insertGetId(['name' => 'ZZ fluxo '.uniqid(), 'type' => 'sales', 'counts_in_reports' => 1, 'rotten_days' => 30, 'is_default' => 0, 'created_at' => now(), 'updated_at' => now()]);
    $stage = fn (string $label, string $code, int $order) => DB::table('lead_pipeline_stages')->insertGetId(['name' => $label, 'code' => $code, 'probability' => 10, 'sort_order' => $order, 'lead_pipeline_id' => $pipelineId]);

    $stages = [];
    for ($i = 1; $i <= $open; $i++) {
        $stages[] = $stage('Etapa '.$i, 'zz-'.$i.'-'.uniqid(), $i);
    }

    Pipeline::forgetCounted();

    return ['pipeline' => $pipelineId, 'open' => $stages, 'won' => $stage('Ganho', 'won', 98), 'lost' => $stage('Perdido', 'lost', 99)];
}

function aflUser(): int
{
    return DB::table('users')->insertGetId([
        'name' => 'ZZ Fluxo '.uniqid(), 'email' => 'zzf-'.uniqid().'@example.test', 'password' => 'x', 'status' => 1,
        'role_id' => DB::table('roles')->value('id'), 'view_permission' => 'global', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function aflTag(): int
{
    return DB::table('tags')->insertGetId(['name' => 'zz-fluxo-'.uniqid(), 'color' => '#000000', 'user_id' => DB::table('users')->min('id'), 'created_at' => now(), 'updated_at' => now()]);
}

function aflLead(array $pipeline, int $value = 1000, ?int $owner = null): Lead
{
    $id = DB::table('leads')->insertGetId([
        'title' => 'ZZ lead fluxo '.uniqid(), 'lead_value' => $value, 'status' => 1, 'user_id' => $owner,
        'lead_pipeline_id' => $pipeline['pipeline'], 'lead_pipeline_stage_id' => $pipeline['open'][0], 'created_at' => now(), 'updated_at' => now(),
    ]);

    return Lead::query()->withoutGlobalScopes()->findOrFail($id);
}

function aflHasTag(Lead $lead, int $tag): bool
{
    return DB::table('lead_tags')->where('lead_id', $lead->id)->where('tag_id', $tag)->exists();
}

function aflMove(Lead $lead, int $stageId): void
{
    $lead->refresh()->update(['lead_pipeline_stage_id' => $stageId]);
}

function aflNode(string $id, string $type, array $config, array $extra = []): array
{
    return ['id' => $id, 'type' => $type, 'config' => $config, 'position' => ['x' => 0, 'y' => 0]] + $extra;
}

function aflEdge(string $source, string $target, ?string $branch = null): array
{
    return ['id' => 'e-'.$source.'-'.$target.'-'.($branch ?? 'x'), 'source' => $source, 'target' => $target, 'branch' => $branch];
}

/** A flow rule, validated (strict) the way the screen stores it. */
function aflRule(array $nodes, array $edges, string $status = 'on', bool $once = false): AutomationRule
{
    $data = FlowValidator::validateRule(['name' => 'ZZ fluxo '.uniqid(), 'status' => $status, 'run_once_per_lead' => $once, 'flow' => ['nodes' => $nodes, 'edges' => $edges]]);

    return AutomationRule::query()->create($data);
}

function aflStageTrigger(array $p, int $stageIndex): array
{
    return aflNode('t', 'trigger', ['trigger_type' => 'stage_entered', 'pipeline_id' => $p['pipeline'], 'stage_id' => $p['open'][$stageIndex], 'from_stage_id' => null]);
}

function aflRuns(AutomationRule $rule)
{
    return AutomationRun::query()->where('rule_id', $rule->id)->orderBy('id')->get();
}

function aflAdmin(): User
{
    return User::query()->findOrFail(DB::table('users')->where('role_id', DB::table('roles')->where('permission_type', 'all')->value('id'))->min('id'));
}

// ---------------------------------------------------------------- engine

it('walks the flow from the trigger and records the path block by block', function () {
    $p = aflPipeline();
    $tag = aflTag();
    $owner = aflUser();

    $rule = aflRule([
        aflStageTrigger($p, 1),
        aflNode('a1', 'action', ['type' => 'add_tag', 'tag_id' => $tag]),
        aflNode('a2', 'action', ['type' => 'assign_owner', 'user_id' => $owner]),
    ], [aflEdge('t', 'a1'), aflEdge('a1', 'a2')]);

    $lead = aflLead($p);
    aflMove($lead, $p['open'][1]);

    $run = aflRuns($rule)->first();

    expect($run->status)->toBe('ok')
        ->and(collect($run->steps)->pluck('node')->all())->toBe(['t', 'a1', 'a2'])
        ->and(aflHasTag($lead, $tag))->toBeTrue()
        ->and((int) $lead->refresh()->user_id)->toBe($owner);
});

it('follows the "Sim" or the "Não" branch of a condition', function () {
    $p = aflPipeline();
    $big = aflTag();
    $small = aflTag();

    $rule = aflRule([
        aflStageTrigger($p, 1),
        aflNode('c', 'condition', ['match' => 'all', 'rules' => [['type' => 'lead_value', 'operator' => 'gte', 'value' => 500]]]),
        aflNode('yes', 'action', ['type' => 'add_tag', 'tag_id' => $big]),
        aflNode('no', 'action', ['type' => 'add_tag', 'tag_id' => $small]),
    ], [aflEdge('t', 'c'), aflEdge('c', 'yes', 'yes'), aflEdge('c', 'no', 'no')]);

    $rich = aflLead($p, 1000);
    $poor = aflLead($p, 100);
    aflMove($rich, $p['open'][1]);
    aflMove($poor, $p['open'][1]);

    [$first, $second] = aflRuns($rule)->all();

    expect(aflHasTag($rich, $big))->toBeTrue()->and(aflHasTag($rich, $small))->toBeFalse()
        ->and(aflHasTag($poor, $small))->toBeTrue()->and(aflHasTag($poor, $big))->toBeFalse()
        ->and(collect($first->steps)->firstWhere('action', 'branch')['branch'])->toBe('yes')
        ->and(collect($second->steps)->firstWhere('action', 'branch')['branch'])->toBe('no')
        ->and(collect($second->steps)->pluck('node')->unique()->values()->all())->toBe(['t', 'c', 'no']);
});

it('records a skipped run when the path ends before any action (empty branch)', function () {
    $p = aflPipeline();
    $tag = aflTag();
    $needed = aflTag();

    $rule = aflRule([
        aflStageTrigger($p, 1),
        aflNode('c', 'condition', ['match' => 'all', 'rules' => [['type' => 'tag', 'tag_id' => $needed]]], ['end' => ['no']]),
        aflNode('a', 'action', ['type' => 'add_tag', 'tag_id' => $tag]),
    ], [aflEdge('t', 'c'), aflEdge('c', 'a', 'yes')], 'on', true);

    $lead = aflLead($p);
    aflMove($lead, $p['open'][1]);

    $run = aflRuns($rule)->first();

    expect($run->status)->toBe('skipped')
        ->and($run->idempotency_key)->toStartWith('skip:')
        ->and($run->error)->toBe(trans('automations::app.engine.conditions_not_met'))
        ->and(collect($run->steps)->last()['action'])->toBe('end')
        ->and(aflHasTag($lead, $tag))->toBeFalse();

    // "Once per lead" was not used up by the skip.
    $lead->tags()->attach($needed);
    aflMove($lead, $p['open'][0]);
    aflMove($lead, $p['open'][1]);

    expect(aflHasTag($lead, $tag))->toBeTrue();
});

it('checks conditions placed after actions against the updated lead', function () {
    $p = aflPipeline();
    $tag = aflTag();
    $owner = aflUser();

    $rule = aflRule([
        aflStageTrigger($p, 1),
        aflNode('a1', 'action', ['type' => 'add_tag', 'tag_id' => $tag]),
        aflNode('c', 'condition', ['match' => 'any', 'rules' => [['type' => 'tag', 'tag_id' => $tag], ['type' => 'owner', 'user_id' => $owner]]], ['end' => ['no']]),
        aflNode('a2', 'action', ['type' => 'assign_owner', 'user_id' => $owner]),
    ], [aflEdge('t', 'a1'), aflEdge('a1', 'c'), aflEdge('c', 'a2', 'yes')]);

    $lead = aflLead($p);
    aflMove($lead, $p['open'][1]);

    expect(aflRuns($rule)->first()->status)->toBe('ok')->and((int) $lead->refresh()->user_id)->toBe($owner);
});

it('stops the walk at the first failed action', function () {
    $p = aflPipeline();
    $other = aflPipeline();
    $tag = aflTag();

    $rule = aflRule([
        aflStageTrigger($p, 1),
        aflNode('a1', 'action', ['type' => 'move_stage', 'stage_id' => $other['open'][0]]),
        aflNode('a2', 'action', ['type' => 'add_tag', 'tag_id' => $tag]),
    ], [aflEdge('t', 'a1'), aflEdge('a1', 'a2')]);

    $lead = aflLead($p);
    aflMove($lead, $p['open'][1]);

    $run = aflRuns($rule)->first();

    expect($run->status)->toBe('failed')
        ->and(collect($run->steps)->pluck('node')->all())->toBe(['t', 'a1'])
        ->and(aflHasTag($lead, $tag))->toBeFalse();
});

it('runs one event only once and keeps the trigger block id in the key (idempotency)', function () {
    $p = aflPipeline();
    $owner = aflUser();

    $rule = aflRule([
        aflNode('t', 'trigger', ['trigger_type' => 'lead_created', 'source_id' => null, 'type_id' => null]),
        aflNode('a', 'action', ['type' => 'create_task', 'title' => 'Ligar', 'due_in_days' => 0, 'assignee' => 'fixed', 'user_id' => $owner]),
    ], [aflEdge('t', 'a')]);

    $lead = aflLead($p);
    $engine = app(Engine::class);
    $engine->runFromEvent($rule->id, $lead->id, 'lead_created', ['source_id' => null, 'type_id' => null]);
    $engine->runFromEvent($rule->id, $lead->id, 'lead_created', ['type_id' => null, 'source_id' => null]);

    $legacyHash = sha1(implode('|', [$rule->id, $lead->id, 'lead_created', json_encode(['source_id' => null, 'type_id' => null])]));

    expect(aflRuns($rule))->toHaveCount(1)
        ->and(aflRuns($rule)->first()->idempotency_key)->toBe('evt:'.sha1($legacyHash.'|node:t'))
        ->and(DB::table('lead_activities')->where('lead_id', $lead->id)->count())->toBe(1);
});

it('keeps the loop protection: a flow never fires twice on the same lead in one chain', function () {
    $p = aflPipeline();
    $s = $p['open'];

    $forth = aflRule([aflStageTrigger($p, 1), aflNode('a', 'action', ['type' => 'move_stage', 'stage_id' => $s[2]])], [aflEdge('t', 'a')]);
    $back = aflRule([aflStageTrigger($p, 2), aflNode('a', 'action', ['type' => 'move_stage', 'stage_id' => $s[1]])], [aflEdge('t', 'a')]);

    $lead = aflLead($p);
    aflMove($lead, $s[1]);

    expect(aflRuns($forth)->where('status', 'ok'))->toHaveCount(1)
        ->and(aflRuns($back)->where('status', 'ok'))->toHaveCount(1)
        ->and(aflRuns($forth)->where('status', 'skipped')->first()->steps[0]['action'])->toBe('loop_protection')
        ->and((int) $lead->refresh()->lead_pipeline_stage_id)->toBe($s[1]);
});

it('in test mode walks the flow, records the path and changes nothing', function () {
    $p = aflPipeline();
    $tag = aflTag();

    $rule = aflRule([
        aflStageTrigger($p, 1),
        aflNode('c', 'condition', ['match' => 'all', 'rules' => [['type' => 'pipeline', 'pipeline_id' => $p['pipeline']]]], ['end' => ['no']]),
        aflNode('a', 'action', ['type' => 'add_tag', 'tag_id' => $tag]),
    ], [aflEdge('t', 'c'), aflEdge('c', 'a', 'yes')], 'test');

    $lead = aflLead($p);
    aflMove($lead, $p['open'][1]);

    $run = aflRuns($rule)->first();

    expect($run->status)->toBe('dry_run')
        ->and($run->idempotency_key)->toStartWith('dry:')
        ->and(collect($run->steps)->pluck('node')->unique()->values()->all())->toBe(['t', 'c', 'a'])
        ->and(collect($run->steps)->last()['detail'])->toContain('Adicionaria')
        ->and(aflHasTag($lead, $tag))->toBeFalse();

    $simulated = app(Engine::class)->simulate($rule, $lead);

    expect($simulated->status)->toBe('dry_run')->and($simulated->trigger)->toBe('simulation')->and(aflHasTag($lead, $tag))->toBeFalse();
});

// ---------------------------------------------------------------- validation

function aflValid(array $p, int $tag): array
{
    return [
        'nodes' => [
            aflStageTrigger($p, 1),
            aflNode('c', 'condition', ['match' => 'all', 'rules' => [['type' => 'tag', 'tag_id' => $tag]]], ['end' => ['no']]),
            aflNode('a', 'action', ['type' => 'add_tag', 'tag_id' => $tag]),
        ],
        'edges' => [aflEdge('t', 'c'), aflEdge('c', 'a', 'yes')],
    ];
}

it('accepts a valid flow and drops unknown keys', function () {
    $p = aflPipeline();
    $tag = aflTag();
    $flow = aflValid($p, $tag);
    $flow['nodes'][2]['evil'] = '<script>';
    $flow['nodes'][2]['config']['extra'] = 'x';
    $flow['nodes'][2]['label'] = '<b>Etiquetar</b>';
    $flow['edges'][0]['onclick'] = 'x';

    $result = FlowValidator::sanitize($flow);
    $action = $result['flow']['nodes'][2];

    expect($result['errors'])->toBe([])->and($result['general'])->toBe([])
        ->and($action)->not->toHaveKey('evil')
        ->and($action['config'])->toBe(['type' => 'add_tag', 'tag_id' => $tag])
        ->and($action['label'])->toBe('bEtiquetar/b')
        ->and($result['flow']['edges'][0])->toBe(['id' => 'e-t-c-x', 'source' => 't', 'target' => 'c', 'branch' => null]);
});

it('finds the soft problems: triggers, loose blocks, cycles, branches, config', function () {
    $p = aflPipeline();
    $tag = aflTag();

    // Two triggers + a loose block.
    $flow = aflValid($p, $tag);
    $flow['nodes'][] = aflNode('t2', 'trigger', ['trigger_type' => 'lead_created']);
    $flow['nodes'][] = aflNode('loose', 'action', ['type' => 'add_tag', 'tag_id' => $tag]);
    $result = FlowValidator::sanitize($flow);

    expect($result['general'])->toContain(trans('automations::app.flow.errors.one_trigger'))
        ->and($result['errors']['loose'])->toContain(trans('automations::app.flow.errors.unreachable'));

    // A cycle.
    $cycle = [
        'nodes' => [aflStageTrigger($p, 1), aflNode('a', 'action', ['type' => 'add_tag', 'tag_id' => $tag]), aflNode('b', 'action', ['type' => 'add_tag', 'tag_id' => $tag]), aflNode('c', 'condition', ['rules' => [['type' => 'tag', 'tag_id' => $tag]]], ['end' => ['no']])],
        'edges' => [aflEdge('t', 'a'), aflEdge('a', 'c'), aflEdge('c', 'b', 'yes'), aflEdge('b', 'a')],
    ];
    expect(FlowValidator::sanitize($cycle)['general'])->toContain(trans('automations::app.flow.errors.cycle'));

    // A condition with a branch neither linked nor ended, and an action with no tag.
    $flow = aflValid($p, $tag);
    $flow['nodes'][1]['end'] = [];
    $flow['nodes'][2]['config']['tag_id'] = null;
    $result = FlowValidator::sanitize($flow);

    expect($result['errors']['c'])->toContain(trans('automations::app.flow.errors.branch_no'))
        ->and($result['errors']['a'])->toContain(trans('automations::app.validation.tag'));

    // A webhook to an internal address.
    $flow = aflValid($p, $tag);
    $flow['nodes'][2]['config'] = ['type' => 'webhook', 'url' => 'http://10.0.0.1/x', 'secret' => ''];
    expect(FlowValidator::sanitize($flow)['errors']['a'])->toContain(trans('automations::app.validation.url'));
});

it('refuses hard problems even in a draft', function (callable $break) {
    $p = aflPipeline();
    $flow = aflValid($p, aflTag());
    $break($flow);

    expect(fn () => FlowValidator::sanitize($flow))->toThrow(ValidationException::class);
})->with([
    'unknown block type' => [function (&$flow) {
        // "wait" exists since Fase 2; the conversation bot (Fase 3) does not.
        $flow['nodes'][2]['type'] = 'bot';
    }],
    'unknown action' => [function (&$flow) {
        $flow['nodes'][2]['config']['type'] = 'delete_lead';
    }],
    'unknown condition' => [function (&$flow) {
        $flow['nodes'][1]['config']['rules'][0]['type'] = 'sql';
    }],
    'repeated id' => [function (&$flow) {
        $flow['nodes'][2]['id'] = 'c';
    }],
    'bad id' => [function (&$flow) {
        $flow['nodes'][2]['id'] = 'a"><img>';
    }],
    'link to nowhere' => [function (&$flow) {
        $flow['edges'][] = aflEdge('a', 'ghost');
    }],
    'self link' => [function (&$flow) {
        $flow['edges'][] = aflEdge('a', 'a');
    }],
    'branch on an action' => [function (&$flow) {
        $flow['edges'][] = aflEdge('t', 'a', 'yes');
    }],
    'too many blocks' => [function (&$flow) {
        for ($i = 0; $i < 30; $i++) {
            $flow['nodes'][] = aflNode('x'.$i, 'action', ['type' => 'add_tag']);
        }
    }],
    'not a graph' => [function (&$flow) {
        $flow['nodes'] = 'x';
    }],
]);

// ---------------------------------------------------------------- conversion

function aflLegacy(array $p, array $conditions, array $actions, array $extra = []): AutomationRule
{
    return AutomationRule::query()->create($extra + [
        'name' => 'ZZ antiga '.uniqid(), 'status' => 'on', 'trigger_type' => 'stage_entered',
        'trigger_config' => ['pipeline_id' => $p['pipeline'], 'stage_id' => $p['open'][1], 'from_stage_id' => null],
        'conditions' => $conditions, 'actions' => $actions, 'run_once_per_lead' => false,
    ]);
}

/** What a run did, without the flow-only rows (trigger, branch, end) and block ids. */
function aflOutcome(AutomationRun $run): array
{
    return [
        'status' => $run->status,
        'error' => $run->error,
        'steps' => collect($run->steps)->reject(fn ($s) => in_array($s['action'], ['trigger', 'branch', 'end'], true))
            ->map(fn ($s) => ['action' => $s['action'], 'status' => $s['status'], 'detail' => $s['detail']])->values()->all(),
    ];
}

it('converts an old rule into a straight-line flow that behaves exactly the same', function (string $case) {
    $p = aflPipeline();
    $tag = aflTag();
    $after = aflTag();
    $needed = aflTag();
    $owner = aflUser();

    [$conditions, $actions, $prepare] = match ($case) {
        'conditions met' => [[['type' => 'tag', 'tag_id' => $needed], ['type' => 'pipeline', 'pipeline_id' => $p['pipeline']]], [['type' => 'add_tag', 'tag_id' => $tag], ['type' => 'assign_owner', 'user_id' => $owner]], fn (Lead $l) => $l->tags()->attach($needed)],
        'conditions not met' => [[['type' => 'tag', 'tag_id' => $needed]], [['type' => 'add_tag', 'tag_id' => $tag]], fn () => null],
        'failing action' => [[], [['type' => 'assign_owner', 'user_id' => 999999], ['type' => 'add_tag', 'tag_id' => $after]], fn () => null],
        'no conditions' => [[], [['type' => 'create_task', 'title' => 'Ligar {{lead}}', 'due_in_days' => 1, 'assignee' => 'owner'], ['type' => 'add_tag', 'tag_id' => $tag]], fn () => null],
    };

    $rule = aflLegacy($p, $conditions, $actions);

    // Before: the old engine.
    $before = aflLead($p);
    $prepare($before);
    aflMove($before, $p['open'][1]);
    $old = aflOutcome(aflRuns($rule)->last());

    Artisan::call('automations:convert-rules');
    $rule->refresh();

    expect($rule->isFlow())->toBeTrue();

    // After: the same rule as a flow, on an identical lead.
    $after_lead = aflLead($p);
    $prepare($after_lead);
    aflMove($after_lead, $p['open'][1]);
    $new = aflOutcome(aflRuns($rule)->last());

    $state = fn (Lead $l) => [
        'stage' => (int) $l->refresh()->lead_pipeline_stage_id,
        'owner' => $l->user_id,
        'tags' => DB::table('lead_tags')->where('lead_id', $l->id)->pluck('tag_id')->sort()->values()->all(),
        'activities' => DB::table('lead_activities')->where('lead_id', $l->id)->count(),
    ];

    expect(str_replace((string) $before->title, '#', json_encode($old)))->toBe(str_replace((string) $after_lead->title, '#', json_encode($new)))
        ->and($state($after_lead))->toBe($state($before));
})->with(['conditions met', 'conditions not met', 'failing action', 'no conditions']);

it('converts idempotently, keeping status, test mode, name, history and the "once per lead" memory', function () {
    $p = aflPipeline();
    $tag = aflTag();

    $on = aflLegacy($p, [], [['type' => 'add_tag', 'tag_id' => $tag]], ['run_once_per_lead' => true]);
    $test = aflLegacy($p, [['type' => 'pipeline', 'pipeline_id' => $p['pipeline']]], [['type' => 'add_tag', 'tag_id' => $tag]], ['status' => 'test', 'trigger_config' => ['pipeline_id' => $p['pipeline'], 'stage_id' => $p['open'][2], 'from_stage_id' => null]]);
    $off = aflLegacy($p, [], [['type' => 'add_tag', 'tag_id' => $tag]], ['status' => 'off']);

    $lead = aflLead($p);
    aflMove($lead, $p['open'][1]);
    $runsBefore = AutomationRun::query()->whereIn('rule_id', [$on->id, $test->id, $off->id])->count();

    Artisan::call('automations:convert-rules');
    $flows = AutomationRule::query()->whereIn('id', [$on->id, $test->id, $off->id])->get()->keyBy('id');
    Artisan::call('automations:convert-rules');

    foreach ([$on, $test, $off] as $original) {
        $converted = AutomationRule::query()->findOrFail($original->id);

        expect($converted->flow)->toBe($flows[$original->id]->flow)
            ->and($converted->status)->toBe($original->status)
            ->and($converted->name)->toBe($original->name)
            ->and($converted->run_once_per_lead)->toBe($original->run_once_per_lead)
            ->and($converted->actions)->toBe($original->actions)
            ->and($converted->flow['meta']['source'])->toBe('legacy');
    }

    expect(AutomationRun::query()->whereIn('rule_id', [$on->id, $test->id, $off->id])->count())->toBe($runsBefore);

    // "Once per lead": the lead that already ran the old rule does not run the flow again.
    aflMove($lead, $p['open'][0]);
    aflMove($lead, $p['open'][1]);

    expect(aflRuns($on)->where('status', 'ok'))->toHaveCount(1);
});

it('reverts converted straight-line flows and keeps branching ones', function () {
    $p = aflPipeline();
    $tag = aflTag();

    $legacy = aflLegacy($p, [['type' => 'tag', 'tag_id' => $tag]], [['type' => 'add_tag', 'tag_id' => $tag]]);
    Artisan::call('automations:convert-rules');

    $branching = aflRule([
        aflStageTrigger($p, 1),
        aflNode('c', 'condition', ['match' => 'all', 'rules' => [['type' => 'tag', 'tag_id' => $tag]]]),
        aflNode('y', 'action', ['type' => 'add_tag', 'tag_id' => $tag]),
        aflNode('n', 'action', ['type' => 'add_tag', 'tag_id' => $tag]),
    ], [aflEdge('t', 'c'), aflEdge('c', 'y', 'yes'), aflEdge('c', 'n', 'no')]);

    expect($branching->actions)->toBe([]);

    Artisan::call('automations:convert-rules', ['--revert' => true]);

    expect($legacy->refresh()->flow)->toBeNull()
        ->and($legacy->conditions)->toBe([['type' => 'tag', 'tag_id' => $tag]])
        ->and($branching->refresh()->isFlow())->toBeTrue();
});

it('round-trips an old rule through the flow format', function () {
    $rule = [
        'trigger_type' => 'tag_added', 'trigger_config' => ['tag_id' => 5],
        'conditions' => [['type' => 'owner', 'user_id' => 3]],
        'actions' => [['type' => 'add_tag', 'tag_id' => 6], ['type' => 'mark_lost', 'reason_id' => null]],
    ];

    expect(FlowConverter::toLegacy(FlowConverter::fromLegacy($rule)))->toBe($rule);
});

// ---------------------------------------------------------------- screen / API

function aflPayload(array $p, int $tag, string $status = 'on'): array
{
    return ['name' => 'ZZ fluxo da tela', 'status' => $status, 'run_once_per_lead' => true, 'flow' => aflValid($p, $tag)];
}

it('saves a flow from the builder and keeps the old columns in sync', function () {
    $p = aflPipeline();
    $tag = aflTag();

    $response = $this->actingAs(aflAdmin(), 'user')->postJson(route('admin.automations.store'), aflPayload($p, $tag))->assertOk()
        ->assertJsonPath('data.is_flow', true)
        ->assertJsonPath('data.flow.nodes.0.id', 't');

    $rule = AutomationRule::query()->findOrFail($response->json('data.id'));

    expect($rule->trigger_type)->toBe('stage_entered')
        ->and($rule->trigger_config['stage_id'])->toBe($p['open'][1])
        ->and($rule->conditions)->toBe([['type' => 'tag', 'tag_id' => $tag]])
        ->and($rule->actions)->toBe([['type' => 'add_tag', 'tag_id' => $tag]])
        ->and($rule->flow['meta']['source'])->toBe('builder');
});

it('saves an invalid flow only as a draft and refuses to turn it on', function () {
    $p = aflPipeline();
    $tag = aflTag();
    $admin = aflAdmin();
    $payload = aflPayload($p, $tag);
    $payload['flow']['nodes'][1]['end'] = [];

    $this->actingAs($admin, 'user')->postJson(route('admin.automations.store'), $payload)
        ->assertStatus(422)->assertJsonValidationErrors('flow.nodes.c');

    $payload['status'] = 'off';
    $id = $this->actingAs($admin, 'user')->postJson(route('admin.automations.store'), $payload)->assertOk()->json('data.id');

    $this->actingAs($admin, 'user')->patchJson(route('admin.automations.status', ['id' => $id]), ['status' => 'on'])
        ->assertStatus(422)->assertJsonValidationErrors('flow.nodes.c');
    $this->actingAs($admin, 'user')->patchJson(route('admin.automations.status', ['id' => $id]), ['status' => 'test'])->assertStatus(422);

    expect(AutomationRule::query()->findOrFail($id)->status)->toBe('off');

    // Fixed: now it turns on.
    $payload['flow']['nodes'][1]['end'] = ['no'];
    $this->actingAs($admin, 'user')->putJson(route('admin.automations.update', ['id' => $id]), $payload)->assertOk();
    $this->actingAs($admin, 'user')->patchJson(route('admin.automations.status', ['id' => $id]), ['status' => 'on'])->assertOk()->assertJsonPath('data.status', 'on');
});

it('never sends a webhook secret of a flow back and keeps it when unchanged', function () {
    $p = aflPipeline();
    $tag = aflTag();
    $admin = aflAdmin();
    $payload = aflPayload($p, $tag);
    $payload['flow']['nodes'][2]['config'] = ['type' => 'webhook', 'url' => 'https://hooks.example.com/f', 'secret' => 'segredo-fluxo'];

    $created = $this->actingAs($admin, 'user')->postJson(route('admin.automations.store'), $payload)->assertOk();

    expect($created->json('data.flow.nodes.2.config.secret'))->toBe('__keep__');

    $payload['flow']['nodes'][2]['config']['secret'] = '__keep__';
    $this->actingAs($admin, 'user')->putJson(route('admin.automations.update', ['id' => $created->json('data.id')]), $payload)->assertOk();

    expect(AutomationRule::query()->findOrFail($created->json('data.id'))->flow['nodes'][2]['config']['secret'])->toBe('segredo-fluxo');
});

it('opens an old rule in the builder as its flow, and shows the flow templates', function () {
    $p = aflPipeline();
    aflLegacy($p, [], [['type' => 'add_tag', 'tag_id' => aflTag()]], ['name' => 'ZZ regra antiga da tela']);

    $this->actingAs(aflAdmin(), 'user')->get(route('admin.automations.index'))
        ->assertOk()
        ->assertSee('ZZ regra antiga da tela', false)
        ->assertSee('automations/build', false)
        ->assertViewHas('recipes', fn ($recipes) => collect($recipes)->every(fn ($recipe) => $recipe['rule']['flow']['meta']['source'] === 'recipe'))
        ->assertViewHas('rules', fn ($rules) => collect($rules)->contains(fn ($rule) => $rule['name'] === 'ZZ regra antiga da tela' && ! $rule['is_flow'] && $rule['flow']['nodes'][1]['config']['type'] === 'add_tag'));
});

it('keeps the permissions: viewing is not creating or editing', function () {
    $p = aflPipeline();
    $roleId = DB::table('roles')->insertGetId(['name' => 'ZZ só vê automações', 'description' => 'x', 'permission_type' => 'custom', 'permissions' => json_encode(['settings', 'settings.automation', 'settings.automation.automations']), 'created_at' => now(), 'updated_at' => now()]);
    $userId = DB::table('users')->insertGetId(['name' => 'ZZ', 'email' => 'zzv-'.uniqid().'@example.test', 'password' => 'x', 'status' => 1, 'role_id' => $roleId, 'view_permission' => 'global', 'created_at' => now(), 'updated_at' => now()]);
    $viewer = User::query()->findOrFail($userId);
    $rule = aflRule([aflStageTrigger($p, 1), aflNode('a', 'action', ['type' => 'add_tag', 'tag_id' => aflTag()])], [aflEdge('t', 'a')], 'off');

    $this->actingAs($viewer, 'user')->get(route('admin.automations.index'))->assertOk();
    $this->actingAs($viewer, 'user')->postJson(route('admin.automations.store'), aflPayload($p, aflTag()))->assertStatus(401);
    $this->actingAs($viewer, 'user')->putJson(route('admin.automations.update', ['id' => $rule->id]), aflPayload($p, aflTag()))->assertStatus(401);
    $this->actingAs($viewer, 'user')->patchJson(route('admin.automations.status', ['id' => $rule->id]), ['status' => 'on'])->assertStatus(401);
});
