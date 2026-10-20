<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What the pipeline is for (commercial = sales, post_sale, other) and whether its deals count in the sales
        // reports (revenue, average ticket, goals, ads). Every existing pipeline keeps counting, as before.
        Schema::table('lead_pipelines', function (Blueprint $table) {
            $table->string('type', 20)->default('commercial')->after('name');
            $table->boolean('counts_in_reports')->default(true)->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('lead_pipelines', function (Blueprint $table) {
            $table->dropColumn(['type', 'counts_in_reports']);
        });
    }
};
