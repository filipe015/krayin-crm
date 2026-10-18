<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Rebuilds what can be known from the CRM as it is today, so the numbers do not start from zero:
     * every deal's creation, the stage it is in now (from the day it entered it), and its meetings.
     * Moves between stages and tags from before this day were never recorded and cannot be recovered.
     */
    public function up(): void
    {
        if (DB::table('lead_events')->exists()) {
            return;
        }

        $now = now()->toDateTimeString();

        DB::statement("
            insert into lead_events (lead_id, kind, pipeline_id, stage_id, occurred_at, source, created_at)
            select id, 'created', lead_pipeline_id, lead_pipeline_stage_id, created_at, 'backfill', '{$now}'
            from leads
        ");

        DB::statement("
            insert into lead_events (lead_id, kind, pipeline_id, stage_id, occurred_at, source, created_at)
            select id, 'stage_entered', lead_pipeline_id, lead_pipeline_stage_id, coalesce(stage_entered_at, created_at), 'backfill', '{$now}'
            from leads
        ");

        DB::statement("
            insert into lead_events (lead_id, kind, activity_id, occurred_at, source, created_at)
            select la.lead_id, 'appointment_created', a.id, a.created_at, 'backfill', '{$now}'
            from lead_activities la
            join activities a on a.id = la.activity_id
            where a.type = 'meeting'
        ");
    }

    public function down(): void
    {
        DB::table('lead_events')->where('source', 'backfill')->delete();
    }
};
