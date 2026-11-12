<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Lead\Models\Pipeline;

uses(DatabaseTransactions::class);

/**
 * The cards "Total de negócios" and "Negócios ganhos" on the dashboard link to a list of exactly the deals they counted.
 */
function dashboardLead(int $stageId, string $createdAt, ?string $closedAt = null): int
{
    $stage = DB::table('lead_pipeline_stages')->find($stageId);

    return DB::table('leads')->insertGetId([
        'title' => 'ZZ dash '.uniqid(), 'lead_value' => 100, 'status' => 1, 'lead_pipeline_id' => $stage->lead_pipeline_id,
        'lead_pipeline_stage_id' => $stageId, 'created_at' => $createdAt, 'updated_at' => $createdAt, 'closed_at' => $closedAt,
    ]);
}

it('lists the same deals the dashboard counts, for total and won', function () {
    $counted = Pipeline::countedIds();
    $won = DB::table('lead_pipeline_stages')->whereIn('lead_pipeline_id', $counted)->where('code', 'won')->value('id');
    $open = DB::table('lead_pipeline_stages')->whereIn('lead_pipeline_id', $counted)->whereNotIn('code', ['won', 'lost'])->value('id');

    $period = ['start' => '2020-03-01', 'end' => '2020-03-31'];

    $inOpen = dashboardLead($open, '2020-03-10 10:00:00');
    $inWon = dashboardLead($won, '2020-03-05 10:00:00', '2020-03-20 10:00:00');
    $outside = dashboardLead($open, '2020-01-10 10:00:00');

    $admin = getDefaultAdmin();

    $total = $this->actingAs($admin, 'user')->get(route('admin.dashboard.leads', $period + ['metric' => 'total']))->assertOk();
    $totalIds = $total->viewData('leads')->pluck('id')->all();
    expect($totalIds)->toContain($inOpen, $inWon)->not->toContain($outside);

    $wonResponse = $this->actingAs($admin, 'user')->get(route('admin.dashboard.leads', $period + ['metric' => 'won']))->assertOk();
    $wonIds = $wonResponse->viewData('leads')->pluck('id')->all();
    expect($wonIds)->toContain($inWon)->not->toContain($inOpen, $outside);

    // The numbers shown on the dashboard come from the same query.
    request()->merge($period);
    $reporting = app(Webkul\Admin\Helpers\Reporting\Lead::class);
    expect($total->viewData('leads')->total())->toBe($reporting->getTotalLeads($reporting->getStartDate(), $reporting->getEndDate()));
    expect($wonResponse->viewData('leads')->total())->toBe($reporting->getWonLeads($reporting->getStartDate(), $reporting->getEndDate()));
});

it('does not open the list without the leads permission', function () {
    $roleId = DB::table('roles')->insertGetId([
        'name' => 'ZZ sem leads '.uniqid(), 'description' => 't', 'permission_type' => 'custom',
        'permissions' => json_encode(['dashboard']), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $userId = DB::table('users')->insertGetId([
        'name' => 'ZZ', 'email' => 'zz-'.uniqid().'@example.test', 'password' => bcrypt('x'), 'status' => 1,
        'role_id' => $roleId, 'view_permission' => 'global', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs(Webkul\User\Models\User::find($userId), 'user')->get(route('admin.dashboard.leads'))->assertStatus(401);
});
