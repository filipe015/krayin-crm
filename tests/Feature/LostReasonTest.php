<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

uses(DatabaseTransactions::class);

function lostFunnel(): array
{
    $pipeline = DB::table('lead_pipelines')->insertGetId(['name' => 'ZZ perda '.uniqid(), 'type' => 'sales', 'counts_in_reports' => 1, 'rotten_days' => 30, 'is_default' => 0, 'created_at' => now(), 'updated_at' => now()]);
    $open = DB::table('lead_pipeline_stages')->insertGetId(['name' => 'Follow', 'code' => 'follow', 'probability' => 10, 'sort_order' => 1, 'lead_pipeline_id' => $pipeline]);
    $lost = DB::table('lead_pipeline_stages')->insertGetId(['name' => 'Perdido', 'code' => 'lost', 'probability' => 0, 'sort_order' => 9, 'lead_pipeline_id' => $pipeline]);
    $lead = DB::table('leads')->insertGetId(['title' => 'ZZ', 'lead_value' => 1, 'status' => 1, 'lead_pipeline_id' => $pipeline, 'lead_pipeline_stage_id' => $open, 'created_at' => now(), 'updated_at' => now()]);

    return compact('pipeline', 'open', 'lost', 'lead');
}

it('manages the loss reasons of a pipeline on the settings screen', function () {
    $f = lostFunnel();
    $admin = getDefaultAdmin();

    test()->actingAs($admin, 'user')->get(route('admin.lost-reasons.index'))->assertOk();

    $id = test()->postJson(route('admin.lost-reasons.store'), ['lead_pipeline_id' => $f['pipeline'], 'name' => ' Sem orçamento ', 'is_active' => true])
        ->assertOk()->json('data.id');

    test()->putJson(route('admin.lost-reasons.update', $id), ['name' => 'Sem verba', 'is_active' => false])->assertOk();
    expect(DB::table('lead_lost_reasons')->where('id', $id)->first())->name->toBe('Sem verba')->is_active->toBe(0);

    test()->postJson(route('admin.lost-reasons.store'), ['lead_pipeline_id' => 999999, 'name' => 'x', 'is_active' => true])->assertUnprocessable();

    test()->deleteJson(route('admin.lost-reasons.delete', $id))->assertOk();
    expect(DB::table('lead_lost_reasons')->where('id', $id)->exists())->toBeFalse();
});

it('requires a standard reason when the pipeline has some, and never asks for the loss date', function () {
    $f = lostFunnel();
    $reason = DB::table('lead_lost_reasons')->insertGetId(['lead_pipeline_id' => $f['pipeline'], 'name' => 'Escolheu concorrente', 'sort_order' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $other = DB::table('lead_lost_reasons')->insertGetId(['lead_pipeline_id' => lostFunnel()['pipeline'], 'name' => 'De outro funil', 'sort_order' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);

    test()->actingAs(getDefaultAdmin(), 'user');

    $url = route('admin.leads.stage.update', $f['lead']);

    // No reason, free text and a reason of another pipeline are all refused: the lead stays where it was.
    test()->putJson($url, ['lead_pipeline_stage_id' => $f['lost']])->assertUnprocessable();
    test()->putJson($url, ['lead_pipeline_stage_id' => $f['lost'], 'lost_reason' => 'texto livre'])->assertUnprocessable();
    test()->putJson($url, ['lead_pipeline_stage_id' => $f['lost'], 'lost_reason_id' => $other])->assertUnprocessable();
    expect(DB::table('leads')->where('id', $f['lead'])->value('lead_pipeline_stage_id'))->toBe($f['open']);

    test()->putJson($url, ['lead_pipeline_stage_id' => $f['lost'], 'lost_reason_id' => $reason, 'closed_at' => '2001-01-01 00:00:00'])->assertOk();

    $lead = DB::table('leads')->where('id', $f['lead'])->first();

    expect($lead->lead_pipeline_stage_id)->toBe($f['lost'])
        ->and($lead->lost_reason)->toBe('Escolheu concorrente')
        ->and($lead->lost_reason_id)->toBe($reason)
        ->and($lead->closed_at)->not->toStartWith('2001');
});

it('keeps the free text for a pipeline that has no reasons yet', function () {
    $f = lostFunnel();

    test()->actingAs(getDefaultAdmin(), 'user')
        ->putJson(route('admin.leads.stage.update', $f['lead']), ['lead_pipeline_stage_id' => $f['lost'], 'lost_reason' => 'texto livre'])
        ->assertOk();

    expect(DB::table('leads')->where('id', $f['lead'])->value('lost_reason'))->toBe('texto livre');
});

it('renders the board and the deal page with the reasons list', function () {
    $f = lostFunnel();
    DB::table('lead_lost_reasons')->insert(['lead_pipeline_id' => $f['pipeline'], 'name' => 'Sem resposta', 'sort_order' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);

    test()->actingAs(getDefaultAdmin(), 'user');

    test()->get(route('admin.leads.index', ['pipeline_id' => $f['pipeline']]))->assertOk()->assertSee('Sem resposta');
    test()->get(route('admin.leads.view', $f['lead']))->assertOk()->assertSee('Sem resposta');
});

it('moves several deals at once from the list, with one standard reason for all the lost ones', function () {
    $f = lostFunnel();
    $other = DB::table('lead_pipeline_stages')->insertGetId(['name' => 'Depois', 'code' => 'depois', 'probability' => 20, 'sort_order' => 2, 'lead_pipeline_id' => $f['pipeline']]);
    $second = DB::table('leads')->insertGetId(['title' => 'ZZ 2', 'lead_value' => 1, 'status' => 1, 'lead_pipeline_id' => $f['pipeline'], 'lead_pipeline_stage_id' => $f['open'], 'created_at' => now(), 'updated_at' => now()]);
    $foreign = lostFunnel();

    test()->actingAs(getDefaultAdmin(), 'user');

    $stageOf = fn (int $lead) => DB::table('leads')->where('id', $lead)->value('lead_pipeline_stage_id');
    $mass = fn (array $ids, $value) => test()->postJson(route('admin.leads.mass_update'), ['indices' => $ids, 'value' => $value]);

    // A plain stage move (it used to fail with "Leads can not be deleted.").
    $mass([$f['lead'], $second], $other)->assertOk();
    expect($stageOf($f['lead']))->toBe($other)->and($stageOf($second))->toBe($other);

    // A stage of another pipeline is ignored.
    $mass([$f['lead']], $foreign['open'])->assertStatus(400);
    expect($stageOf($f['lead']))->toBe($other);

    // Lost without a reason is refused once the pipeline has reasons; "lost:<id>" carries the reason.
    $reason = DB::table('lead_lost_reasons')->insertGetId(['lead_pipeline_id' => $f['pipeline'], 'name' => 'Sem resposta', 'sort_order' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);

    $mass([$f['lead'], $second], $f['lost'])->assertUnprocessable();
    expect($stageOf($f['lead']))->toBe($other);

    $mass([$f['lead'], $second], 'lost:'.$reason)->assertOk();

    foreach ([$f['lead'], $second] as $id) {
        $lead = DB::table('leads')->where('id', $id)->first();

        expect($lead->lead_pipeline_stage_id)->toBe($f['lost'])->and($lead->lost_reason)->toBe('Sem resposta')->and($lead->lost_reason_id)->toBe($reason)->and($lead->closed_at)->not->toBeNull();
    }
});
