<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "When a deal is won in this pipeline, open a card in that one."
        Schema::create('funnel_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('source_pipeline_id');
            $table->unsignedInteger('target_pipeline_id');
            $table->unsignedInteger('target_stage_id');
            $table->string('assignment_mode', 20)->default('owner');
            $table->unsignedInteger('assigned_user_id')->nullable();
            $table->json('pool_user_ids')->nullable();
            $table->unsignedInteger('last_assigned_user_id')->nullable();
            $table->boolean('copy_value')->default(false);
            $table->boolean('move_conversation')->default(true);
            $table->timestamps();

            $table->foreign('source_pipeline_id')->references('id')->on('lead_pipelines')->onDelete('cascade');
            $table->foreign('target_pipeline_id')->references('id')->on('lead_pipelines')->onDelete('cascade');
            $table->foreign('target_stage_id')->references('id')->on('lead_pipeline_stages')->onDelete('cascade');
        });

        // One card per rule and won deal, even if the deal is reopened and won again.
        Schema::create('funnel_rule_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('funnel_rule_id');
            $table->unsignedInteger('source_lead_id');
            $table->unsignedInteger('target_lead_id')->nullable();
            $table->timestamps();

            $table->unique(['funnel_rule_id', 'source_lead_id']);
            $table->foreign('funnel_rule_id')->references('id')->on('funnel_rules')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('funnel_rule_runs');
        Schema::dropIfExists('funnel_rules');
    }
};
