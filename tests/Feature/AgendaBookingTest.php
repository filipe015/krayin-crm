<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

uses(DatabaseTransactions::class);

/**
 * Booking from the Agenda screen / the lead's "Agendar" button.
 */
function bookableLead(): int
{
    $stage = DB::table('lead_pipeline_stages')->orderBy('id')->first();

    return DB::table('leads')->insertGetId(['title' => 'ZZ agenda', 'lead_value' => 0, 'status' => 1, 'lead_pipeline_id' => $stage->lead_pipeline_id, 'lead_pipeline_stage_id' => $stage->id, 'created_at' => now(), 'updated_at' => now()]);
}

function bookingPayload(int $leadId, int $userId, string $start): array
{
    return ['lead_id' => $leadId, 'user_id' => $userId, 'start' => $start, 'duration' => 30, 'title' => 'Consulta'];
}

it('books an appointment and answers with its id', function () {
    $admin = getDefaultAdmin();
    $leadId = bookableLead();

    $response = test()->actingAs($admin)->postJson(route('admin.agenda.store'), bookingPayload($leadId, $admin->id, now()->addDays(3)->format('Y-m-d\TH:i')));

    $response->assertStatus(201)->assertJsonPath('data.user_id', $admin->id);

    expect(DB::table('lead_activities')->where('lead_id', $leadId)->count())->toBe(1)
        ->and(DB::table('activities')->where('id', $response->json('data.id'))->value('type'))->toBe('meeting');
});

it('warns about a conflict instead of booking twice', function () {
    $admin = getDefaultAdmin();
    $leadId = bookableLead();
    $start = now()->addDays(4)->format('Y-m-d\TH:i');

    test()->actingAs($admin)->postJson(route('admin.agenda.store'), bookingPayload($leadId, $admin->id, $start))->assertStatus(201);
    test()->actingAs($admin)->postJson(route('admin.agenda.store'), bookingPayload($leadId, $admin->id, $start))->assertStatus(409);

    expect(DB::table('lead_activities')->where('lead_id', $leadId)->count())->toBe(1);
});

it('refuses a person who does not exist', function () {
    $leadId = bookableLead();

    test()->actingAs(getDefaultAdmin())->postJson(route('admin.agenda.store'), bookingPayload($leadId, 999999, now()->addDays(5)->format('Y-m-d\TH:i')))->assertStatus(422);
});
