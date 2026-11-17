<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // History of the automations: one row per rule x lead x event.
        //   status: running (while it runs) | ok | skipped | failed | dry_run
        //   idempotency_key: unique, so the same event never runs twice (and "once per lead" rules
        //   hold a "once:<rule>:<lead>" key). Null for simulations.
        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('rule_id');
            $table->unsignedInteger('lead_id')->nullable();
            $table->string('trigger', 30);
            $table->string('status', 10);
            $table->json('steps')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->unsignedTinyInteger('depth')->default(0);
            $table->string('idempotency_key', 80)->nullable()->unique();
            $table->timestamp('created_at')->nullable();

            $table->foreign('rule_id')->references('id')->on('automation_rules')->onDelete('cascade');
            $table->foreign('lead_id')->references('id')->on('leads')->onDelete('cascade');
            $table->index(['rule_id', 'created_at']);
            $table->index(['lead_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_runs');
    }
};
