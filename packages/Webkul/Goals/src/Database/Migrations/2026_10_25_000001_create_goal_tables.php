<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The month's own settings. working_days null = the default weekdays of the settings.
        Schema::create('goal_months', function (Blueprint $table) {
            $table->id();
            $table->char('year_month', 7)->unique();
            $table->json('working_days')->nullable();
            $table->timestamps();
        });

        // One plan per month and funnel. funnel '' is the general plan (not null: NULLs are never "equal"
        // for a unique key, and there must be only one general plan per month).
        // Everything is a whole number: money in whole reais, rates in whole percents.
        Schema::create('goal_plans', function (Blueprint $table) {
            $table->id();
            $table->char('year_month', 7);
            $table->string('funnel', 120)->default('');

            // What the person sets...
            $table->unsignedBigInteger('revenue_target')->nullable();
            $table->unsignedInteger('ticket')->nullable();
            $table->unsignedTinyInteger('qualification_rate')->nullable(); // lead -> qualified
            $table->unsignedTinyInteger('scheduling_rate')->nullable();    // qualified -> appointment
            $table->unsignedTinyInteger('attendance_rate')->nullable();    // appointment -> attended
            $table->unsignedTinyInteger('closing_rate')->nullable();       // attended -> sale
            $table->unsignedInteger('cpl_target')->nullable();
            $table->unsignedBigInteger('budget')->nullable();

            // ...and what the funnel then needs (worked out, or fixed by hand: see "overrides").
            $table->unsignedInteger('leads_target')->nullable();
            $table->unsignedInteger('qualified_target')->nullable();
            $table->unsignedInteger('scheduled_target')->nullable();
            $table->unsignedInteger('attended_target')->nullable();
            $table->unsignedInteger('sales_target')->nullable();
            $table->json('overrides')->nullable();

            $table->unsignedInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['year_month', 'funnel']);
        });

        // How the funnel steps are recognised, and the default working weekdays (a single row).
        Schema::create('goal_settings', function (Blueprint $table) {
            $table->id();
            $table->json('step_rules');
            $table->json('default_weekdays');
            $table->unsignedInteger('updated_by')->nullable();
            $table->timestamps();
        });

        // Who changed what, and when: one row per field that changed.
        Schema::create('goal_changes', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->nullable();
            $table->string('subject', 20);              // plan | month | settings
            $table->char('year_month', 7)->nullable();
            $table->string('funnel', 120)->default('');
            $table->string('field', 60);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->dateTime('changed_at');

            $table->index('changed_at');
            $table->index(['year_month', 'funnel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goal_changes');
        Schema::dropIfExists('goal_settings');
        Schema::dropIfExists('goal_plans');
        Schema::dropIfExists('goal_months');
    }
};
