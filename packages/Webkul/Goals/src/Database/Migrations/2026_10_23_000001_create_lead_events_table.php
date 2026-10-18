<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What happened to each deal and when. The CRM only keeps where a deal is NOW; the goals count
        // things by the day they happened (entered a stage, got a tag, was scheduled), so it is recorded here.
        //   kind: created | stage_entered | tag_added | appointment_created
        //   source: live (recorded as it happened) | backfill (rebuilt from what the CRM already had)
        Schema::create('lead_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('lead_id');
            $table->string('kind', 30);
            $table->unsignedInteger('pipeline_id')->nullable();
            $table->unsignedInteger('stage_id')->nullable();
            $table->unsignedInteger('tag_id')->nullable();
            $table->unsignedInteger('activity_id')->nullable();
            $table->unsignedInteger('user_id')->nullable();
            $table->dateTime('occurred_at');
            $table->string('source', 10)->default('live');
            $table->timestamp('created_at')->nullable();

            $table->foreign('lead_id')->references('id')->on('leads')->onDelete('cascade');
            $table->index(['kind', 'occurred_at']);
            $table->index(['stage_id', 'occurred_at']);
            $table->index(['tag_id', 'occurred_at']);
            $table->index(['lead_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_events');
    }
};
