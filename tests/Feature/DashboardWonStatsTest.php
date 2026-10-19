<?php

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Admin\Helpers\Reporting\Lead as LeadReporting;

uses(DatabaseTransactions::class);

/**
 * Dashboard: "Negócios ganhos" and "Ticket médio" count only what was won, by closing date.
 */
function dashboardLead(string $stageCode, float $value, string $closedAt): void
{
    $stage = DB::table('lead_pipeline_stages')->where('code', $stageCode)->first();

    DB::table('leads')->insert([
        'title'                  => 'Dashboard test',
        'lead_value'             => $value,
        'status'                 => 1,
        'lead_pipeline_id'       => $stage->lead_pipeline_id,
        'lead_pipeline_stage_id' => $stage->id,
        'closed_at'              => $closedAt,
        'created_at'             => $closedAt,
        'updated_at'             => $closedAt,
    ]);
}

it('averages only the deals won in the period', function () {
    dashboardLead('won', 1000, '2031-03-10 10:00:00');
    dashboardLead('won', 3000, '2031-03-20 10:00:00');
    dashboardLead('lost', 9000, '2031-03-12 10:00:00');
    dashboardLead('won', 5000, '2031-02-01 10:00:00'); // another month

    $report = app(LeadReporting::class);
    $from = Carbon::parse('2031-03-01')->startOfDay();
    $to = Carbon::parse('2031-03-31')->endOfDay();

    expect($report->getWonLeads($from, $to))->toBe(2)
        ->and($report->getAverageLeadValue($from, $to))->toBe(2000.0)
        ->and($report->getAverageLeadValue(Carbon::parse('2031-04-01'), Carbon::parse('2031-04-30')))->toBe(0.0);
});

it('sums the ad spend of the days in the period', function () {
    foreach ([['2032-03-05', 100.5], ['2032-03-20', 400], ['2032-04-02', 999]] as $i => [$date, $spend]) {
        DB::table('ad_insights_daily')->insert(['account_id' => 'test', 'date' => $date, 'ad_id' => 'ad-'.$i, 'platform' => 'facebook', 'spend' => $spend, 'created_at' => now(), 'updated_at' => now()]);
    }

    $report = app(LeadReporting::class);

    expect($report->getAdSpend(Carbon::parse('2032-03-01'), Carbon::parse('2032-03-31')->endOfDay()))->toBe(500.5)
        ->and($report->getAdSpend(Carbon::parse('2032-05-01'), Carbon::parse('2032-05-31')))->toBe(0.0);
});

it('adds the tax set in Anúncios to the ad spend', function () {
    DB::table('ad_insights_daily')->insert(['account_id' => 'test', 'date' => '2033-03-05', 'ad_id' => 'ad-tax', 'platform' => 'facebook', 'spend' => 1000, 'created_at' => now(), 'updated_at' => now()]);

    $report = app(LeadReporting::class);
    $from = Carbon::parse('2033-03-01');
    $to = Carbon::parse('2033-03-31')->endOfDay();

    DB::table('ad_settings')->delete();

    expect($report->getAdSpend($from, $to))->toBe(1000.0);

    DB::table('ad_settings')->insert(['tax_percent' => 13.5, 'created_at' => now(), 'updated_at' => now()]);

    expect($report->getAdSpend($from, $to))->toBe(1135.0)
        ->and($report->getAdTaxPercent())->toBe(13.5);
});
