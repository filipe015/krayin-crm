<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_reminder_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('stage_id')->nullable();
            $table->json('keep_stage_ids')->nullable();
            $table->string('activity_type', 30)->default('meeting');
            $table->string('template_name', 512)->nullable();
            $table->string('template_language', 20)->nullable();
            $table->json('variables')->nullable();
            $table->json('offsets')->nullable();
            $table->unsignedBigInteger('session_id')->nullable();
            $table->timestamps();
        });

        // One row per (appointment, how long before). Its existence is what keeps a reminder
        // from being created twice, or again after an operator cancelled it.
        Schema::create('whatsapp_reminders', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('activity_id');
            $table->unsignedInteger('lead_id');
            $table->unsignedBigInteger('conversation_id')->nullable();
            $table->unsignedInteger('offset_minutes');
            $table->unsignedBigInteger('message_id')->nullable();
            $table->dateTime('appointment_at');
            $table->timestamps();

            $table->unique(['activity_id', 'offset_minutes']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_reminders');
        Schema::dropIfExists('whatsapp_reminder_settings');
    }
};
