<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Construtor visual (fase 1): a regra pode guardar um fluxo (grafo JSON de nós + ligações).
     *
     *  - flow NULL      → regra antiga ("Quando… Se… Então…"), o motor usa trigger/conditions/actions;
     *  - flow preenchido → o motor percorre o grafo (veja Support/FlowValidator.php para o formato).
     *
     * As colunas antigas continuam sendo gravadas (gatilho sempre; condições/ações quando o fluxo é
     * linear), então desfazer esta migration devolve as regras ao formato antigo sem perder nada.
     */
    public function up(): void
    {
        if (Schema::hasColumn('automation_rules', 'flow')) {
            return;
        }

        Schema::table('automation_rules', function (Blueprint $table) {
            $table->json('flow')->nullable()->after('actions');
        });
    }

    /**
     * Fluxos com ramificação não cabem no formato antigo (ficaram com actions = []): são desligados
     * antes de a coluna sair, para nenhuma regra "vazia" ficar ligada.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('automation_rules', 'flow')) {
            return;
        }

        DB::table('automation_rules')
            ->whereNotNull('flow')
            ->get(['id', 'actions'])
            ->filter(fn ($rule) => (array) json_decode((string) $rule->actions, true) === [])
            ->each(fn ($rule) => DB::table('automation_rules')->where('id', $rule->id)->update(['status' => 'off']));

        Schema::table('automation_rules', function (Blueprint $table) {
            $table->dropColumn('flow');
        });
    }
};
