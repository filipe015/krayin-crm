<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Goals\Models\LeadEvent;
use Webkul\Lead\Models\Lead;

uses(DatabaseTransactions::class);

/**
 * lead_events: what happens to a deal, recorded as it happens (and rebuilt for what was already there).
 * Needs a migrated and seeded database.
 */
function eventStages(): array
{
    $pipeline = DB::table('lead_pipelines')->where('is_default', 1)->value('id');

    return [$pipeline, DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $pipeline)->orderBy('sort_order')->pluck('id')->all()];
}

function newLead(?int $stage = null): Lead
{
    [$pipeline, $stages] = eventStages();

    return Lead::query()->create([
        'title'                   => 'ZZ evento '.uniqid(),
        'status'                  => 1,
        'lead_value'              => 0,
        'lead_pipeline_id'        => $pipeline,
        'lead_pipeline_stage_id'  => $stage ?? $stages[0],
    ]);
}

function eventsOf(Lead $lead, string $kind)
{
    return LeadEvent::query()->where('lead_id', $lead->id)->where('kind', $kind)->get();
}

it('records the creation of a deal and the stage it starts in', function () {
    $lead = newLead();

    expect(eventsOf($lead, LeadEvent::CREATED))->toHaveCount(1)
        ->and(eventsOf($lead, LeadEvent::STAGE_ENTERED))->toHaveCount(1)
        ->and(eventsOf($lead, LeadEvent::STAGE_ENTERED)->first()->stage_id)->toBe($lead->lead_pipeline_stage_id);
});

it('records a move to another stage, and nothing for other changes', function () {
    [, $stages] = eventStages();
    $lead = newLead($stages[0]);

    $lead->update(['title' => 'Outro título']);

    expect(eventsOf($lead, LeadEvent::STAGE_ENTERED))->toHaveCount(1);

    $lead->update(['lead_pipeline_stage_id' => $stages[1]]);

    $entered = eventsOf($lead, LeadEvent::STAGE_ENTERED);

    expect($entered)->toHaveCount(2)
        ->and($entered->last()->stage_id)->toBe($stages[1]);
});

it('records a tag put on a deal, once', function () {
    $lead = newLead();
    $tag = DB::table('tags')->insertGetId(['name' => 'ZZ tag '.uniqid(), 'color' => '#FFFFFF', 'user_id' => DB::table('users')->value('id'), 'created_at' => now(), 'updated_at' => now()]);

    $lead->tags()->attach($tag);
    $lead->tags()->syncWithoutDetaching([$tag]);

    $events = eventsOf($lead, LeadEvent::TAG_ADDED);

    expect($events)->toHaveCount(1)
        ->and($events->first()->tag_id)->toBe($tag);
});

it('records a meeting as an appointment, and ignores other activities', function () {
    $lead = newLead();
    $make = fn (string $type) => DB::table('activities')->insertGetId(['title' => 'ZZ', 'type' => $type, 'is_done' => 0, 'schedule_from' => now(), 'schedule_to' => now()->addHour(), 'created_at' => now()->subDay(), 'updated_at' => now()]);

    $meeting = $make('meeting');

    $lead->activities()->attach($meeting);
    $lead->activities()->attach($make('task'));
    $lead->activities()->attach($make('note'));

    $events = eventsOf($lead, LeadEvent::APPOINTMENT_CREATED);

    expect($events)->toHaveCount(1)
        ->and($events->first()->activity_id)->toBe($meeting)
        // The day the appointment was MADE (the activity's creation), not the day it happens.
        ->and($events->first()->occurred_at->isYesterday())->toBeTrue();
});

it('rebuilds events from what the CRM already had, once', function () {
    [$pipeline, $stages] = eventStages();
    $created = now()->subDays(10)->startOfSecond();
    $entered = now()->subDays(3)->startOfSecond();

    $leadId = DB::table('leads')->insertGetId(['title' => 'ZZ antigo', 'status' => 1, 'lead_value' => 0, 'lead_pipeline_id' => $pipeline, 'lead_pipeline_stage_id' => $stages[1], 'created_at' => $created, 'updated_at' => $created, 'stage_entered_at' => $entered]);
    $meeting = DB::table('activities')->insertGetId(['title' => 'ZZ', 'type' => 'meeting', 'is_done' => 0, 'schedule_from' => now(), 'schedule_to' => now()->addHour(), 'created_at' => $created, 'updated_at' => $created]);
    DB::table('lead_activities')->insert(['lead_id' => $leadId, 'activity_id' => $meeting]);

    DB::table('lead_events')->delete();

    $migration = require base_path('packages/Webkul/Goals/src/Database/Migrations/2026_10_23_000002_backfill_lead_events.php');
    $migration->up();
    $migration->up(); // already there: does nothing the second time

    $events = LeadEvent::query()->where('lead_id', $leadId)->get()->keyBy('kind');

    expect($events)->toHaveCount(3)
        ->and($events[LeadEvent::CREATED]->occurred_at->equalTo($created))->toBeTrue()
        ->and($events[LeadEvent::STAGE_ENTERED]->occurred_at->equalTo($entered))->toBeTrue()
        ->and($events[LeadEvent::STAGE_ENTERED]->stage_id)->toBe($stages[1])
        ->and($events[LeadEvent::APPOINTMENT_CREATED]->source)->toBe('backfill');
});
