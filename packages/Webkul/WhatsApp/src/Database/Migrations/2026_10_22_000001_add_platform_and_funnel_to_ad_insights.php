<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per ad per day per platform (facebook, instagram...). Rows that arrived before the
        // breakdown existed keep platform = '' and are replaced as soon as the broken-down rows come.
        Schema::table('ad_insights_daily', function (Blueprint $table) {
            $table->string('platform', 30)->default('')->after('ad_id');
            // The funnel the ad set optimises for: the custom conversion named in its objective.
            $table->string('funnel', 120)->nullable()->after('platform');
        });

        Schema::table('ad_insights_daily', function (Blueprint $table) {
            $table->dropUnique('ad_insights_unique');
            $table->unique(['account_id', 'date', 'ad_id', 'platform'], 'ad_insights_unique');
        });

        // Settings of the Anúncios screen (a single row).
        Schema::create('ad_settings', function (Blueprint $table) {
            $table->id();
            $table->decimal('tax_percent', 5, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_settings');

        Schema::table('ad_insights_daily', function (Blueprint $table) {
            $table->dropUnique('ad_insights_unique');
        });

        Schema::table('ad_insights_daily', function (Blueprint $table) {
            $table->dropColumn(['platform', 'funnel']);
            $table->unique(['account_id', 'date', 'ad_id'], 'ad_insights_unique');
        });
    }
};
