<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fase 2 do construtor (tempo):
     *
     *  - automation_waits: fluxos pausados por um bloco "Esperar". O agendador (automations:tick, a cada
     *    minuto) pega os vencidos, reserva cada um de forma atômica (status + claim_token) e despacha a
     *    retomada na fila.
     *      status: pending (aguardando) | queued (reservada e na fila) | running (retomando) |
     *              resumed (retomada) | cancelled (cancelada, motivo em reason) | expired (desistiu)
     *  - automation_time_marks: memória dos gatilhos de tempo ("lead parado na etapa", "sem atividade"):
     *    um disparo por regra x lead x âncora (entrada na etapa / último contato), para não redisparar a
     *    cada minuto. dry = disparo em modo Teste (não bloqueia o disparo real depois).
     *  - índices em leads.stage_entered_at para o gatilho "parado na etapa" não varrer a tabela.
     */
    public function up(): void
    {
        if (! Schema::hasTable('automation_waits')) {
            Schema::create('automation_waits', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('rule_id');
                $table->unsignedInteger('lead_id');
                $table->unsignedBigInteger('run_id')->nullable();
                $table->string('node_id', 40);
                $table->string('status', 12)->default('pending');
                $table->timestamp('resume_at');
                $table->string('flow_hash', 40)->nullable();
                $table->json('context')->nullable();
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->string('claim_token', 36)->nullable();
                $table->timestamp('claimed_at')->nullable();
                $table->string('reason', 255)->nullable();
                $table->timestamps();

                $table->foreign('rule_id')->references('id')->on('automation_rules')->onDelete('cascade');
                $table->foreign('lead_id')->references('id')->on('leads')->onDelete('cascade');
                $table->foreign('run_id')->references('id')->on('automation_runs')->nullOnDelete();
                $table->index(['status', 'resume_at']);
                $table->index(['rule_id', 'status']);
                $table->index(['lead_id', 'status']);
                $table->index(['status', 'updated_at']);
            });
        }

        if (! Schema::hasTable('automation_time_marks')) {
            Schema::create('automation_time_marks', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('rule_id');
                $table->unsignedInteger('lead_id');
                $table->timestamp('anchor_at');
                $table->boolean('dry')->default(false);
                $table->timestamp('created_at')->nullable();

                $table->foreign('rule_id')->references('id')->on('automation_rules')->onDelete('cascade');
                $table->foreign('lead_id')->references('id')->on('leads')->onDelete('cascade');
                $table->unique(['rule_id', 'lead_id', 'anchor_at', 'dry'], 'automation_time_marks_unique');
                $table->index(['lead_id', 'rule_id']);
            });
        }

        if (Schema::hasColumn('leads', 'stage_entered_at')) {
            Schema::table('leads', function (Blueprint $table) {
                if (! Schema::hasIndex('leads', 'leads_automation_stage_entered_index')) {
                    $table->index(['lead_pipeline_stage_id', 'stage_entered_at'], 'leads_automation_stage_entered_index');
                }

                if (! Schema::hasIndex('leads', 'leads_automation_entered_index')) {
                    $table->index('stage_entered_at', 'leads_automation_entered_index');
                }
            });
        }
    }

    /**
     * Desfaz a fase 2: regras com gatilho de tempo ou com bloco "Esperar" são DESLIGADAS antes (o código
     * anterior não sabe rodá-las), as esperas pendentes somem com a tabela (nada é executado) e o
     * histórico (automation_runs) fica.
     */
    public function down(): void
    {
        if (Schema::hasTable('automation_rules')) {
            DB::table('automation_rules')->whereIn('trigger_type', ['stage_stale', 'no_activity'])->update(['status' => 'off']);

            if (Schema::hasColumn('automation_rules', 'flow')) {
                DB::table('automation_rules')->whereNotNull('flow')->get(['id', 'flow'])
                    ->filter(fn ($rule) => collect((array) (json_decode((string) $rule->flow, true)['nodes'] ?? []))->contains(fn ($node) => ($node['type'] ?? null) === 'wait'))
                    ->each(fn ($rule) => DB::table('automation_rules')->where('id', $rule->id)->update(['status' => 'off']));
            }
        }

        if (Schema::hasTable('leads')) {
            // MySQL drops the foreign key's own index on lead_pipeline_stage_id when the composite index
            // above is created (the composite one serves the key). Give the key its index back first,
            // or the composite index cannot be dropped ("needed in a foreign key constraint").
            $covered = collect(Schema::getIndexes('leads'))->contains(fn ($index) => $index['name'] !== 'leads_automation_stage_entered_index'
                && ($index['columns'][0] ?? null) === 'lead_pipeline_stage_id');

            if (! $covered && Schema::hasIndex('leads', 'leads_automation_stage_entered_index')) {
                Schema::table('leads', fn (Blueprint $table) => $table->index('lead_pipeline_stage_id', 'leads_lead_pipeline_stage_id_foreign'));
            }

            Schema::table('leads', function (Blueprint $table) {
                if (Schema::hasIndex('leads', 'leads_automation_stage_entered_index')) {
                    $table->dropIndex('leads_automation_stage_entered_index');
                }

                if (Schema::hasIndex('leads', 'leads_automation_entered_index')) {
                    $table->dropIndex('leads_automation_entered_index');
                }
            });
        }

        Schema::dropIfExists('automation_time_marks');
        Schema::dropIfExists('automation_waits');
    }
};
