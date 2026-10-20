<?php

namespace Webkul\WhatsApp\Api\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\Admin\Helpers\Reporting\SalesCycle;

class ReportApiController extends ApiController
{
    public function salesCycle(Request $request): JsonResponse
    {
        $request->validate([
            'mode'  => ['nullable', 'in:closing,scheduling'],
            'start' => ['nullable', 'date'],
            'end'   => ['nullable', 'date'],
        ]);

        // The reporting helper reads "start" and "end" from the request, as the dashboard does.
        return $this->ok(app(SalesCycle::class)->stats((string) $request->query('mode', 'closing')));
    }

    public function leadsSummary(Request $request): JsonResponse
    {
        $data = $request->validate([
            'start'       => ['nullable', 'date'],
            'end'         => ['nullable', 'date'],
            'pipeline_id' => ['nullable', 'integer'],
        ]);

        $from = Carbon::parse($data['start'] ?? now()->subDays(30), config('app.timezone'))->startOfDay();
        $to = Carbon::parse($data['end'] ?? now(), config('app.timezone'))->endOfDay();

        $base = fn () => DB::table('leads')
            ->join('lead_pipeline_stages as stages', 'stages.id', '=', 'leads.lead_pipeline_stage_id')
            ->when(
                $data['pipeline_id'] ?? null,
                fn ($q, $v) => $q->where('leads.lead_pipeline_id', $v),
                // Without a pipeline: the ones that count in the sales reports.
                fn ($q) => $q->whereIn('leads.lead_pipeline_id', \Webkul\Lead\Models\Pipeline::countedIds()),
            );

        $created = $base()->whereBetween('leads.created_at', [$from, $to]);

        $won = $base()->where('stages.code', 'won')->whereBetween('leads.closed_at', [$from, $to]);
        $lost = $base()->where('stages.code', 'lost')->whereBetween('leads.closed_at', [$from, $to]);

        return $this->ok([
            'period'   => ['start' => $from->toDateString(), 'end' => $to->toDateString()],
            'created'  => (clone $created)->count(),
            'won'      => ['count' => (clone $won)->count(), 'value' => (float) (clone $won)->sum('leads.lead_value')],
            'lost'     => ['count' => (clone $lost)->count()],
            'open_now' => $base()->whereNotIn('stages.code', ['won', 'lost'])->count(),
            'created_by_stage' => (clone $created)
                ->groupBy('stages.id', 'stages.name')
                ->get(['stages.id', 'stages.name', DB::raw('COUNT(leads.id) as total')]),
            'created_by_source' => (clone $created)
                ->leftJoin('lead_sources', 'lead_sources.id', '=', 'leads.lead_source_id')
                ->groupBy('lead_sources.id', 'lead_sources.name')
                ->get(['lead_sources.id as source_id', 'lead_sources.name', DB::raw('COUNT(leads.id) as total')]),
        ]);
    }
}
