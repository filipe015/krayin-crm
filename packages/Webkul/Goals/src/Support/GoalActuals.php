<?php

namespace Webkul\Goals\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\Goals\Models\LeadEvent;
use Webkul\WhatsApp\Api\Support\AdsOverview;

/**
 * What really happened between two dates, step by step: deals created, deals that reached each step
 * (by the rules of the settings, counted by the day it happened), sales and their value (by closing
 * date) and what was spent on ads. For a funnel, only the deals of its campaigns.
 */
final class GoalActuals
{
    /**
     * @return array{leads: int, qualified: int, scheduled: int, attended: int, sales: int, revenue: int, spend: int}
     */
    public static function between(CarbonImmutable $from, CarbonImmutable $to, string $funnel = ''): array
    {
        $from = $from->startOfDay();
        $to = $to->endOfDay();
        $rules = GoalSettingsService::get()['step_rules'];

        $steps = [
            'leads'     => self::ids(LeadEvent::CREATED, $from, $to),
            'qualified' => self::stepIds($rules['qualified'] ?? [], $from, $to),
            'scheduled' => self::stepIds($rules['scheduled'] ?? [], $from, $to),
            'attended'  => self::stepIds($rules['attended'] ?? [], $from, $to),
        ];

        $sales = self::sales($from, $to);

        if ($funnel !== '') {
            $belongs = (new AdsOverview)->funnelsOfLeads(array_values(array_unique(array_merge(array_merge(...array_values($steps)), array_keys($sales)))));
            $keep = fn (int $id) => ($belongs[$id] ?? null) === $funnel;

            $steps = array_map(fn (array $ids) => array_values(array_filter($ids, $keep)), $steps);
            $sales = array_filter($sales, fn ($value, $id) => $keep((int) $id), ARRAY_FILTER_USE_BOTH);
        }

        return [
            'leads'     => count($steps['leads']),
            'qualified' => count($steps['qualified']),
            'scheduled' => count($steps['scheduled']),
            'attended'  => count($steps['attended']),
            'sales'     => count($sales),
            'revenue'   => (int) round(array_sum($sales)),
            'spend'     => self::spend($from, $to, $funnel),
        ];
    }

    /**
     * A deal counts once in a step, however many of its rules it met.
     *
     * @param  list<array>  $rules
     * @return list<int>
     */
    private static function stepIds(array $rules, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ids = [];

        foreach ($rules as $rule) {
            $found = match ($rule['type'] ?? null) {
                'stage'       => self::ids(LeadEvent::STAGE_ENTERED, $from, $to, fn ($q) => $q->whereIn('stage_id', $rule['stage_ids'] ?? [])),
                'tag'         => self::ids(LeadEvent::TAG_ADDED, $from, $to, fn ($q) => $q->whereIn('tag_id', $rule['tag_ids'] ?? [])),
                'appointment' => self::ids(LeadEvent::APPOINTMENT_CREATED, $from, $to),
                default       => [],
            };

            $ids = array_merge($ids, $found);
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<int>
     */
    private static function ids(string $kind, CarbonImmutable $from, CarbonImmutable $to, ?callable $where = null): array
    {
        $query = LeadEvent::query()->where('kind', $kind)->whereBetween('occurred_at', [$from, $to]);

        if ($where) {
            $where($query);
        }

        return $query->distinct()->pluck('lead_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Deals won, by closing date. lead id => value.
     *
     * @return array<int, float>
     */
    private static function sales(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DB::table('leads')
            ->join('lead_pipeline_stages as stages', 'stages.id', '=', 'leads.lead_pipeline_stage_id')
            ->where('stages.code', 'won')
            ->whereBetween('leads.closed_at', [$from, $to])
            ->pluck('leads.lead_value', 'leads.id')
            ->map(fn ($value) => (float) $value)
            ->all();
    }

    private static function spend(CarbonImmutable $from, CarbonImmutable $to, string $funnel): int
    {
        if (! Schema::hasTable('ad_insights_daily')) {
            return 0;
        }

        return (int) round((float) DB::table('ad_insights_daily')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->when($funnel !== '', fn ($query) => $query->where('funnel', $funnel))
            ->sum('spend'));
    }
}
