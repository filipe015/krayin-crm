<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Color of the Agenda cards by the deal's pipeline stage: {"70":"#16a34a"}. Null = the built-in palette.
        Schema::table('whatsapp_agenda_settings', function (Blueprint $table) {
            $table->json('stage_colors')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_agenda_settings', function (Blueprint $table) {
            $table->dropColumn('stage_colors');
        });
    }
};
