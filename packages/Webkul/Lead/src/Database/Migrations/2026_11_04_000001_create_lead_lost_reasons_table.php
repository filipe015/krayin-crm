<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Standard reasons for losing a deal, one list per pipeline.
        Schema::create('lead_lost_reasons', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('lead_pipeline_id');
            $table->string('name', 120);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('lead_pipeline_id')->references('id')->on('lead_pipelines')->onDelete('cascade');
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->unsignedBigInteger('lost_reason_id')->nullable()->after('lost_reason');
            $table->index('lost_reason_id');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['lost_reason_id']);
            $table->dropColumn('lost_reason_id');
        });

        Schema::dropIfExists('lead_lost_reasons');
    }
};
