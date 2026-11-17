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
