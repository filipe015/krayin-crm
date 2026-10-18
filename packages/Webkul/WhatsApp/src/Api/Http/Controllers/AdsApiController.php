<?php

namespace Webkul\WhatsApp\Api\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\WhatsApp\Api\Support\AdsPerformance;

/**
 * Meta Ads data: an automation (n8n) pushes daily insights; the CRM reports spend against the leads.
 */
class AdsApiController extends ApiController
{
    /**
     * Upsert daily insights, one row per ad per day.
     */
    public function ingest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'rows'                  => ['required', 'array', 'min:1', 'max:1000'],
            'rows.*.account_id'     => ['required', 'string', 'max:40'],
            'rows.*.date'           => ['required', 'date_format:Y-m-d'],
            'rows.*.ad_id'          => ['required', 'string', 'max:40'],
            'rows.*.ad_name'        => ['nullable', 'string', 'max:255'],
            'rows.*.platform'       => ['nullable', 'string', 'max:30'],
            'rows.*.funnel'         => ['nullable', 'string', 'max:120'],
            'rows.*.adset_id'       => ['nullable', 'string', 'max:40'],
            'rows.*.adset_name'     => ['nullable', 'string', 'max:255'],
            'rows.*.campaign_id'    => ['nullable', 'string', 'max:40'],
            'rows.*.campaign_name'  => ['nullable', 'string', 'max:255'],
            'rows.*.spend'          => ['nullable', 'numeric', 'min:0'],
            'rows.*.impressions'    => ['nullable', 'integer', 'min:0'],
            'rows.*.clicks'         => ['nullable', 'integer', 'min:0'],
            'rows.*.results'        => ['nullable', 'array'],
        ]);

        $now = now();

        $rows = array_map(fn ($row) => [
            'account_id'    => preg_replace('/^act_/', '', $row['account_id']),
            'date'          => $row['date'],
            'ad_id'         => $row['ad_id'],
            'platform'      => mb_strtolower(trim((string) ($row['platform'] ?? ''))),
            'funnel'        => isset($row['funnel']) && trim((string) $row['funnel']) !== '' ? trim($row['funnel']) : null,
            'ad_name'       => $row['ad_name'] ?? null,
            'adset_id'      => $row['adset_id'] ?? null,
            'adset_name'    => $row['adset_name'] ?? null,
            'campaign_id'   => $row['campaign_id'] ?? null,
            'campaign_name' => $row['campaign_name'] ?? null,
            'spend'         => round((float) ($row['spend'] ?? 0), 2),
            'impressions'   => (int) ($row['impressions'] ?? 0),
            'clicks'        => (int) ($row['clicks'] ?? 0),
            'results'       => json_encode((object) array_map('floatval', (array) ($row['results'] ?? []))),
            'created_at'    => $now,
            'updated_at'    => $now,
        ], $data['rows']);

        // Rows sent before the platform breakdown existed (platform '') are replaced by the broken-down
        // ones for the same ad and day, so spend is not counted twice.
        foreach ($rows as $row) {
            if ($row['platform'] !== '') {
                DB::table('ad_insights_daily')
                    ->where('account_id', $row['account_id'])
                    ->where('date', $row['date'])
                    ->where('ad_id', $row['ad_id'])
                    ->where('platform', '')
                    ->delete();
            }
        }

        DB::table('ad_insights_daily')->upsert(
            $rows,
            ['account_id', 'date', 'ad_id', 'platform'],
            ['funnel', 'ad_name', 'adset_id', 'adset_name', 'campaign_id', 'campaign_name', 'spend', 'impressions', 'clicks', 'results', 'updated_at'],
        );

        return $this->ok(['received' => count($rows)], [], 201);
    }

    public function performance(Request $request): JsonResponse
    {
        $data = $request->validate([
            'start'      => ['nullable', 'date'],
            'end'        => ['nullable', 'date'],
            'level'      => ['nullable', 'in:campaign,adset,ad'],
            'account_id' => ['nullable', 'string', 'max:40'],
        ]);

        $from = Carbon::parse($data['start'] ?? now()->subDays(30), config('app.timezone'))->startOfDay();
        $to = Carbon::parse($data['end'] ?? now(), config('app.timezone'))->endOfDay();

        if ($from->diffInDays($to) > 366) {
            return $this->fail('range_too_long', 'O período máximo é de 366 dias.', 422);
        }

        return $this->ok(app(AdsPerformance::class)->report(
            $from,
            $to,
            $data['level'] ?? 'campaign',
            isset($data['account_id']) ? preg_replace('/^act_/', '', $data['account_id']) : null,
        ));
    }
}
