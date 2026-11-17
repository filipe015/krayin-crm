<?php

namespace Webkul\Automations\Support;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webkul\Automations\Models\AutomationRule;
use Webkul\Automations\Models\AutomationRun;
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
     * @param  array<string, mixed>  $first  the first action block (the walk up to it is in $steps)
     * @param  list<array<string, mixed>>  $steps
     * @param  list<string>  $chain
     */
    private function executeFlow(AutomationRun $run, array $flow, array $first, array $steps, AutomationRule $rule, Lead $lead, bool $dry, int $depth, array $chain): AutomationRun
    {
        $started = microtime(true);
        $error = null;

        try {
            AutomationContext::run($depth + 1, [...$chain, AutomationContext::key($rule->id, $lead->id)], $rule->name, function () use ($flow, $first, $lead, $rule, $dry, &$steps, &$error) {
                [, $more, $error] = $this->walk($flow, $first, $lead, $rule, false, $dry);
                $steps = [...$steps, ...$more];
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

    /**
     * Walks the graph from $node. With $untilAction it stops at the first action block (not run) and
     * returns it; otherwise it runs every block on the way (actions too) until the end or the first
     * failed action. A visited set and a step cap make sure a bad graph can never loop.
     *
     * @param  array<string, mixed>  $flow
     * @param  array<string, mixed>  $node
     * @return array{0: array<string, mixed>|null, 1: list<array<string, mixed>>, 2: string|null}
     */
    private function walk(array $flow, array $node, Lead $lead, AutomationRule $rule, bool $untilAction, bool $dry): array
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
                    $steps[] = [
                        'node' => $id, 'action' => 'trigger', 'status' => 'ok',
                        'detail' => trans('automations::app.engine.flow_trigger', ['trigger' => trans('automations::app.triggers.'.($node['config']['trigger_type'] ?? ''))]),
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

                case FlowGraph::ACTION:
                    if ($untilAction) {
                        return [$node, $steps, null];
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
                        return [null, $steps, (string) ($result['detail'] ?? 'failed')];
                    }
                    break;

                default:
                    return [null, $steps, null];
            }

            $next = FlowGraph::next($flow, $id, $branch);

            if ($next === null && $node['type'] === FlowGraph::CONDITION) {
                $steps[] = ['node' => $id, 'action' => 'end', 'status' => 'skipped', 'detail' => trans('automations::app.engine.flow_end')];
            }

            $node = $next !== null ? ($nodes[$next] ?? null) : null;
        }

        return [null, $steps, null];
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
