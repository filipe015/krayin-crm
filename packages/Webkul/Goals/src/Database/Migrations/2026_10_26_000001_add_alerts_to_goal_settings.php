<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The rules of the automatic alerts (null = the defaults) and whether they are on.
        Schema::table('goal_settings', function (Blueprint $table) {
            $table->json('alert_rules')->nullable()->after('default_weekdays');
            $table->boolean('alerts_enabled')->default(true)->after('alert_rules');
        });
    }

    public function down(): void
    {
        Schema::table('goal_settings', function (Blueprint $table) {
            $table->dropColumn(['alert_rules', 'alerts_enabled']);
        });
    }
};
