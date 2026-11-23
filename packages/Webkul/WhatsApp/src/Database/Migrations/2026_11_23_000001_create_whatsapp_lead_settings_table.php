<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How conversations become leads (Configurações → Criação de leads pelo WhatsApp). A
        // single row per installation; while it does not exist the WHATSAPP_AUTO_LEAD* env
        // values are used, so nothing changes until the screen is saved once.
        Schema::create('whatsapp_lead_settings', function (Blueprint $table) {
            $table->id();
            $table->string('mode', 10)->default('manual');
            $table->unsignedInteger('pipeline_id')->nullable();
            $table->unsignedInteger('stage_id')->nullable();
            $table->string('source', 100)->nullable();
            $table->string('tags', 500)->nullable();
            $table->unsignedInteger('user_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_lead_settings');
    }
};
