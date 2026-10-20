<?php

namespace Webkul\Admin\Helpers\Reporting;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sales cycle: how many days a lead takes from being created to
 *  - "closing": being marked as won (leads.closed_at), or
 *  - "scheduling": getting its first appointment (the first activity of type "meeting").
 *
 * Only won leads count for closing. The result is grouped by the day, week or month the cycle
 * ended in, inside the dashboard's date range.
 */
class SalesCycle extends AbstractReporting
{
    public const MODES = ['closing', 'scheduling'];

    /**
     * @return array{mode: string, average: float, median: float, total: int, over_time: list<array<string, mixed>>}
     */
    public function stats(string $mode): array
    {
        $mode = in_array($mode, self::MODES, true) ? $mode : 'closing';

        $rows = $mode === 'scheduling' ? $this->schedulingRows() : $this->closingRows();

        $days = $rows->pluck('days');

        return [
            'mode'      => $mode,
            'average'   => $this->round($days->avg() ?? 0),
            'median'    => $this->round($this->median($days)),
            'total'     => $rows->count(),
            'over_time' => $this->overTime($rows),
        ];
    }

    /**
     * Won leads closed inside the period.
     *
     * @return Collection<int, object{ended_at: string, days: float}>
     */
    protected function closingRows(): Collection
    {
        $query = DB::table('leads')
            ->join('lead_pipeline_stages as stages', 'stages.id', '=', 'leads.lead_pipeline_stage_id')
            ->where('stages.code', 'won')
            ->whereNotNull('leads.closed_at')
            ->whereNotNull('leads.created_at')
            ->whereIn('leads.lead_pipeline_id', \Webkul\Lead\Models\Pipeline::reportableIds())
            ->whereBetween('leads.closed_at', [$this->startDate, $this->endDate]);

        $this->restrictToAuthorized($query);

        return $this->toRows($query->get([
            'leads.closed_at as ended_at',
            DB::raw('TIMESTAMPDIFF(SECOND, leads.created_at, leads.closed_at) as seconds'),
        ]));
    }

    /**
     * Leads whose first appointment was booked inside the period.
     *
     * @return Collection<int, object{ended_at: string, days: float}>
     */
    protected function schedulingRows(): Collection
    {
        $first = DB::table('lead_activities')
            ->join('activities', 'activities.id', '=', 'lead_activities.activity_id')
            ->where('activities.type', 'meeting')
            ->groupBy('lead_activities.lead_id')
            ->select('lead_activities.lead_id', DB::raw('MIN(activities.created_at) as booked_at'));

        $query = DB::table('leads')
            ->joinSub($first, 'first_meeting', 'first_meeting.lead_id', '=', 'leads.id')
            ->whereIn('leads.lead_pipeline_id', \Webkul\Lead\Models\Pipeline::reportableIds())
            ->whereNotNull('leads.created_at')
            ->whereBetween('first_meeting.booked_at', [$this->startDate, $this->endDate]);

        $this->restrictToAuthorized($query);

        return $this->toRows($query->get([
            'first_meeting.booked_at as ended_at',
            DB::raw('TIMESTAMPDIFF(SECOND, leads.created_at, first_meeting.booked_at) as seconds'),
        ]));
    }

    protected function restrictToAuthorized($query): void
    {
        if ($userIds = bouncer()->getAuthorizedUserIds()) {
            $query->whereIn('leads.user_id', $userIds);
        }
    }

    /**
     * @return Collection<int, object{ended_at: string, days: float}>
     */
    protected function toRows(Collection $rows): Collection
    {
        return $rows
            ->map(fn ($row) => (object) [
                'ended_at' => $row->ended_at,
                // A negative gap (clock skew, edited dates) is not a cycle: count it as same-day.
                'days'     => max(0, (int) $row->seconds) / 86400,
            ])
            ->values();
    }

    /**
     * Average and median per day, week or month, depending on how long the period is.
     *
     * @return list<array<string, mixed>>
     */
    protected function overTime(Collection $rows): array
    {
        $span = $this->startDate->diffInDays($this->endDate);

        $period = $span <= 14 ? 'day' : ($span <= 120 ? 'week' : 'month');

        $buckets = [];
        $cursor = $this->startDate->copy();

        while ($cursor <= $this->endDate) {
            $key = $this->bucketKey($cursor, $period);

            $buckets[$key] = ['label' => $this->bucketLabel($cursor, $period), 'days' => collect()];

            $cursor = match ($period) {
                'day'   => $cursor->addDay(),
                'week'  => $cursor->addWeek(),
                default => $cursor->addMonthNoOverflow(),
            };
        }

        // The last step can stop short of the period's final week or month.
        $endKey = $this->bucketKey($this->endDate, $period);

        $buckets[$endKey] ??= ['label' => $this->bucketLabel($this->endDate, $period), 'days' => collect()];

        ksort($buckets);

        foreach ($rows as $row) {
            $key = $this->bucketKey(Carbon::parse($row->ended_at), $period);

            if (isset($buckets[$key])) {
                $buckets[$key]['days']->push($row->days);
            }
        }

        return array_values(array_map(fn ($bucket) => [
            'label'   => $bucket['label'],
            'average' => $this->round($bucket['days']->avg() ?? 0),
            'median'  => $this->round($this->median($bucket['days'])),
            'count'   => $bucket['days']->count(),
        ], $buckets));
    }

    protected function bucketKey(Carbon $date, string $period): string
    {
        return match ($period) {
            'day'   => $date->format('Y-m-d'),
            'week'  => $date->copy()->startOfWeek()->format('Y-m-d'),
            default => $date->format('Y-m'),
        };
    }

    protected function bucketLabel(Carbon $date, string $period): string
    {
        return match ($period) {
            'day'   => $date->translatedFormat('d M'),
            'week'  => $date->copy()->startOfWeek()->translatedFormat('d M'),
            default => $date->translatedFormat('M Y'),
        };
    }

    protected function median(Collection $values): float
    {
        $sorted = $values->sort()->values();
        $count = $sorted->count();

        if ($count === 0) {
            return 0;
        }

        $middle = intdiv($count, 2);

        return $count % 2 ? (float) $sorted[$middle] : ((float) $sorted[$middle - 1] + (float) $sorted[$middle]) / 2;
    }

    protected function round(float $days): float
    {
        return round($days, 1);
    }
}
