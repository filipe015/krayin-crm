<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "Quando… Se… Então…" rules (Configurações → Automação → Automações).
        //   status: on | off | test (test = evaluates and records what it would do, changes nothing)
        //   trigger_type: stage_entered | lead_created | tag_added | activity_created
        Schema::create('automation_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('status', 10)->default('off');
            $table->string('trigger_type', 30);
            $table->json('trigger_config')->nullable();
            $table->json('conditions')->nullable();
            $table->json('actions');
            $table->boolean('run_once_per_lead')->default(true);
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['trigger_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_rules');
    }
};
