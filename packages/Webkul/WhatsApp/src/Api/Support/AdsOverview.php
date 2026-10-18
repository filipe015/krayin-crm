<?php

namespace Webkul\WhatsApp\Api\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Anúncios dashboard: one month of Meta spend and results next to what the CRM did with the leads
 * (matched by UTM campaign), with the same month-by-month trend, daily curve, funnel, platform and
 * campaign breakdowns.
 *
 * Funnel = the custom conversion the ad set optimises for (sent by the automation). A lead gets the
 * funnel of the campaign its UTM names. "Leads Meta" are the conversions Meta reports for that funnel
 * (falling back to the generic "lead" action); "Leads CRM" are the CRM leads created in the period whose
 * UTM campaign is one of the advertised campaigns. MQL = those not lost; meetings = those with a meeting.
 */
final class AdsOverview
{
    private const TREND_MONTHS = 6;

    /** @var array<string, string> normalized campaign name => funnel */
    private array $campaignFunnel = [];

    /** @var array<string, int> role => attribute id */
    private array $attributes = [];

    public function __construct()
    {
        $this->attributes = AdsPerformance::detectUtmAttributes();
    }

    public static function taxPercent(): float
    {
        return (float) (DB::table('ad_settings')->value('tax_percent') ?? 0);
    }

    public static function saveTaxPercent(float $percent): void
    {
        $now = now();

        if (DB::table('ad_settings')->exists()) {
            DB::table('ad_settings')->update(['tax_percent' => $percent, 'updated_at' => $now]);

            return;
        }

        DB::table('ad_settings')->insert(['tax_percent' => $percent, 'created_at' => $now, 'updated_at' => $now]);
    }

    /**
     * @return array<string, mixed>
     */
    public function month(Carbon $month, ?string $accountId = null): array
    {
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth();
        $tax = self::taxPercent();

        $this->loadCampaignFunnels($accountId);

        $current = $this->period($from, $to, $accountId, $tax);

        $trend = [];

        for ($i = self::TREND_MONTHS - 1; $i >= 0; $i--) {
            $start = $month->copy()->subMonthsNoOverflow($i)->startOfMonth();
            $data = $i === 0 ? $current : $this->period($start, $start->copy()->endOfMonth(), $accountId, $tax);

            $trend[] = ['month' => $start->format('Y-m'), 'label' => $start->locale('pt_BR')->translatedFormat('M/y')] + $data['summary'];
        }

        return [
            'month'       => $from->format('Y-m'),
            'label'       => $from->locale('pt_BR')->translatedFormat('F \d\e Y'),
            'tax_percent' => $tax,
            'summary'     => $current['summary'],
            'funnels'     => $current['funnels'],
            'trend'       => $trend,
            'daily'       => $this->daily($from, $to, $accountId, $current['funnels']),
            'platforms'   => $current['platforms'],
            'origins'     => $current['origins'],
            'campaigns'   => $current['campaigns'],
            'last_sync'   => DB::table('ad_insights_daily')->when($accountId, fn ($q) => $q->where('account_id', $accountId))->max('updated_at'),
            'utm_detected' => array_keys($this->attributes),
        ];
    }

    /**
     * @return array{summary: array, funnels: array, platforms: array, origins: array, campaigns: array}
     */
    private function period(Carbon $from, Carbon $to, ?string $accountId, float $tax): array
    {
        $spendRows = $this->spendRows($from, $to, $accountId);
        $leads = $this->leads($from, $to);

        // Spend side, by funnel / platform / campaign.
        $funnels = [];
        $platforms = [];
        $campaigns = [];
        $totalSpend = 0.0;
        $totalMeta = 0.0;

        foreach ($spendRows as $row) {
            $funnel = $row->funnel ?: null;
            $spend = (float) $row->spend;
            $meta = $this->metaLeads($row);
            $campaignKey = $this->normalize($row->campaign_name);
            $funnelKey = $funnel ?? '';
            $platformKey = $row->platform !== '' ? $row->platform : '';

            $totalSpend += $spend;
            $totalMeta += $meta;

            $f = &$funnels[$funnelKey];
            $f ??= $this->blankFunnel($funnel);
            $f['spend'] += $spend;
            $f['leads_meta'] += $meta;
            unset($f);

            $p = &$platforms[$platformKey];
            $p ??= ['platform' => $platformKey, 'spend' => 0.0, 'clicks' => 0, 'impressions' => 0, 'leads_meta' => 0.0];
            $p['spend'] += $spend;
            $p['clicks'] += (int) $row->clicks;
            $p['impressions'] += (int) $row->impressions;
            $p['leads_meta'] += $meta;
            unset($p);

            $c = &$campaigns[$campaignKey];
            $c ??= ['campaign' => $row->campaign_name, 'funnel' => $funnel, 'spend' => 0.0, 'clicks' => 0, 'impressions' => 0, 'leads_meta' => 0.0, 'leads_crm' => 0];
            $c['spend'] += $spend;
            $c['clicks'] += (int) $row->clicks;
            $c['impressions'] += (int) $row->impressions;
            $c['leads_meta'] += $meta;
            $c['funnel'] ??= $funnel;
            unset($c);
        }

        // CRM side.
        $crm = ['leads' => 0, 'mql' => 0, 'disqualified' => 0, 'scheduled' => 0];
        $noUtm = 0;
        $unmatched = 0;
        $origins = [];

        foreach ($leads as $lead) {
            $origin = $this->originLabel($lead->utm_source);
            $origins[$origin] = ($origins[$origin] ?? 0) + 1;

            $campaign = $this->normalize($lead->utm_campaign);

            if ($campaign === '') {
                $noUtm++;

                continue;
            }

            if (! array_key_exists($campaign, $this->campaignFunnel) && ! isset($campaigns[$campaign])) {
                $unmatched++;

                continue;
            }

            $funnel = $campaigns[$campaign]['funnel'] ?? $this->campaignFunnel[$campaign] ?? null;
            $funnelKey = $funnel ?? '';
            $lost = $lead->stage_code === 'lost';

            $f = &$funnels[$funnelKey];
            $f ??= $this->blankFunnel($funnel);
            $f['leads_crm']++;
            $f['mql'] += $lost ? 0 : 1;
            $f['disqualified'] += $lost ? 1 : 0;
            $f['scheduled'] += $lead->scheduled ? 1 : 0;
            unset($f);

            if (isset($campaigns[$campaign])) {
                $campaigns[$campaign]['leads_crm']++;
            }

            $crm['leads']++;
            $crm['mql'] += $lost ? 0 : 1;
            $crm['disqualified'] += $lost ? 1 : 0;
            $crm['scheduled'] += $lead->scheduled ? 1 : 0;
        }

        $summary = $this->derive([
            'spend'        => $totalSpend,
            'leads_meta'   => $totalMeta,
            'leads_crm'    => $crm['leads'],
            'mql'          => $crm['mql'],
            'disqualified' => $crm['disqualified'],
            'scheduled'    => $crm['scheduled'],
        ], $tax) + ['no_utm' => $noUtm, 'unmatched' => $unmatched];

        $funnelRows = array_values(array_map(fn ($f) => $this->derive($f, $tax) + ['name' => $f['name']], $funnels));
        usort($funnelRows, fn ($a, $b) => [$b['spend'], $b['leads_crm']] <=> [$a['spend'], $a['leads_crm']]);

        $platformRows = array_values(array_map(function ($p) {
            $p['spend'] = round($p['spend'], 2);
            $p['cpl'] = $p['leads_meta'] > 0 ? round($p['spend'] / $p['leads_meta'], 2) : null;
            $p['leads_meta'] = (int) round($p['leads_meta']);

            return $p;
        }, $platforms));
        usort($platformRows, fn ($a, $b) => $b['spend'] <=> $a['spend']);

        $campaignRows = array_values(array_map(function ($c) {
            $c['spend'] = round($c['spend'], 2);
            $c['cpl'] = $c['leads_meta'] > 0 ? round($c['spend'] / $c['leads_meta'], 2) : null;
            $c['leads_meta'] = (int) round($c['leads_meta']);

            return $c;
        }, $campaigns));
        usort($campaignRows, fn ($a, $b) => $b['spend'] <=> $a['spend']);

        arsort($origins);

        return [
            'summary'   => $summary,
            'funnels'   => $funnelRows,
            'platforms' => $platformRows,
            'campaigns' => $campaignRows,
            'origins'   => array_map(fn ($label, $count) => ['source' => $label, 'count' => $count], array_keys($origins), array_values($origins)),
        ];
    }

    /**
     * Spend and Meta leads by day, with a line per funnel.
     *
     * @return array{days: list<array>, funnels: list<string>}
     */
    private function daily(Carbon $from, Carbon $to, ?string $accountId, array $funnels): array
    {
        $names = array_values(array_filter(array_map(fn ($f) => $f['name'], $funnels), fn ($name) => $name !== null));
        $days = [];

        foreach ($this->spendRows($from, $to, $accountId) as $row) {
            $day = &$days[$row->date];
            $day ??= ['date' => $row->date, 'spend' => 0.0, 'total' => 0.0, 'by_funnel' => []];

            $meta = $this->metaLeads($row);

            $day['spend'] += (float) $row->spend;
            $day['total'] += $meta;

            if ($row->funnel) {
                $day['by_funnel'][$row->funnel] = ($day['by_funnel'][$row->funnel] ?? 0) + $meta;
            }

            unset($day);
        }

        $cursor = $from->copy();
        $out = [];

        // No curve for days that have not happened yet.
        $last = $to->lt(now()->endOfDay()) ? $to : now()->endOfDay();

        while ($cursor->lte($last)) {
            $key = $cursor->toDateString();
            $day = $days[$key] ?? ['date' => $key, 'spend' => 0.0, 'total' => 0.0, 'by_funnel' => []];

            $out[] = [
                'date'      => $key,
                'spend'     => round($day['spend'], 2),
                'total'     => (int) round($day['total']),
                'by_funnel' => array_map(fn ($value) => (int) round($value), $day['by_funnel']),
            ];

            $cursor->addDay();
        }

        return ['days' => $out, 'funnels' => $names];
    }

    private function spendRows(Carbon $from, Carbon $to, ?string $accountId): Collection
    {
        return DB::table('ad_insights_daily')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->when($accountId, fn ($q) => $q->where('account_id', $accountId))
            ->get(['date', 'platform', 'funnel', 'campaign_name', 'spend', 'impressions', 'clicks', 'results']);
    }

    private function metaLeads(object $row): float
    {
        $results = (array) json_decode((string) $row->results, true);

        if ($row->funnel && isset($results[$row->funnel])) {
            return (float) $results[$row->funnel];
        }

        return (float) ($results['lead'] ?? 0);
    }

    private function loadCampaignFunnels(?string $accountId): void
    {
        $this->campaignFunnel = [];

        $names = DB::table('ad_insights_daily')
            ->when($accountId, fn ($q) => $q->where('account_id', $accountId))
            ->whereNotNull('campaign_name')
            ->groupBy('campaign_name')
            ->selectRaw('campaign_name, max(funnel) as funnel')
            ->get();

        foreach ($names as $row) {
            $this->campaignFunnel[$this->normalize($row->campaign_name)] = $row->funnel ?: null;
        }
    }

    private function leads(Carbon $from, Carbon $to): Collection
    {
        $query = DB::table('leads')
            ->join('lead_pipeline_stages as stages', 'stages.id', '=', 'leads.lead_pipeline_stage_id')
            ->whereBetween('leads.created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->select([
                'leads.id', 'stages.code as stage_code',
                DB::raw('EXISTS (SELECT 1 FROM lead_activities la JOIN activities a ON a.id = la.activity_id WHERE la.lead_id = leads.id AND a.type = \'meeting\') as scheduled'),
            ]);

        foreach (['campaign' => 'utm_campaign', 'source' => 'utm_source'] as $role => $alias) {
            if (isset($this->attributes[$role])) {
                $attributeId = $this->attributes[$role];

                $query->leftJoin("attribute_values as av_$role", function ($join) use ($role, $attributeId) {
                    $join->on("av_$role.entity_id", '=', 'leads.id')
                        ->where("av_$role.entity_type", 'leads')
                        ->where("av_$role.attribute_id", $attributeId);
                })->addSelect("av_$role.text_value as $alias");
            } else {
                $query->addSelect(DB::raw("NULL as $alias"));
            }
        }

        return $query->get();
    }

    private function blankFunnel(?string $name): array
    {
        return ['name' => $name, 'spend' => 0.0, 'leads_meta' => 0.0, 'leads_crm' => 0, 'mql' => 0, 'disqualified' => 0, 'scheduled' => 0];
    }

    /**
     * @param  array<string, float|int>  $base
     */
    private function derive(array $base, float $tax): array
    {
        $spend = round((float) $base['spend'], 2);
        $metaLeads = (int) round((float) $base['leads_meta']);
        $crmLeads = (int) ($base['leads_crm'] ?? 0);
        $mql = (int) ($base['mql'] ?? 0);
        $scheduled = (int) ($base['scheduled'] ?? 0);
        $taxValue = round($spend * $tax / 100, 2);
        $per = fn (float $amount, int $count) => $count > 0 ? round($amount / $count, 2) : null;
        $pct = fn (int $part, int $whole) => $whole > 0 ? round($part / $whole * 100, 1) : null;

        return [
            'spend'              => $spend,
            'tax'                => $taxValue,
            'total_cost'         => round($spend + $taxValue, 2),
            'leads_meta'         => $metaLeads,
            'cpl_meta'           => $per($spend, $metaLeads),
            'leads_crm'          => $crmLeads,
            'cpl_crm'            => $per($spend, $crmLeads),
            'mql'                => $mql,
            'disqualified'       => (int) ($base['disqualified'] ?? 0),
            'qualification_rate' => $pct($mql, $crmLeads),
            'scheduled'          => $scheduled,
            'lead_to_scheduled'  => $pct($scheduled, $crmLeads),
            'cost_per_scheduled' => $per($spend, $scheduled),
        ];
    }

    private function originLabel(?string $source): string
    {
        $source = $this->normalize($source);

        return match (true) {
            $source === '' => 'Origem não reconhecida',
            in_array($source, ['ig', 'instagram', 'instagram_feed', 'instagram_stories', 'instagram_reels'], true), str_contains($source, 'instagram') => 'Instagram',
            in_array($source, ['fb', 'facebook', 'face'], true), str_contains($source, 'facebook') => 'Facebook',
            default => mb_convert_case($source, MB_CASE_TITLE),
        };
    }

    private function normalize(?string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $text)));
    }
}
