<?php

namespace Webkul\Automations\Support;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webkul\Automations\Models\AutomationRule;
use Webkul\Automations\Models\AutomationRun;
use Webkul\Automations\Models\AutomationWait;
use Webkul\Lead\Models\Lead;

/**
 * Runs a rule on a lead: checks the conditions, claims the run (idempotency), executes or — in test
 * mode — only describes the actions, and writes it all in automation_runs.
 *
 * Idempotency keys (automation_runs.idempotency_key, unique):
 *  - "evt:<sha1(rule|lead|trigger|context)>"  a live run of one event (the same event never runs twice);
 *  - "once:<rule>:<lead>"                     a live run of a "once per lead" rule (whatever the event);
 *  - "dry:<sha1…>" / "skip:<sha1…>"           test-mode runs and condition misses of one event.
 * Simulations from the screen have no key.
 *
 * Flows (rules built in the visual builder, AutomationRule::isFlow()) use the same keys and the same
 * claim, with the trigger block's id in the event hash; the walk follows the links from the trigger,
 * a condition picks its "yes"/"no" branch and every step in the history carries the id of the block
 * it came from ("node"), so the history shows the path the lead took. The run is claimed right before
 * the first action: a walk that ends before any action is recorded as "Ignorada" (skip:…), like an
 * old rule whose conditions did not hold.
 *
 * Fase 2: an "Esperar" block counts like an action for the claim (the run is claimed before the first
 * action OR wait, so the same event never stores two waits). A live wait leaves the run as "waiting"
 * and a row in automation_waits; resume() continues the SAME run later (see automations:tick).
 */
class Engine
{
    public function __construct(private ActionRunner $actions) {}

    /**
     * @param  array<string, mixed>  $context
     * @param  list<string>  $chain
     */
    public function runFromEvent(int $ruleId, int $leadId, string $trigger, array $context = [], int $depth = 0, array $chain = []): ?AutomationRun
    {
        if (! EventRouter::enabled()) {
            return null;
        }

        $rule = AutomationRule::query()->find($ruleId);

        if (! $rule || $rule->status === AutomationRule::STATUS_OFF) {
            return null;
        }

        $lead = Lead::query()->withoutGlobalScopes()->find($leadId);

        if (! $lead) {
            return null;
        }

        // A time trigger is found by the tick; the job may run a bit later: is the lead still due?
        if ($rule->isTimeTriggered() && ($rule->trigger_type !== $trigger || ! TimeTriggers::stillQualifies($rule, $lead, $context))) {
            return null;
        }

        $eventHash = sha1(implode('|', [$rule->id, $lead->id, $trigger, json_encode(self::sorted($context))]));
        $dry = $rule->isTest();

        if ($rule->isFlow()) {
            return $this->runFlow($rule, $lead, $trigger, $eventHash, $dry, $depth, $chain);
        }

        [$passes, $checks] = ConditionEvaluator::evaluate((array) $rule->conditions, $lead);

        if (! $passes) {
            $this->record($rule, $lead, $trigger, AutomationRun::STATUS_SKIPPED, $checks, trans('automations::app.engine.conditions_not_met'), $depth, 'skip:'.$eventHash, 0);

            return null;
        }

        $key = match (true) {
            $dry => 'dry:'.$eventHash,
            $rule->run_once_per_lead => 'once:'.$rule->id.':'.$lead->id,
            default => 'evt:'.$eventHash,
        };

        $run = $this->claim($rule, $lead, $trigger, $depth, $key);

        if (! $run) {
            return null;
        }

        return $this->execute($run, $rule, $lead, $checks, $dry, $depth, $chain);
    }

    /**
     * "Simular em um lead": conditions and actions are evaluated against the lead as it is now and
     * nothing is changed, whatever the rule's status. Written in the history as a dry run.
     */
    public function simulate(AutomationRule $rule, Lead $lead): AutomationRun
    {
        if ($rule->isFlow()) {
            return $this->simulateFlow($rule, $lead);
        }

        [$passes, $checks] = ConditionEvaluator::evaluate((array) $rule->conditions, $lead);

        if (! $passes) {
            return $this->record($rule, $lead, 'simulation', AutomationRun::STATUS_SKIPPED, $checks, trans('automations::app.engine.conditions_not_met'), 0, null, 0);
        }

        $run = AutomationRun::query()->create([
            'rule_id' => $rule->id, 'lead_id' => $lead->id, 'trigger' => 'simulation',
            'status' => AutomationRun::STATUS_RUNNING, 'depth' => 0, 'created_at' => now(),
        ]);

        return $this->execute($run, $rule, $lead, $checks, true, 0, []);
    }

    /**
     * @param  list<array<string, mixed>>  $checks
     * @param  list<string>  $chain
     */
    private function execute(AutomationRun $run, AutomationRule $rule, Lead $lead, array $checks, bool $dry, int $depth, array $chain): AutomationRun
    {
        $started = microtime(true);
        $steps = $checks;
        $error = null;

        try {
            AutomationContext::run($depth + 1, [...$chain, AutomationContext::key($rule->id, $lead->id)], $rule->name, function () use ($rule, $lead, $dry, &$steps, &$error) {
                foreach ((array) $rule->actions as $action) {
                    $type = (string) ($action['type'] ?? '');

                    try {
                        $lead->refresh();
                        $result = $this->actions->run((array) $action, $lead, $rule, $dry);
                    } catch (Throwable $exception) {
                        report($exception);
                        $result = ['status' => 'failed', 'detail' => $exception->getMessage()];
                    }

                    $steps[] = ['action' => $type] + $result;

                    if (($result['status'] ?? '') === 'failed') {
                        $error = (string) ($result['detail'] ?? 'failed');

                        // The next actions may depend on this one: stop here.
                        break;
                    }
                }
            });
        } catch (Throwable $exception) {
            report($exception);
            $error = $exception->getMessage();
        }

        $status = match (true) {
            $dry => AutomationRun::STATUS_DRY_RUN,
            $error !== null => AutomationRun::STATUS_FAILED,
            default => AutomationRun::STATUS_OK,
        };

        try {
            $run->forceFill([
                'status' => $status,
                'steps' => $steps,
                'error' => $error !== null ? mb_substr($error, 0, 2000) : null,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ])->save();
        } catch (Throwable $exception) {
            Log::warning('Automations: run could not be saved.', ['run_id' => $run->id, 'error' => $exception->getMessage()]);
        }

        return $run;
    }

    // ------------------------------------------------------------------ flows

    /**
     * @param  list<string>  $chain
     */
    private function runFlow(AutomationRule $rule, Lead $lead, string $trigger, string $eventHash, bool $dry, int $depth, array $chain): ?AutomationRun
    {
        $flow = (array) $rule->flow;
        $start = FlowGraph::trigger($flow);

        if (! $start) {
            return null;
        }

        // The trigger block is part of the event's identity (a future flow may have more than one entry).
        $eventHash = sha1($eventHash.'|node:'.$start['id']);

        [$first, $steps] = $this->walk($flow, $start, $lead, $rule, true, false);

        if ($first === null) {
            $this->record($rule, $lead, $trigger, AutomationRun::STATUS_SKIPPED, $steps, self::endReason($steps), $depth, 'skip:'.$eventHash, 0);

            return null;
        }

        $key = match (true) {
            $dry => 'dry:'.$eventHash,
            $rule->run_once_per_lead => 'once:'.$rule->id.':'.$lead->id,
            default => 'evt:'.$eventHash,
        };

        $run = $this->claim($rule, $lead, $trigger, $depth, $key);

        if (! $run) {
            return null;
        }

        return $this->executeFlow($run, $flow, $first, $steps, $rule, $lead, $dry, $depth, $chain);
    }

    private function simulateFlow(AutomationRule $rule, Lead $lead): AutomationRun
    {
        $flow = (array) $rule->flow;
        $start = FlowGraph::trigger($flow);
        [$first, $steps] = $start ? $this->walk($flow, $start, $lead, $rule, true, true) : [null, []];

        // A time trigger fires by itself: "Simular" says whether this lead would qualify now.
        if ($start && $rule->isTimeTriggered()) {
            array_splice($steps, 1, 0, [['node' => (string) $start['id'], 'action' => 'time_check'] + TimeTriggers::describe($rule, $lead)]);
        }

        if ($first === null) {
            return $this->record($rule, $lead, 'simulation', AutomationRun::STATUS_SKIPPED, $steps, self::endReason($steps), 0, null, 0);
        }

        $run = AutomationRun::query()->create([
            'rule_id' => $rule->id, 'lead_id' => $lead->id, 'trigger' => 'simulation',
            'status' => AutomationRun::STATUS_RUNNING, 'depth' => 0, 'created_at' => now(),
        ]);

        return $this->executeFlow($run, $flow, $first, $steps, $rule, $lead, true, 0, []);
    }

    /**
     * @param  array<string, mixed>  $flow
     * @param  array<string, mixed>  $first  the first action/wait block (the walk up to it is in $steps)
     * @param  list<array<string, mixed>>  $steps
     * @param  list<string>  $chain
     */
    private function executeFlow(AutomationRun $run, array $flow, array $first, array $steps, AutomationRule $rule, Lead $lead, bool $dry, int $depth, array $chain): AutomationRun
    {
        $started = microtime(true);
        $error = null;
        $waiting = false;
        $live = ['run' => $run, 'trigger' => (string) $run->trigger, 'depth' => $depth, 'chain' => $chain];

        try {
            AutomationContext::run($depth + 1, [...$chain, AutomationContext::key($rule->id, $lead->id)], $rule->name, function () use ($flow, $first, $lead, $rule, $dry, $live, &$steps, &$error, &$waiting) {
                [, $more, $error, $waiting] = $this->walk($flow, $first, $lead, $rule, false, $dry, $live);
                $steps = [...$steps, ...$more];
            });
        } catch (Throwable $exception) {
            report($exception);
            $error = $exception->getMessage();
        }

        $this->finish($run, $steps, $error, $dry, $waiting, $started);

        return $run;
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private function finish(AutomationRun $run, array $steps, ?string $error, bool $dry, bool $waiting, float $started, int $previousMs = 0): void
    {
        $status = match (true) {
            $dry => AutomationRun::STATUS_DRY_RUN,
            $error !== null => AutomationRun::STATUS_FAILED,
            $waiting => AutomationRun::STATUS_WAITING,
            default => AutomationRun::STATUS_OK,
        };

        try {
            $run->forceFill([
                'status' => $status,
                'steps' => $steps,
                'error' => $error !== null ? mb_substr($error, 0, 2000) : null,
                'duration_ms' => min(4294967295, $previousMs + (int) round((microtime(true) - $started) * 1000)),
            ])->save();
        } catch (Throwable $exception) {
            Log::warning('Automations: run could not be saved.', ['run_id' => $run->id, 'error' => $exception->getMessage()]);
        }
    }

    /**
     * Walks the graph from $node. With $untilAction it stops at the first action or wait block (not
     * run) and returns it; otherwise it runs every block on the way until the end, the first failed
     * action or a wait. A wait in test mode / simulation ($dry) only says how long it would wait and
     * the walk goes on; a live wait ($live: the run being executed) is stored in automation_waits and
     * the walk stops there (automations:tick resumes it). A visited set and a step cap make sure a
     * bad graph can never loop.
     *
     * @param  array<string, mixed>  $flow
     * @param  array<string, mixed>  $node
     * @param  array{run: AutomationRun, trigger: string, depth: int, chain: list<string>}|null  $live
     * @return array{0: array<string, mixed>|null, 1: list<array<string, mixed>>, 2: string|null, 3: bool}
     */
    private function walk(array $flow, array $node, Lead $lead, AutomationRule $rule, bool $untilAction, bool $dry, ?array $live = null): array
    {
        $nodes = FlowGraph::nodes($flow);
        $steps = [];
        $visited = [];
        $limit = count($nodes) + 1;

        while ($node !== null && $limit-- > 0 && ! isset($visited[$node['id']])) {
            $visited[$node['id']] = true;
            $id = (string) $node['id'];
            $branch = null;

            switch ($node['type']) {
                case FlowGraph::TRIGGER:
                    $config = (array) ($node['config'] ?? []);
                    $label = trans('automations::app.triggers.'.($config['trigger_type'] ?? ''));

                    if (in_array($config['trigger_type'] ?? null, AutomationRule::TIME_TRIGGERS, true)) {
                        $label .= ' ('.TimeTriggers::human($config).')';
                    }

                    $steps[] = [
                        'node' => $id, 'action' => 'trigger', 'status' => 'ok',
                        'detail' => trans('automations::app.engine.flow_trigger', ['trigger' => $label]),
                    ];
                    break;

                case FlowGraph::CONDITION:
                    $lead->refresh();
                    [$passes, $checks] = ConditionEvaluator::evaluateGroup((array) ($node['config']['rules'] ?? []), (string) ($node['config']['match'] ?? 'all'), $lead);
                    $branch = $passes ? 'yes' : 'no';

                    foreach ($checks as $check) {
                        $steps[] = ['node' => $id] + $check;
                    }

                    $steps[] = [
                        'node' => $id, 'action' => 'branch', 'branch' => $branch, 'status' => $passes ? 'ok' : 'skipped',
                        'detail' => trans('automations::app.engine.flow_branch_'.$branch),
                    ];
                    break;

                case FlowGraph::WAIT:
                    if ($untilAction) {
                        return [$node, $steps, null, false];
                    }

                    $config = (array) ($node['config'] ?? []);
                    $until = now()->addSeconds(FlowGraph::seconds($config));

                    if ($dry || $live === null) {
                        $steps[] = [
                            'node' => $id, 'action' => 'wait', 'status' => 'ok',
                            'detail' => trans('automations::app.engine.would_wait', ['duration' => TimeTriggers::human($config), 'date' => $until->format('d/m/Y H:i')]),
                        ];
                        break;
                    }

                    $problem = $this->pause($live, $node, $lead, $rule, $flow, $until);

                    if ($problem !== null) {
                        $steps[] = ['node' => $id, 'action' => 'wait', 'status' => 'failed', 'detail' => $problem];

                        return [null, $steps, $problem, false];
                    }

                    $steps[] = [
                        'node' => $id, 'action' => 'wait', 'status' => 'ok', 'until' => $until->toDateTimeString(),
                        'detail' => trans('automations::app.engine.waiting_until', ['date' => $until->format('d/m/Y H:i'), 'duration' => TimeTriggers::human($config)]),
                    ];

                    return [null, $steps, null, true];

                case FlowGraph::ACTION:
                    if ($untilAction) {
                        return [$node, $steps, null, false];
                    }

                    $action = (array) ($node['config'] ?? []);

                    try {
                        $lead->refresh();
                        $result = $this->actions->run($action, $lead, $rule, $dry);
                    } catch (Throwable $exception) {
                        report($exception);
                        $result = ['status' => 'failed', 'detail' => $exception->getMessage()];
                    }

                    $steps[] = ['node' => $id, 'action' => (string) ($action['type'] ?? '')] + $result;

                    if (($result['status'] ?? '') === 'failed') {
                        // The next blocks may depend on this one: stop here.
                        return [null, $steps, (string) ($result['detail'] ?? 'failed'), false];
                    }
                    break;

                default:
                    return [null, $steps, null, false];
            }

            $next = FlowGraph::next($flow, $id, $branch);

            if ($next === null && $node['type'] === FlowGraph::CONDITION) {
                $steps[] = ['node' => $id, 'action' => 'end', 'status' => 'skipped', 'detail' => trans('automations::app.engine.flow_end')];
            }

            $node = $next !== null ? ($nodes[$next] ?? null) : null;
        }

        return [null, $steps, null, false];
    }

    /**
     * Stores a live wait. Returns why it could not be stored (pending limit of the CRM), or null.
     *
     * @param  array{run: AutomationRun, trigger: string, depth: int, chain: list<string>}  $live
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $flow
     */
    private function pause(array $live, array $node, Lead $lead, AutomationRule $rule, array $flow, \Carbon\Carbon $until): ?string
    {
        $max = max(1, (int) config('automations.max_pending_waits', 10000));

        if (AutomationWait::query()->whereIn('status', AutomationWait::OPEN)->count() >= $max) {
            Log::warning('Automations: pending waits limit reached.', ['rule_id' => $rule->id, 'lead_id' => $lead->id, 'max' => $max]);

            return trans('automations::app.engine.wait_limit', ['max' => $max]);
        }

        $lead->refresh();

        AutomationWait::query()->create([
            'rule_id' => $rule->id,
            'lead_id' => $lead->id,
            'run_id' => $live['run']->id,
            'node_id' => (string) $node['id'],
            'status' => AutomationWait::STATUS_PENDING,
            'resume_at' => $until,
            'flow_hash' => FlowGraph::hash($flow),
            'context' => [
                'trigger' => $live['trigger'],
                'depth' => $live['depth'],
                'chain' => array_values($live['chain']),
                'pipeline_id' => $lead->lead_pipeline_id ? (int) $lead->lead_pipeline_id : null,
                'stage_id' => $lead->lead_pipeline_stage_id ? (int) $lead->lead_pipeline_stage_id : null,
                'stage_entered_at' => $lead->stage_entered_at?->toDateTimeString(),
                'cancel_on_stage_change' => (bool) ($node['config']['cancel_on_stage_change'] ?? true),
            ],
        ]);

        return null;
    }

    // ------------------------------------------------------------------ resuming a wait

    /**
     * Resumes a wait that automations:tick claimed (status "running"). Everything is checked again
     * with the data of NOW:
     *  - module off: the wait goes back to "pending" (resumes when it is turned on again);
     *  - rule deleted / no longer "Ligada", lead deleted, flow edited since the pause (FlowGraph::hash),
     *    lead moved to another stage (or left and came back) while "Cancelar se o lead mudar de etapa"
     *    is on: cancelled, with the reason in the wait and in the run's history;
     *  - otherwise the wait is marked "resumed" BEFORE the rest of the flow runs (a crash in the middle
     *    never runs the actions twice) and the walk goes on from the block after the wait, with the
     *    same loop protection (depth/chain) as the run that paused. Conditions after the wait see the
     *    lead as it is now. A later wait pauses again (same run).
     *
     * Exceptions before the walk are left to the job, which retries with backoff.
     */
    public function resume(AutomationWait $wait): ?AutomationRun
    {
        if (! EventRouter::enabled()) {
            $wait->forceFill(['status' => AutomationWait::STATUS_PENDING, 'claim_token' => null])->save();

            return null;
        }

        $context = (array) $wait->context;
        $rule = AutomationRule::query()->find($wait->rule_id);
        $lead = Lead::query()->withoutGlobalScopes()->find($wait->lead_id);
        $run = $wait->run_id ? AutomationRun::query()->find($wait->run_id) : null;

        $reason = match (true) {
            ! $rule => 'rule_deleted',
            $rule->status !== AutomationRule::STATUS_ON => 'rule_off',
            ! $rule->isFlow() || FlowGraph::hash((array) $rule->flow) !== $wait->flow_hash => 'flow_changed',
            ! $lead => 'lead_deleted',
            ! isset(FlowGraph::nodes((array) $rule->flow)[$wait->node_id]) => 'flow_changed',
            ($context['cancel_on_stage_change'] ?? true) && self::stageChanged($lead, $context) => 'stage_changed',
            default => null,
        };

        if ($reason !== null) {
            return $this->cancelWait($wait, $reason, $rule, $lead, $run);
        }

        // Claimed: from here on the wait never runs again, whatever happens below.
        $claimed = AutomationWait::query()->whereKey($wait->id)->where('status', AutomationWait::STATUS_RUNNING)
            ->update(['status' => AutomationWait::STATUS_RESUMED, 'updated_at' => now()]);

        if (! $claimed) {
            return null;
        }

        $flow = (array) $rule->flow;
        $nodes = FlowGraph::nodes($flow);
        $started = microtime(true);

        $run ??= AutomationRun::query()->create([
            'rule_id' => $rule->id, 'lead_id' => $lead->id, 'trigger' => (string) ($context['trigger'] ?? 'resume'),
            'status' => AutomationRun::STATUS_RUNNING, 'depth' => min((int) ($context['depth'] ?? 0), 255), 'created_at' => now(),
        ]);

        $previousMs = (int) $run->duration_ms;
        $steps = array_values((array) $run->steps);
        $steps[] = ['node' => $wait->node_id, 'action' => 'wait_resumed', 'status' => 'ok', 'detail' => trans('automations::app.engine.wait_resumed')];

        $depth = (int) ($context['depth'] ?? 0);
        $chain = array_values(array_map('strval', (array) ($context['chain'] ?? [])));
        $live = ['run' => $run, 'trigger' => (string) $run->trigger, 'depth' => $depth, 'chain' => $chain];
        $next = FlowGraph::next($flow, $wait->node_id);
        $error = null;
        $waiting = false;

        if ($next !== null && isset($nodes[$next])) {
            try {
                AutomationContext::run($depth + 1, [...$chain, AutomationContext::key($rule->id, $lead->id)], $rule->name, function () use ($flow, $nodes, $next, $lead, $rule, $live, &$steps, &$error, &$waiting) {
                    [, $more, $error, $waiting] = $this->walk($flow, $nodes[$next], $lead, $rule, false, false, $live);
                    $steps = [...$steps, ...$more];
                });
            } catch (Throwable $exception) {
                report($exception);
                $error = $exception->getMessage();
            }
        }

        $this->finish($run, $steps, $error, false, $waiting, $started, $previousMs);

        return $run;
    }

    /**
     * Cancels one wait and writes why in the run's history (a new run row when the old one is gone).
     */
    public function cancelWait(AutomationWait $wait, string $reason, ?AutomationRule $rule = null, ?Lead $lead = null, ?AutomationRun $run = null): ?AutomationRun
    {
        return $this->closeWait($wait, AutomationWait::STATUS_CANCELLED, trans('automations::app.engine.wait_cancelled_'.$reason), $rule, $lead, $run);
    }

    /**
     * Gave up after AutomationWait::MAX_ATTEMPTS tries that never got to run the rest of the flow.
     */
    public function expireWait(AutomationWait $wait, string $why): ?AutomationRun
    {
        Log::warning('Automations: wait expired.', ['wait_id' => $wait->id, 'error' => $why]);

        return $this->closeWait($wait, AutomationWait::STATUS_EXPIRED, trans('automations::app.engine.wait_expired', ['attempts' => $wait->attempts]));
    }

    private function closeWait(AutomationWait $wait, string $status, string $text, ?AutomationRule $rule = null, ?Lead $lead = null, ?AutomationRun $run = null): ?AutomationRun
    {
        $wait->forceFill(['status' => $status, 'reason' => mb_substr($text, 0, 255), 'claim_token' => null])->save();

        $run ??= $wait->run_id ? AutomationRun::query()->find($wait->run_id) : null;
        $step = ['node' => $wait->node_id, 'action' => 'wait_cancelled', 'status' => 'skipped', 'detail' => $text];

        try {
            if ($run) {
                $run->forceFill(['status' => AutomationRun::STATUS_SKIPPED, 'steps' => [...array_values((array) $run->steps), $step], 'error' => $text])->save();

                return $run;
            }

            $rule ??= AutomationRule::query()->find($wait->rule_id);
            $lead ??= Lead::query()->withoutGlobalScopes()->find($wait->lead_id);

            // Rule or lead deleted: their history went with them.
            if (! $rule || ! $lead) {
                return null;
            }

            return $this->record($rule, $lead, (string) (($wait->context['trigger'] ?? null) ?: 'resume'), AutomationRun::STATUS_SKIPPED, [$step], $text, (int) ($wait->context['depth'] ?? 0), null, 0);
        } catch (Throwable $exception) {
            Log::warning('Automations: cancelled wait could not be recorded.', ['wait_id' => $wait->id, 'error' => $exception->getMessage()]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function stageChanged(Lead $lead, array $context): bool
    {
        if ((int) $lead->lead_pipeline_stage_id !== (int) ($context['stage_id'] ?? 0)) {
            return true;
        }

        // Left the stage and came back: a new stay.
        $entered = $context['stage_entered_at'] ?? null;

        return $entered !== null && $lead->stage_entered_at && $lead->stage_entered_at->toDateTimeString() !== $entered;
    }

    /**
     * Why a walk reached no action: a condition that went to "Não" (like an old rule's conditions not
     * holding) or a flow with no action on the path taken.
     *
     * @param  list<array<string, mixed>>  $steps
     */
    private static function endReason(array $steps): string
    {
        $branch = collect($steps)->last(fn ($step) => ($step['action'] ?? null) === 'branch');

        return trans(($branch['branch'] ?? null) === 'no' ? 'automations::app.engine.conditions_not_met' : 'automations::app.engine.flow_no_action');
    }

    /**
     * Inserts the run with its key; a second one with the same key means it already ran (or runs).
     */
    private function claim(AutomationRule $rule, Lead $lead, string $trigger, int $depth, string $key): ?AutomationRun
    {
        try {
            return AutomationRun::query()->create([
                'rule_id' => $rule->id,
                'lead_id' => $lead->id,
                'trigger' => $trigger,
                'status' => AutomationRun::STATUS_RUNNING,
                'depth' => min($depth, 255),
                'idempotency_key' => $key,
                'created_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private function record(AutomationRule $rule, Lead $lead, string $trigger, string $status, array $steps, ?string $error, int $depth, ?string $key, int $ms): AutomationRun
    {
        $attributes = [
            'rule_id' => $rule->id, 'lead_id' => $lead->id, 'trigger' => $trigger, 'status' => $status,
            'steps' => $steps, 'error' => $error, 'depth' => min($depth, 255), 'duration_ms' => $ms,
            'idempotency_key' => $key, 'created_at' => now(),
        ];

        try {
            return AutomationRun::query()->create($attributes);
        } catch (UniqueConstraintViolationException) {
            return AutomationRun::query()->where('idempotency_key', $key)->firstOrFail();
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private static function sorted(array $context): array
    {
        ksort($context);

        return $context;
    }
}
