<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\WhatsApp\Models\AgendaSetting;

uses(DatabaseTransactions::class);

/**
 * The booking card that opens when a deal reaches a stage chosen in Configurações → Agenda.
 */
function promptStages(): array
{
    $stages = DB::table('lead_pipeline_stages')->whereNotIn('code', ['won', 'lost'])->orderBy('id')->limit(2)->get();

    return [$stages[0], $stages[1]];
}

function promptLead(object $stage): int
{
    return DB::table('leads')->insertGetId(['title' => 'ZZ prompt', 'lead_value' => 0, 'status' => 1, 'lead_pipeline_id' => $stage->lead_pipeline_id, 'lead_pipeline_stage_id' => $stage->id, 'created_at' => now(), 'updated_at' => now()]);
}

function chooseTriggers(array $triggers): void
{
    $setting = AgendaSetting::query()->first() ?? new AgendaSetting(AgendaSetting::DEFAULTS);
    $setting->fill(['stage_triggers' => $triggers])->save();
}

it('opens only for the stages the installation chose', function () {
    [$chosen, $other] = promptStages();
    chooseTriggers([['pipeline_id' => $chosen->lead_pipeline_id, 'stage_id' => $chosen->id, 'kind' => 'appointment']]);

    $admin = getDefaultAdmin();
    $url = route('admin.agenda.stage-prompt');

    $open = test()->actingAs($admin)->postJson($url, ['lead_id' => promptLead($chosen)])->assertOk();

    expect($open->json('open'))->toBeTrue()->and($open->json('kind'))->toBe('appointment')->and($open->json('lead.stage'))->toBe($chosen->name);

    test()->actingAs($admin)->postJson($url, ['lead_id' => promptLead($other)])->assertOk()->assertJson(['open' => false]);
});

it('does not ask again when the deal already has an appointment waiting', function () {
    [$chosen] = promptStages();
    chooseTriggers([['pipeline_id' => $chosen->lead_pipeline_id, 'stage_id' => $chosen->id, 'kind' => 'appointment']]);

    $admin = getDefaultAdmin();
    $leadId = promptLead($chosen);

    test()->actingAs($admin)->postJson(route('admin.agenda.store'), ['lead_id' => $leadId, 'user_id' => $admin->id, 'start' => now()->addDays(2)->format('Y-m-d\TH:i'), 'duration' => 30])->assertStatus(201);

    test()->actingAs($admin)->postJson(route('admin.agenda.stage-prompt'), ['lead_id' => $leadId])->assertOk()->assertJson(['open' => false]);
});

it('opens the follow-up card for a stage set to follow-up, and a follow-up already waiting stops it', function () {
    [$chosen] = promptStages();
    chooseTriggers([['pipeline_id' => $chosen->lead_pipeline_id, 'stage_id' => $chosen->id, 'kind' => 'followup']]);

    $admin = getDefaultAdmin();
    $leadId = promptLead($chosen);
    $url = route('admin.agenda.stage-prompt');

    test()->actingAs($admin)->postJson($url, ['lead_id' => $leadId])->assertOk()->assertJson(['open' => true, 'kind' => 'followup']);

    test()->actingAs($admin)->postJson(route('admin.agenda.store'), ['kind' => 'followup', 'lead_id' => $leadId, 'user_id' => $admin->id, 'start' => now()->addDay()->format('Y-m-d\TH:i'), 'duration' => 15])->assertStatus(201);

    test()->actingAs($admin)->postJson($url, ['lead_id' => $leadId])->assertOk()->assertJson(['open' => false]);
});

it('stays closed while no stage was chosen', function () {
    [$stage] = promptStages();
    chooseTriggers([]);

    test()->actingAs(getDefaultAdmin())->postJson(route('admin.agenda.stage-prompt'), ['lead_id' => promptLead($stage)])->assertOk()->assertJson(['open' => false]);
});

it('saves the chosen stages and refuses a stage from another pipeline', function () {
    [$stage] = promptStages();
    $admin = getDefaultAdmin();

    $payload = fn (array $triggers) => AgendaSetting::DEFAULTS + ['stage_triggers' => $triggers];

    test()->actingAs($admin)->putJson(route('admin.agenda.settings.update'), $payload([['pipeline_id' => $stage->lead_pipeline_id, 'stage_id' => $stage->id, 'kind' => 'followup']]))->assertOk();

    expect(AgendaSetting::stageTriggers())->toBe([$stage->id => 'followup']);

    test()->actingAs($admin)->putJson(route('admin.agenda.settings.update'), $payload([['pipeline_id' => 999999, 'stage_id' => $stage->id, 'kind' => 'appointment']]))->assertStatus(422);
});
