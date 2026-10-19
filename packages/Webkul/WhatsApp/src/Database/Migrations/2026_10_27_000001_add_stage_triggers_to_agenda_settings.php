<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pipeline stages that open the booking card when a deal is moved or created there:
        // [{"pipeline_id":9,"stage_id":70,"kind":"appointment"}]. Null = none.
        Schema::table('whatsapp_agenda_settings', function (Blueprint $table) {
            $table->json('stage_triggers')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_agenda_settings', function (Blueprint $table) {
            $table->dropColumn('stage_triggers');
        });
    }
};
