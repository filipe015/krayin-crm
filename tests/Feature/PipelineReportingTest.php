<?php

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Admin\Helpers\Reporting\Lead as LeadReporting;
use Webkul\Goals\Support\GoalActuals;
use Webkul\Lead\Models\Pipeline;

uses(DatabaseTransactions::class);

/**
 * A pipeline marked "Não conta nos relatórios de vendas" (post-sale, for instance) stays out of revenue,
 * average ticket, goals, ads and the reports of the API; the others count as before.
 */
function postSalePipeline(bool $counts = false): array
{
    $pipelineId = DB::table('lead_pipelines')->insertGetId(['name' => 'ZZ Pós-venda '.uniqid(), 'type' => 'post_sale', 'counts_in_reports' => $counts, 'rotten_days' => 30, 'is_default' => 0, 'created_at' => now(), 'updated_at' => now()]);

    $stage = fn (string $name, string $code, int $order) => DB::table('lead_pipeline_stages')->insertGetId(['name' => $name, 'code' => $code, 'probability' => 100, 'sort_order' => $order, 'lead_pipeline_id' => $pipelineId]);

    Pipeline::forgetCounted();

    return ['pipeline' => $pipelineId, 'open' => $stage('Onboarding', 'onboarding', 1), 'won' => $stage('Concluído', 'won', 98), 'lost' => $stage('Cancelado', 'lost', 99)];
}

function wonDeal(int $pipelineId, int $stageId, float $value, string $closedAt): int
{
    return DB::table('leads')->insertGetId(['title' => 'ZZ deal', 'lead_value' => $value, 'status' => 1, 'lead_pipeline_id' => $pipelineId, 'lead_pipeline_stage_id' => $stageId, 'closed_at' => $closedAt, 'created_at' => $closedAt, 'updated_at' => $closedAt]);
}

function commercialWon(): array
{
    $stage = DB::table('lead_pipeline_stages')->where('code', 'won')->whereIn('lead_pipeline_id', Pipeline::countedIds())->first();

    return [(int) $stage->lead_pipeline_id, (int) $stage->id];
}

it('leaves a pipeline that does not count out of the dashboard numbers', function () {
    [$commercial, $won] = commercialWon();
    $post = postSalePipeline(false);

    wonDeal($commercial, $won, 1000, '2032-03-10 10:00:00');
    wonDeal($post['pipeline'], $post['won'], 0, '2032-03-12 10:00:00');
    wonDeal($post['pipeline'], $post['won'], 500, '2032-03-15 10:00:00');

    $report = app(LeadReporting::class);
    $from = Carbon::parse('2032-03-01')->startOfDay();
    $to = Carbon::parse('2032-03-31')->endOfDay();

    expect($report->getWonLeads($from, $to))->toBe(1)
        ->and($report->getTotalWonLeadValue($from, $to))->toEqual(1000)
        ->and($report->getAverageLeadValue($from, $to))->toBe(1000.0)
        ->and($report->getTotalLeads($from, $to))->toBe(1);
});

it('counts the pipeline again when it is switched on', function () {
    $post = postSalePipeline(true);

    wonDeal($post['pipeline'], $post['won'], 500, '2032-04-15 10:00:00');

    $report = app(LeadReporting::class);

    expect($report->getWonLeads(Carbon::parse('2032-04-01'), Carbon::parse('2032-04-30')->endOfDay()))->toBe(1);
});

it('leaves it out of the goals as well', function () {
    [$commercial, $won] = commercialWon();
    $post = postSalePipeline(false);

    $a = wonDeal($commercial, $won, 1000, '2032-05-10 10:00:00');
    $b = wonDeal($post['pipeline'], $post['won'], 700, '2032-05-11 10:00:00');

    foreach ([$a, $b] as $lead) {
        DB::table('lead_events')->insert(['lead_id' => $lead, 'kind' => 'created', 'occurred_at' => '2032-05-10 10:00:00', 'source' => 'live', 'created_at' => now()]);
    }

    $actual = GoalActuals::between(CarbonImmutable::parse('2032-05-01'), CarbonImmutable::parse('2032-05-31'));

    expect($actual['leads'])->toBe(1)->and($actual['sales'])->toBe(1)->and($actual['revenue'])->toBe(1000);
});

it('saves the type and the reporting choice on the pipeline form', function () {
    $admin = getDefaultAdmin();

    $stages = [
        'stage_1' => ['name' => 'Novo', 'code' => 'novo', 'probability' => 100, 'sort_order' => 1],
        'stage_2' => ['name' => 'Ganho', 'code' => 'won', 'probability' => 100, 'sort_order' => 98],
        'stage_3' => ['name' => 'Perdido', 'code' => 'lost', 'probability' => 0, 'sort_order' => 99],
    ];

    test()->actingAs($admin, 'user')->post(route('admin.settings.pipelines.store'), ['name' => 'ZZ funil '.uniqid(), 'rotten_days' => 30, 'type' => 'post_sale', 'counts_in_reports' => 0, 'stages' => $stages])->assertRedirect();

    $pipeline = Pipeline::query()->where('name', 'like', 'ZZ funil%')->latest('id')->first();

    expect($pipeline->type)->toBe('post_sale')->and($pipeline->counts_in_reports)->toBeFalse()
        ->and(Pipeline::countedIds())->not->toContain($pipeline->id);

    // an unknown type is refused; leaving it out gives a commercial pipeline that counts
    $other = ['name' => 'ZZ funil '.uniqid(), 'rotten_days' => 30, 'stages' => [
        'stage_1' => ['name' => 'Novo B', 'code' => 'novo-b', 'probability' => 100, 'sort_order' => 1],
        'stage_2' => ['name' => 'Ganho B', 'code' => 'won', 'probability' => 100, 'sort_order' => 98],
        'stage_3' => ['name' => 'Perdido B', 'code' => 'lost', 'probability' => 0, 'sort_order' => 99],
    ]];

    test()->actingAs($admin, 'user')->post(route('admin.settings.pipelines.store'), $other + ['type' => 'qualquer'])->assertSessionHasErrors('type');

    test()->actingAs($admin, 'user')->post(route('admin.settings.pipelines.store'), $other)->assertRedirect();

    $second = Pipeline::query()->where('name', $other['name'])->first();

    expect($second->type)->toBe('commercial')->and($second->counts_in_reports)->toBeTrue();
});

it('shows the type and the reporting choice when editing a pipeline, and saves a change', function () {
    $admin = getDefaultAdmin();
    $post = postSalePipeline(false);

    $html = test()->actingAs($admin, 'user')->get(route('admin.settings.pipelines.edit', ['id' => $post['pipeline']]))->assertOk()->getContent();

    expect($html)->toContain('name="type"')->toContain('name="counts_in_reports"')
        ->and(preg_match('/<option value="post_sale"\s+selected/', $html))->toBe(1)
        ->and(preg_match('/<option value="0"\s+selected/', $html))->toBe(1);

    $stages = [];

    foreach (DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $post['pipeline'])->get() as $stage) {
        $stages[$stage->id] = ['name' => $stage->name, 'code' => $stage->code, 'probability' => 100, 'sort_order' => $stage->sort_order];
    }

    test()->actingAs($admin, 'user')->post(route('admin.settings.pipelines.update', ['id' => $post['pipeline']]), ['name' => 'ZZ Pós-venda editado '.uniqid(), 'rotten_days' => 30, 'type' => 'post_sale', 'counts_in_reports' => 1, 'stages' => $stages])->assertRedirect();

    expect(Pipeline::query()->find($post['pipeline'])->counts_in_reports)->toBeTrue();
});
