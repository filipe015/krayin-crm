<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\WhatsApp\Api\Models\ApiKey;
use Webkul\WhatsApp\Api\Support\AdsOverview;

uses(DatabaseTransactions::class);

/**
 * Meta Ads insights by platform and funnel, and the Anúncios dashboard built from them.
 * Needs a migrated and seeded database.
 */
function adsHeaders(): array
{
    [, $token] = ApiKey::issue('ads-test', ['read', 'write'], null);

    return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
}

function adsRow(array $overrides = []): array
{
    return array_merge([
        'account_id'    => 'act_999',
        'date'          => '2026-03-10',
        'campaign_id'   => 'c1',
        'campaign_name' => 'Camp Online',
        'adset_id'      => 's1',
        'adset_name'    => 'Conjunto',
        'ad_id'         => 'a1',
        'ad_name'       => 'Anuncio',
        'spend'         => 100,
        'impressions'   => 1000,
        'clicks'        => 50,
        'results'       => ['lead' => 7, 'Lead - Online' => 5],
    ], $overrides);
}

beforeEach(function () {
    test()->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
});

it('stores one row per platform and replaces the old row without platform', function () {
    test()->postJson('/api/v1/ads/insights', ['rows' => [adsRow()]], adsHeaders())->assertStatus(201);

    expect(DB::table('ad_insights_daily')->where('account_id', '999')->count())->toBe(1);

    test()->postJson('/api/v1/ads/insights', ['rows' => [
        adsRow(['platform' => 'Instagram', 'funnel' => 'Lead - Online', 'spend' => 60]),
        adsRow(['platform' => 'facebook', 'funnel' => 'Lead - Online', 'spend' => 40]),
    ]], adsHeaders())->assertStatus(201);

    $rows = DB::table('ad_insights_daily')->where('account_id', '999')->orderBy('platform')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('platform')->all())->toBe(['facebook', 'instagram'])
        ->and($rows->first()->funnel)->toBe('Lead - Online')
        ->and((float) $rows->sum('spend'))->toBe(100.0);

    // Sending the same day again updates instead of duplicating.
    test()->postJson('/api/v1/ads/insights', ['rows' => [adsRow(['platform' => 'instagram', 'funnel' => 'Lead - Online', 'spend' => 70])]], adsHeaders())->assertStatus(201);

    expect(DB::table('ad_insights_daily')->where('account_id', '999')->count())->toBe(2)
        ->and((float) DB::table('ad_insights_daily')->where('account_id', '999')->where('platform', 'instagram')->value('spend'))->toBe(70.0);
});

it('builds the monthly dashboard: spend, tax, funnel leads and platforms', function () {
    AdsOverview::saveTaxPercent(10);

    test()->postJson('/api/v1/ads/insights', ['rows' => [
        adsRow(['platform' => 'instagram', 'funnel' => 'Lead - Online', 'spend' => 60, 'clicks' => 30, 'results' => ['lead' => 9, 'Lead - Online' => 6]]),
        adsRow(['platform' => 'facebook', 'funnel' => 'Lead - Online', 'spend' => 40, 'clicks' => 20, 'results' => ['lead' => 4, 'Lead - Online' => 4]]),
        adsRow(['ad_id' => 'a2', 'campaign_name' => 'Camp Presencial', 'platform' => 'instagram', 'funnel' => 'Lead - Presencial', 'spend' => 50, 'results' => ['Lead - Presencial' => 5]]),
    ]], adsHeaders())->assertStatus(201);

    $report = app(AdsOverview::class)->month(\Illuminate\Support\Carbon::parse('2026-03-01'), '999');

    expect($report['summary']['spend'])->toBe(150.0)
        ->and($report['summary']['tax'])->toBe(15.0)
        ->and($report['summary']['total_cost'])->toBe(165.0)
        // Meta leads count the funnel's own conversion, not the generic "lead" action.
        ->and($report['summary']['leads_meta'])->toBe(15)
        ->and($report['summary']['cpl_meta'])->toBe(10.0)
        ->and(collect($report['funnels'])->pluck('name')->all())->toContain('Lead - Online', 'Lead - Presencial')
        ->and(collect($report['platforms'])->pluck('platform')->sort()->values()->all())->toBe(['facebook', 'instagram'])
        ->and($report['trend'])->toHaveCount(6)
        ->and(collect($report['daily']['days'])->firstWhere('date', '2026-03-10')['spend'])->toBe(150.0)
        ->and($report['campaigns'][0]['campaign'])->toBe('Camp Online');
});

it('matches CRM leads to campaigns ignoring copies and numbering, reads UTMs from the description, and counts sales and CAC', function () {
    $now = now();
    $base = ['entity_type' => 'leads', 'is_user_defined' => 1, 'is_required' => 0, 'is_unique' => 0, 'quick_add' => 0, 'sort_order' => 99, 'created_at' => $now, 'updated_at' => $now, 'type' => 'text'];
    $campaignAttr = DB::table('attributes')->insertGetId($base + ['code' => 'utm_campaign_t'.uniqid(), 'name' => 'UTM Campaign']);
    DB::table('attributes')->insertGetId($base + ['code' => 'utm_source_t'.uniqid(), 'name' => 'UTM Source']);

    $pipeline = DB::table('lead_pipelines')->where('is_default', 1)->value('id');
    $stage = fn (string $code) => DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $pipeline)->where('code', $code)->value('id');
    $first = DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $pipeline)->orderBy('sort_order')->value('id');

    $lead = function (string $title, int $stageId, ?string $campaign, ?string $description = null, float $value = 0) use ($pipeline, $now, $campaignAttr) {
        $id = DB::table('leads')->insertGetId(['title' => $title, 'description' => $description, 'status' => 1, 'lead_value' => $value, 'lead_pipeline_id' => $pipeline, 'lead_pipeline_stage_id' => $stageId, 'created_at' => $now, 'updated_at' => $now]);

        if ($campaign !== null) {
            DB::table('attribute_values')->insert(['entity_type' => 'leads', 'entity_id' => $id, 'attribute_id' => $campaignAttr, 'text_value' => $campaign]);
        }

        return $id;
    };

    $month = $now->copy()->startOfMonth();

    test()->postJson('/api/v1/ads/insights', ['rows' => [
        adsRow(['date' => $month->toDateString(), 'platform' => 'instagram', 'funnel' => 'Lead - Online', 'campaign_name' => '02 - [LEADS ONLINE] - [ESCALA]', 'spend' => 300, 'results' => ['Lead - Online' => 10]]),
        adsRow(['date' => $month->toDateString(), 'ad_id' => 'a2', 'platform' => 'instagram', 'funnel' => 'Lead - Bari', 'campaign_name' => '15 - [BARI PRA SEMPRE]', 'spend' => 100, 'results' => ['Lead - Bari' => 4]]),
    ]], adsHeaders())->assertStatus(201);

    // A copy of the campaign, with a different number, still counts; one is won.
    $lead('copia', $first, '02 - [LEADS ONLINE] - [ESCALA] — Cópia');
    $lead('ganho', $stage('won'), '12 - [LEADS ONLINE] - [ESCALA]', null, 1500);
    // From the LP: UTM only in the description.
    $lead('lp', $first, null, "Prioridade: alta\nUTM source: ig\nUTM campaign: 15 - [BARI PRA SEMPRE]\nUTM medium: -");
    $lead('perdido', $stage('lost'), '02 - [LEADS ONLINE] - [ESCALA]');
    // Organic and untracked.
    $lead('organico', $first, 'link_bio');
    $lead('sem utm', $first, null, 'sem nada');

    $report = app(AdsOverview::class)->month($month, '999');
    $funnels = collect($report['funnels'])->keyBy('name');

    expect($report['summary']['leads_crm'])->toBe(4)
        ->and($report['summary']['unmatched'])->toBe(1)
        ->and($report['summary']['no_utm'])->toBe(1)
        ->and($report['summary']['won'])->toBe(1)
        ->and($report['summary']['won_value'])->toBe(1500.0)
        ->and($report['summary']['cac'])->toBe(400.0)
        ->and($report['summary']['mql'])->toBe(3)
        ->and($funnels['Lead - Online']['leads_crm'])->toBe(3)
        ->and($funnels['Lead - Online']['cpl_meta'])->toBe(30.0)
        ->and($funnels['Lead - Bari']['leads_crm'])->toBe(1)
        ->and($funnels['Lead - Bari']['leads_meta'])->toBe(4);
});
