<?php

namespace Webkul\Automations\Support;

use Illuminate\Support\Facades\DB;
use Webkul\Automations\Models\AutomationRule;
use Webkul\Automations\Models\AutomationRun;

/**
 * A rule as the screen sees it: webhook secrets never leave the server ("__keep__" stands for
 * "there is one, unchanged"), plus how many times it ran and when it last did.
 */
final class RulePresenter
{
    public const KEEP_SECRET = '__keep__';

    /**
     * @return array<string, mixed>
     */
    public static function present(AutomationRule $rule, ?array $stats = null): array
    {
        $stats ??= self::stats([$rule->id])[$rule->id] ?? null;

        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'status' => $rule->status,
            'trigger_type' => $rule->trigger_type,
            'trigger_config' => (object) ((array) $rule->trigger_config),
            'conditions' => array_values((array) $rule->conditions),
            'actions' => array_map(function ($action) {
                if (($action['type'] ?? null) === 'webhook') {
                    $action['secret'] = filled($action['secret'] ?? null) ? self::KEEP_SECRET : '';
                }

                return $action;
            }, array_values((array) $rule->actions)),
            'run_once_per_lead' => (bool) $rule->run_once_per_lead,
            'runs_count' => (int) ($stats['runs'] ?? 0),
            'last_run_at' => $stats['last'] ?? null,
            'last_status' => $stats['last_status'] ?? null,
        ];
    }

    /**
     * @param  list<int>  $ruleIds
     * @return array<int, array{runs: int, last: ?string, last_status: ?string}>
     */
    public static function stats(array $ruleIds): array
    {
        if ($ruleIds === []) {
            return [];
        }

        $rows = DB::table('automation_runs')
            ->whereIn('rule_id', $ruleIds)
            ->where('status', '!=', AutomationRun::STATUS_SKIPPED)
            ->groupBy('rule_id')
            ->selectRaw('rule_id, count(*) as runs, max(id) as last_id')
            ->get();

        $last = DB::table('automation_runs')->whereIn('id', $rows->pluck('last_id'))->get(['id', 'status', 'created_at'])->keyBy('id');

        $stats = [];

        foreach ($rows as $row) {
            $run = $last[$row->last_id] ?? null;
            $stats[(int) $row->rule_id] = [
                'runs' => (int) $row->runs,
                'last' => $run?->created_at,
                'last_status' => $run?->status,
            ];
        }

        return $stats;
    }
}
