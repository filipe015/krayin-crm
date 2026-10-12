<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_button_rules', function (Blueprint $table) {
            $table->id();
            $table->string('match_text', 100)->unique();
            $table->unsignedInteger('stage_id')->nullable();
            $table->unsignedInteger('tag_id')->nullable();
            $table->timestamps();

            $table->foreign('stage_id')->references('id')->on('lead_pipeline_stages')->nullOnDelete();
            $table->foreign('tag_id')->references('id')->on('tags')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_button_rules');
    }
};
