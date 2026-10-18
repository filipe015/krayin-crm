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
