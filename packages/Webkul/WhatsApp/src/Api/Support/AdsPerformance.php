<?php

namespace Webkul\WhatsApp\Api\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Meta Ads spend (table ad_insights_daily) against what happened to the leads those ads brought,
 * matched by UTM:  utm_campaign = campaign name, utm_medium = ad set name, utm_content = ad name.
 *
 * Spend counts by the day it was spent. Leads are the ones CREATED in the period, followed to
 * wherever they are now (scheduled, won), so a lead from the last days of the period still counts
 * when it is won later.
 */
final class AdsPerformance
{
    public const LEVELS = ['campaign', 'adset', 'ad'];

    /** @var array<string, string> level => detected leads attribute code, per UTM role */
    private array $utm = [];

    public static function detectUtmAttributes(): array
    {
        $roles = [
            'campaign' => 'utm_campaign',
            'adset'    => 'utm_medium',
            'ad'       => 'utm_content',
            'source'   => 'utm_source',
        ];

        $attributes = DB::table('attributes')->where('entity_type', 'leads')->get(['id', 'code', 'name']);

        $normalize = fn (string $text) => preg_replace('/[^a-z0-9]+/', '_', mb_strtolower(trim($text)));

        $found = [];

        foreach ($roles as $role => $needle) {
            foreach ($attributes as $attribute) {
                $code = $normalize((string) $attribute->code);
                $name = $normalize((string) $attribute->name);

                if ($code === $needle || $name === $needle || str_contains($code, $needle) || str_contains($name, $needle)) {
                    $found[$role] = (int) $attribute->id;

                    break;
                }
            }
        }

        return $found;
    }

    /**
     * @return array<string, mixed>
     */
    public function report(Carbon $from, Carbon $to, string $level = 'campaign', ?string $accountId = null): array
    {
        $level = in_array($level, self::LEVELS, true) ? $level : 'campaign';

        $attributeIds = self::detectUtmAttributes();

        $spend = $this->spend($from, $to, $accountId);
        $leads = $this->leads($from, $to, $attributeIds);

        $groups = [];
        $resultKeys = [];

        // 1) Spend rows decide the groups.
        foreach ($spend as $row) {
            $key = $this->key($level, $row->campaign_name, $row->adset_name, $row->ad_name);

            $group = &$groups[$key];
            $group ??= $this->blank($level, $row->campaign_name, $row->adset_name, $row->ad_name);

            $group['spend'] += (float) $row->spend;
            $group['impressions'] += (int) $row->impressions;
            $group['clicks'] += (int) $row->clicks;

            foreach ((array) json_decode((string) $row->results, true) as $label => $count) {
                $group['meta_results'][$label] = ($group['meta_results'][$label] ?? 0) + (float) $count;
                $resultKeys[$label] = true;
            }

            unset($group);
        }

        // 2) CRM leads are laid over them, by the same key.
        $empty = ['leads' => 0, 'scheduled' => 0, 'won' => 0, 'won_value' => 0.0];
        $outside = ['unmatched' => $empty, 'no_utm' => $empty];

        foreach ($leads as $lead) {
            $isEmpty = $lead->utm_campaign === null || trim((string) $lead->utm_campaign) === '';

            $target = null;

            if (! $isEmpty) {
                $key = $this->key($level, $lead->utm_campaign, $lead->utm_adset, $lead->utm_ad);
                $target = isset($groups[$key]) ? $key : null;
            }

            $won = $lead->stage_code === 'won';
            $scheduled = (bool) $lead->scheduled;

            if ($target !== null) {
                $groups[$target]['leads']++;
                $groups[$target]['scheduled'] += $scheduled ? 1 : 0;
                $groups[$target]['won'] += $won ? 1 : 0;
                $groups[$target]['won_value'] += $won ? (float) $lead->lead_value : 0.0;

                continue;
            }

            $bucket = $isEmpty ? 'no_utm' : 'unmatched';

            $outside[$bucket]['leads']++;
            $outside[$bucket]['scheduled'] += $scheduled ? 1 : 0;
            $outside[$bucket]['won'] += $won ? 1 : 0;
            $outside[$bucket]['won_value'] += $won ? (float) $lead->lead_value : 0.0;
        }

        // 3) Derived metrics.
        $rows = array_values(array_map(fn ($group) => $this->derive($group), $groups));

        usort($rows, fn ($a, $b) => $b['spend'] <=> $a['spend']);

        $totals = $this->derive(array_reduce($rows, function ($carry, $row) {
            foreach (['spend', 'impressions', 'clicks', 'leads', 'scheduled', 'won', 'won_value'] as $field) {
                $carry[$field] += $row[$field];
            }

            foreach ($row['meta_results'] as $label => $count) {
                $carry['meta_results'][$label] = ($carry['meta_results'][$label] ?? 0) + $count;
            }

            return $carry;
        }, $this->blank($level, null, null, null)));

        return [
            'period'        => ['start' => $from->toDateString(), 'end' => $to->toDateString()],
            'level'         => $level,
            'result_labels' => array_keys($resultKeys),
            'utm_detected'  => array_keys($attributeIds),
            'totals'        => $totals,
            'rows'          => $rows,
            'crm_without_match' => $outside['unmatched'],
            'crm_without_utm'   => $outside['no_utm'],
            'last_sync'     => DB::table('ad_insights_daily')->when($accountId, fn ($q) => $q->where('account_id', $accountId))->max('updated_at'),
        ];
    }

    private function spend(Carbon $from, Carbon $to, ?string $accountId): Collection
    {
        return DB::table('ad_insights_daily')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->when($accountId, fn ($q) => $q->where('account_id', $accountId))
            ->get(['campaign_name', 'adset_name', 'ad_name', 'spend', 'impressions', 'clicks', 'results']);
    }

    /**
     * @param  array<string, int>  $attributeIds  role => attribute id
     */
    private function leads(Carbon $from, Carbon $to, array $attributeIds): Collection
    {
        $query = DB::table('leads')
            ->join('lead_pipeline_stages as stages', 'stages.id', '=', 'leads.lead_pipeline_stage_id')
            ->whereBetween('leads.created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->select([
                'leads.id', 'leads.lead_value', 'stages.code as stage_code',
                DB::raw('EXISTS (SELECT 1 FROM lead_activities la JOIN activities a ON a.id = la.activity_id WHERE la.lead_id = leads.id AND a.type = \'meeting\') as scheduled'),
            ]);

        foreach (['campaign' => 'utm_campaign', 'adset' => 'utm_adset', 'ad' => 'utm_ad'] as $role => $alias) {
            if (isset($attributeIds[$role])) {
                $query->leftJoin("attribute_values as av_$role", function ($join) use ($role, $attributeIds) {
                    $join->on("av_$role.entity_id", '=', 'leads.id')
                        ->where("av_$role.entity_type", 'leads')
                        ->where("av_$role.attribute_id", $attributeIds[$role]);
                })->addSelect("av_$role.text_value as $alias");
            } else {
                $query->addSelect(DB::raw("NULL as $alias"));
            }
        }

        return $query->get();
    }

    private function normalize(?string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $text)));
    }

    private function key(string $level, ?string $campaign, ?string $adset, ?string $ad): string
    {
        return match ($level) {
            'campaign' => $this->normalize($campaign),
            'adset'    => $this->normalize($campaign).'|'.$this->normalize($adset),
            default    => $this->normalize($campaign).'|'.$this->normalize($adset).'|'.$this->normalize($ad),
        };
    }

    private function blank(string $level, ?string $campaign, ?string $adset, ?string $ad): array
    {
        return [
            'campaign'     => $campaign,
            'adset'        => $level === 'campaign' ? null : $adset,
            'ad'           => $level === 'ad' ? $ad : null,
            'spend'        => 0.0,
            'impressions'  => 0,
            'clicks'       => 0,
            'meta_results' => [],
            'leads'        => 0,
            'scheduled'    => 0,
            'won'          => 0,
            'won_value'    => 0.0,
        ];
    }

    private function derive(array $row): array
    {
        $per = fn (float $total, int $count) => $count > 0 ? round($total / $count, 2) : null;

        $row['spend'] = round($row['spend'], 2);
        $row['won_value'] = round($row['won_value'], 2);
        $row['ctr'] = $row['impressions'] > 0 ? round($row['clicks'] / $row['impressions'] * 100, 2) : null;
        $row['cpl'] = $per($row['spend'], $row['leads']);
        $row['cost_per_scheduled'] = $per($row['spend'], $row['scheduled']);
        $row['cost_per_won'] = $per($row['spend'], $row['won']);
        $row['roas'] = $row['spend'] > 0 ? round($row['won_value'] / $row['spend'], 2) : null;
        $row['lead_to_scheduled'] = $row['leads'] > 0 ? round($row['scheduled'] / $row['leads'] * 100, 1) : null;
        $row['scheduled_to_won'] = $row['scheduled'] > 0 ? round($row['won'] / $row['scheduled'] * 100, 1) : null;

        return $row;
    }
}
