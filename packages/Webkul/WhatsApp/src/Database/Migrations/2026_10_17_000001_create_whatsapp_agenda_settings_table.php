<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_agenda_settings', function (Blueprint $table) {
            $table->id();
            $table->string('singular', 40)->default('Agendamento');
            $table->string('plural', 40)->default('Agendamentos');
            $table->string('gender', 1)->default('m');
            $table->string('responsible', 40)->default('Responsável');
            $table->string('responsible_plural', 40)->default('Responsáveis');
            $table->unsignedSmallInteger('default_duration')->default(30);
            $table->unsignedTinyInteger('day_start')->default(7);
            $table->unsignedTinyInteger('day_end')->default(21);
            $table->string('distribution_mode', 20)->default('manual');
            $table->json('distribution_user_ids')->nullable();
            $table->unsignedInteger('last_assigned_user_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_agenda_settings');
    }
};
