<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\WhatsApp\Models\AgendaSetting;

uses(DatabaseTransactions::class);

/**
 * The color of the Agenda cards, chosen per pipeline stage in Configurações → Agenda.
 */
function colorPayload(array $colors): array
{
    return AgendaSetting::DEFAULTS + ['stage_colors' => $colors];
}

it('saves the color of a stage and reads it back in lower case', function () {
    $stage = DB::table('lead_pipeline_stages')->orderBy('id')->first();

    test()->actingAs(getDefaultAdmin())->putJson(route('admin.agenda.settings.update'), colorPayload([(string) $stage->id => '#16A34A']))->assertOk();

    expect(AgendaSetting::stageColors())->toBe([(int) $stage->id => '#16a34a']);
});

it('drops a color when it is reset and ignores stages that do not exist', function () {
    $stage = DB::table('lead_pipeline_stages')->orderBy('id')->first();
    $admin = getDefaultAdmin();

    test()->actingAs($admin)->putJson(route('admin.agenda.settings.update'), colorPayload([(string) $stage->id => '#16a34a', '99999999' => '#000000']))->assertOk();

    expect(AgendaSetting::stageColors())->toBe([(int) $stage->id => '#16a34a']);

    test()->actingAs($admin)->putJson(route('admin.agenda.settings.update'), colorPayload([]))->assertOk();

    expect(AgendaSetting::stageColors())->toBe([]);
});

it('refuses a color that is not #rrggbb', function () {
    $stage = DB::table('lead_pipeline_stages')->orderBy('id')->first();

    test()->actingAs(getDefaultAdmin())->putJson(route('admin.agenda.settings.update'), colorPayload([(string) $stage->id => 'red']))->assertStatus(422);
});

it('gives the Agenda page the chosen colors', function () {
    $stage = DB::table('lead_pipeline_stages')->orderBy('id')->first();
    $admin = getDefaultAdmin();

    test()->actingAs($admin)->putJson(route('admin.agenda.settings.update'), colorPayload([(string) $stage->id => '#7c3aed']))->assertOk();

    test()->actingAs($admin)->get(route('admin.agenda.index'))->assertOk()->assertSee('#7c3aed', false);
});
