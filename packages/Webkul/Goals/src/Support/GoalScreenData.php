<?php

namespace Webkul\Goals\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\Goals\Models\GoalChange;

/**
 * Everything the Metas settings screen needs for a month, in one array: the plans (with what they
 * mean per week and per day), the working days, the funnels' gap to the general plan, the step
 * rules and the lists to choose from (stages, tags, funnels).
 */
final class GoalScreenData
{
    public const PER_PAGE = 30;

    /**
     * @return array<string, mixed>
     */
    public static function for(string $month): array
    {
        $workingDays = GoalPlanService::workingDays($month);
        $plans = GoalPlanService::plans($month);

        return [
            'month'             => $month,
            'month_label'       => CarbonImmutable::parse($month.'-01')->locale('pt_BR')->translatedFormat('F \d\e Y'),
            'plans'             => $plans->map(fn ($plan) => self::plan($plan, $month, $workingDays))->values()->all(),
            'working_days'      => $workingDays,
            'days'              => self::days($month, $workingDays),
            'gap'               => GoalPlanService::funnelGap($month),
            'settings'          => GoalSettingsService::get(),
            'alerts'            => GoalAlertSettings::get(),
            'suggested_funnels' => self::suggestedFunnels(),
            'options'           => self::options(),
        ];
    }

    /**
     * @return array{items: list<array>, has_more: bool}
     */
    public static function history(?string $month, int $page): array
    {
        $rows = GoalChange::query()
            ->leftJoin('users', 'users.id', '=', 'goal_changes.user_id')
            ->when($month, fn ($query) => $query->where('goal_changes.year_month', $month))
            ->orderByDesc('goal_changes.changed_at')
            ->orderByDesc('goal_changes.id')
            ->offset(($page - 1) * self::PER_PAGE)
            ->limit(self::PER_PAGE + 1)
            ->get(['goal_changes.*', 'users.name as user_name']);

        return [
            'items'    => GoalChangeFormatter::format($rows->take(self::PER_PAGE)),
            'has_more' => $rows->count() > self::PER_PAGE,
        ];
    }

    /**
     * @param  list<string>  $workingDays
     * @return array<string, mixed>
     */
    private static function plan($plan, string $month, array $workingDays): array
    {
        $values = $plan->only([...GoalPlanService::INPUTS, ...GoalPlanService::TARGETS]);
        $daily = GoalPlanService::daily($plan, $workingDays);

        return [
            'funnel'          => $plan->funnel,
            'values'          => $values,
            'fixed'           => $plan->overrides ?? [],
            'revenue_implied' => $plan->sales_target !== null && $plan->ticket ? $plan->sales_target * $plan->ticket : null,
            'budget_needed'   => $plan->leads_target !== null && $plan->cpl_target ? $plan->leads_target * $plan->cpl_target : null,
            'weeks'           => array_map(fn (array $week) => [
                'week'    => $week['week'],
                'start'   => $week['start'],
                'end'     => $week['end'],
                'working' => count(array_intersect($week['days'], $workingDays)),
                'targets' => self::sum($daily, $week['days']),
            ], GoalCalendar::weeks($month)),
            'days'            => array_map(fn (string $date) => ['date' => $date, 'targets' => self::sum($daily, [$date])], GoalCalendar::days($month)),
        ];
    }

    /**
     * @param  array<string, array<string, int>>  $daily
     * @param  list<string>  $dates
     * @return array<string, int>
     */
    private static function sum(array $daily, array $dates): array
    {
        $totals = [];

        foreach ($daily as $metric => $days) {
            $totals[$metric] = array_sum(array_intersect_key($days, array_flip($dates)));
        }

        return $totals;
    }

    /**
     * @param  list<string>  $workingDays
     * @return list<array{date: string, weekday: int, working: bool}>
     */
    private static function days(string $month, array $workingDays): array
    {
        return array_map(fn (string $date) => [
            'date'    => $date,
            'weekday' => (int) CarbonImmutable::parse($date)->isoWeekday(),
            'working' => in_array($date, $workingDays, true),
        ], GoalCalendar::days($month));
    }

    /**
     * Funnels the ads already know (Lead - Online, ...), to offer when adding a funnel to the plan.
     *
     * @return list<string>
     */
    private static function suggestedFunnels(): array
    {
        if (! Schema::hasTable('ad_insights_daily')) {
            return [];
        }

        return DB::table('ad_insights_daily')->whereNotNull('funnel')->where('funnel', '!=', '')->distinct()->orderBy('funnel')->pluck('funnel')->all();
    }

    /**
     * @return array{pipelines: list<array>, tags: list<array>}
     */
    private static function options(): array
    {
        $stages = DB::table('lead_pipeline_stages')->orderBy('sort_order')->get(['id', 'name', 'lead_pipeline_id'])->groupBy('lead_pipeline_id');

        return [
            'pipelines' => DB::table('lead_pipelines')->orderBy('id')->get(['id', 'name'])->map(fn ($pipeline) => [
                'id'     => (int) $pipeline->id,
                'name'   => $pipeline->name,
                'stages' => ($stages[$pipeline->id] ?? collect())->map(fn ($stage) => ['id' => (int) $stage->id, 'name' => $stage->name])->values()->all(),
            ])->all(),
            'tags'      => DB::table('tags')->orderBy('name')->get(['id', 'name'])->map(fn ($tag) => ['id' => (int) $tag->id, 'name' => $tag->name])->all(),
        ];
    }
}
