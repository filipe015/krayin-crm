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
