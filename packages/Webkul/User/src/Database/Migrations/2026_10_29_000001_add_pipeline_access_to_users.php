<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Access by pipeline: a restricted user sees only the pipelines listed in user_pipelines (everywhere: board,
        // lists, deals, dashboard, agenda, WhatsApp). Everybody starts unrestricted, as before.
        // inbox_queue: whether a restricted user also sees the WhatsApp conversations that still have no deal.
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('pipelines_restricted')->default(false);
            $table->boolean('inbox_queue')->default(true);
        });

        Schema::create('user_pipelines', function (Blueprint $table) {
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('lead_pipeline_id');

            $table->primary(['user_id', 'lead_pipeline_id']);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('lead_pipeline_id')->references('id')->on('lead_pipelines')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_pipelines');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['pipelines_restricted', 'inbox_queue']);
        });
    }
};
