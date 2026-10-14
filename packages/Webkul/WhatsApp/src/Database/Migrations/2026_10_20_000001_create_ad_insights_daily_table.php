<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Meta Ads spend and results, one row per ad per day. Filled by an automation (n8n) through
        // POST /api/v1/ads/insights; read by the Anúncios screen and GET /api/v1/ads/performance.
        Schema::create('ad_insights_daily', function (Blueprint $table) {
            $table->id();
            $table->string('account_id', 40);
            $table->date('date');
            $table->string('campaign_id', 40)->nullable();
            $table->string('campaign_name')->nullable();
            $table->string('adset_id', 40)->nullable();
            $table->string('adset_name')->nullable();
            $table->string('ad_id', 40);
            $table->string('ad_name')->nullable();
            $table->decimal('spend', 12, 2)->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            // Conversions the platform reported, by label: {"lead": 12, "Lead - Online": 9, ...}
            $table->json('results')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'date', 'ad_id'], 'ad_insights_unique');
            $table->index('date');
            $table->index('campaign_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_insights_daily');
    }
};
