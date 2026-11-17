<?php

namespace Webkul\Automations\Support;

use Illuminate\Support\Facades\Log;
use Throwable;
use Webkul\Automations\Jobs\RunAutomationRule;
use Webkul\Automations\Models\AutomationRule;
use Webkul\Automations\Models\AutomationRun;

/**
 * Turns a lead event into queued jobs: one per rule (on or test) whose trigger matches. Nothing here
 * may ever get in the way of saving the lead, so every failure is only logged.
 */
final class EventRouter
{
    public static function enabled(): bool
    {
        return (bool) config('automations.enabled', false);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function fire(string $trigger, int $leadId, array $context = []): void
    {
        if (! self::enabled() || $leadId <= 0) {
            return;
        }

        try {
            $rules = AutomationRule::query()
                ->where('trigger_type', $trigger)
                ->whereIn('status', [AutomationRule::STATUS_ON, AutomationRule::STATUS_TEST])
                ->orderBy('id')
                ->get();

            foreach ($rules as $rule) {
                if (TriggerMatcher::matches($rule, $trigger, $context)) {
                    self::queue($rule, $trigger, $leadId, $context);
                }
            }
        } catch (Throwable $exception) {
            Log::warning('Automations: event could not be routed.', ['trigger' => $trigger, 'lead_id' => $leadId, 'error' => $exception->getMessage()]);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function queue(AutomationRule $rule, string $trigger, int $leadId, array $context): void
    {
        $depth = AutomationContext::depth();
        $chain = AutomationContext::chain();

        if (in_array(AutomationContext::key($rule->id, $leadId), $chain, true)) {
            self::blocked($rule, $leadId, $trigger, $depth, 'same_rule');

            return;
        }

        if ($depth >= max(1, (int) config('automations.max_depth', 3))) {
            self::blocked($rule, $leadId, $trigger, $depth, 'max_depth');

            return;
        }

        try {
            $pending = RunAutomationRule::dispatch($rule->id, $leadId, $trigger, $context, $depth, $chain)->afterCommit();

            if ($queue = config('automations.queue')) {
                $pending->onQueue($queue);
            }
        } catch (Throwable $exception) {
            Log::warning('Automations: job could not be queued.', ['rule_id' => $rule->id, 'lead_id' => $leadId, 'error' => $exception->getMessage()]);
        }
    }

    /**
     * Loop protection cut the chain here: written in the history, so it can be seen.
     */
    private static function blocked(AutomationRule $rule, int $leadId, string $trigger, int $depth, string $reason): void
    {
        try {
            AutomationRun::query()->create([
                'rule_id' => $rule->id,
                'lead_id' => $leadId,
                'trigger' => $trigger,
                'status' => AutomationRun::STATUS_SKIPPED,
                'steps' => [['action' => 'loop_protection', 'status' => 'skipped', 'detail' => trans('automations::app.engine.loop_'.$reason, ['depth' => $depth])]],
                'error' => trans('automations::app.engine.loop_'.$reason, ['depth' => $depth]),
                'depth' => min($depth, 255),
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            Log::warning('Automations: loop protection could not be recorded.', ['error' => $exception->getMessage()]);
        }
    }
}
