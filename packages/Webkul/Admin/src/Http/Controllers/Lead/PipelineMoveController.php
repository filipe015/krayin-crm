<?php

namespace Webkul\Admin\Http\Controllers\Lead;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Webkul\Activity\Repositories\ActivityRepository;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Attribute\Models\AttributeValue;
use Webkul\Lead\Models\Lead;
use Webkul\Lead\Models\LostReason;
use Webkul\Lead\Models\Pipeline;
use Webkul\Lead\Models\Stage;
use Webkul\Lead\Services\PipelineTransfer;

/**
 * Troca rápida de funil e etapa de um lead (menu do card no Kanban e seletor no topo da página do lead).
 *
 * As duas rotas ficam na permissão "Negócios → Editar" (Config/acl.php). Os funis e etapas vêm dos
 * models com o escopo "Acesso por funil", então um usuário restrito só vê e só usa os funis dele; o lead
 * também precisa estar na visão do usuário (individual/grupo/global), como na página do lead.
 */
class PipelineMoveController extends Controller
{
    /**
     * Funis e etapas que o usuário pode usar para este lead, com os motivos de perda de cada funil.
     */
    public function options(int $id): JsonResponse
    {
        $lead = $this->visibleLead($id);

        $pipelines = Pipeline::query()
            ->with('stages')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->filter(fn (Pipeline $pipeline) => $pipeline->stages->isNotEmpty())
            ->map(fn (Pipeline $pipeline) => [
                'id' => (int) $pipeline->id,
                'name' => $pipeline->name,
                'stages' => $pipeline->stages->sortBy('sort_order')->values()->map(fn (Stage $stage) => [
                    'id' => (int) $stage->id,
                    'name' => $stage->name,
                    'code' => $stage->code,
                    'sort_order' => (int) $stage->sort_order,
                ])->all(),
                'lost_reasons' => LostReason::optionsFor((int) $pipeline->id),
            ])
            ->values();

        return response()->json([
            'data' => [
                'lead' => [
                    'id' => (int) $lead->id,
                    'pipeline_id' => (int) $lead->lead_pipeline_id,
                    'stage_id' => (int) $lead->lead_pipeline_stage_id,
                    'stage_code' => $lead->stage?->code,
                ],
                'pipelines' => $pipelines,
                'visibility_risk_stage_ids' => $this->visibilityRiskStageIds(),
            ],
        ]);
    }

    /**
     * Leva o lead para outro funil/etapa (ou só outra etapa do mesmo funil).
     */
    public function update(int $id): JsonResponse
    {
        $this->validate(request(), [
            'lead_pipeline_id' => 'required|integer',
            'lead_pipeline_stage_id' => 'required|integer',
            'lost_reason_id' => 'nullable|integer',
            'lost_reason' => 'nullable|string|max:1000',
            'confirm_closed' => 'nullable|boolean',
        ]);

        $lead = $this->visibleLead($id);

        // Escopo "Acesso por funil": um funil que o usuário não enxerga não existe para ele.
        $pipeline = Pipeline::query()->find((int) request('lead_pipeline_id'));

        if (! $pipeline) {
            throw ValidationException::withMessages(['lead_pipeline_id' => trans('admin::app.lead-move.errors.pipeline')]);
        }

        $stage = Stage::query()
            ->where('lead_pipeline_id', $pipeline->id)
            ->find((int) request('lead_pipeline_stage_id'));

        if (! $stage) {
            throw ValidationException::withMessages(['lead_pipeline_stage_id' => trans('admin::app.lead-move.errors.stage')]);
        }

        // Destino igual ao atual: nada a fazer, nada é gravado.
        if ((int) $stage->id === (int) $lead->lead_pipeline_stage_id && (int) $pipeline->id === (int) $lead->lead_pipeline_id) {
            return response()->json([
                'changed' => false,
                'message' => trans('admin::app.lead-move.nothing-changed'),
            ]);
        }

        // Ganho/Perdido entram nos relatórios e nas Metas pela etapa atual: só com confirmação explícita.
        $closed = ['won', 'lost'];

        if ((in_array($lead->stage?->code, $closed, true) || in_array($stage->code, $closed, true)) && ! request()->boolean('confirm_closed')) {
            throw ValidationException::withMessages(['confirm_closed' => trans('admin::app.lead-move.errors.confirm-closed')]);
        }

        $extra = $stage->code === 'lost'
            ? LostReason::resolve((int) $pipeline->id, request('lost_reason_id'), request('lost_reason'))
            : [];

        $move = DB::transaction(function () use ($lead, $stage, $extra) {
            $move = PipelineTransfer::move($lead, $stage, $extra);

            $this->recordHistory($lead, $move);

            return $move;
        });

        $lead->refresh();

        return response()->json([
            'changed' => true,
            'message' => trans($move['pipeline_changed'] ? 'admin::app.lead-move.success-pipeline' : 'admin::app.lead-move.success-stage', [
                'pipeline' => $move['to_pipeline'],
                'stage' => $move['to_stage'],
            ]),
            'move' => $move,
            'lead' => [
                'id' => (int) $lead->id,
                'pipeline_id' => (int) $lead->lead_pipeline_id,
                'stage_id' => (int) $lead->lead_pipeline_stage_id,
                'stage_code' => $lead->stage?->code,
            ],
            // Uma regra (automação, regra de funil) pode ter trocado o responsável ou o funil logo depois.
            'still_visible' => $this->isVisible((int) $lead->id),
        ]);
    }

    /**
     * O lead, se este usuário pode vê-lo: escopo de funil (404) e visão individual/grupo (403).
     */
    private function visibleLead(int $id): Lead
    {
        $lead = Lead::query()->findOrFail($id);

        $userIds = bouncer()->getAuthorizedUserIds();

        abort_if($userIds && ! in_array($lead->user_id, $userIds), 403, trans('admin::app.lead-move.errors.not-visible'));

        return $lead;
    }

    /**
     * Etapas em que uma automação ligada troca o responsável ou o funil assim que o lead entra: para um
     * usuário com visão limitada (individual/grupo ou acesso por funil), o lead pode sair da visão dele.
     * A tela avisa antes de confirmar. Sem visão limitada (ou sem o módulo ligado) a lista é vazia.
     *
     * @return list<int>
     */
    private function visibilityRiskStageIds(): array
    {
        $limited = bouncer()->getAuthorizedUserIds() !== null || bouncer()->getAllowedPipelineIds() !== null;

        if (! $limited || ! config('automations.enabled') || ! Schema::hasTable('automation_rules')) {
            return [];
        }

        $hasFlow = Schema::hasColumn('automation_rules', 'flow');

        return DB::table('automation_rules')
            ->where('trigger_type', 'stage_entered')
            ->where('status', 'on')
            ->get($hasFlow ? ['trigger_config', 'actions', 'flow'] : ['trigger_config', 'actions'])
            ->filter(function ($rule) {
                $body = ($rule->actions ?? '').($rule->flow ?? '');

                return str_contains($body, 'assign_owner') || str_contains($body, 'transfer_pipeline');
            })
            ->map(fn ($rule) => (int) (json_decode((string) $rule->trigger_config, true)['stage_id'] ?? 0))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function isVisible(int $id): bool
    {
        $lead = Lead::query()->find($id);

        if (! $lead) {
            return false;
        }

        $userIds = bouncer()->getAuthorizedUserIds();

        return ! $userIds || in_array($lead->user_id, $userIds);
    }

    /**
     * Histórico do lead: "Funil: X → Y" (se mudou) e "Etapa: A → B", em nome de quem fez — no mesmo
     * formato das alterações de campo que a aba Histórico já lê. Os valores de atributo de funil/etapa
     * são acertados sem eventos para não gerar uma segunda linha (nem uma linha com valor antigo velho).
     *
     * @param  array<string, mixed>  $move
     */
    private function recordHistory(Lead $lead, array $move): void
    {
        $attributes = DB::table('attributes')
            ->where('entity_type', 'leads')
            ->whereIn('code', ['lead_pipeline_id', 'lead_pipeline_stage_id'])
            ->get(['id', 'code', 'name'])
            ->keyBy('code');

        $changes = [];

        if ($move['pipeline_changed']) {
            $changes['lead_pipeline_id'] = [$move['from_pipeline_id'], $move['from_pipeline'], $move['to_pipeline_id'], $move['to_pipeline'], trans('admin::app.lead-move.pipeline')];
        }

        if ($move['stage_changed']) {
            $changes['lead_pipeline_stage_id'] = [$move['from_stage_id'], $move['from_stage'], $move['to_stage_id'], $move['to_stage'], trans('admin::app.lead-move.stage')];
        }

        foreach ($changes as $code => [$oldId, $oldLabel, $newId, $newLabel, $fallbackName]) {
            $name = $attributes[$code]->name ?? $fallbackName;

            $activity = app(ActivityRepository::class)->create([
                'type' => 'system',
                'title' => trans('admin::app.activities.updated', ['attribute' => $name]),
                'is_done' => 1,
                'additional' => json_encode([
                    'attribute' => $name,
                    'new' => ['value' => $newId, 'label' => $newLabel],
                    'old' => ['value' => $oldId, 'label' => $oldLabel],
                    'source' => 'quick_move',
                ]),
                'user_id' => auth()->guard('user')->id(),
            ]);

            $lead->activities()->attach($activity->id);

            if (isset($attributes[$code])) {
                AttributeValue::withoutEvents(fn () => AttributeValue::query()->updateOrCreate(
                    ['entity_type' => 'leads', 'entity_id' => $lead->id, 'attribute_id' => $attributes[$code]->id],
                    ['integer_value' => $newId],
                ));
            }
        }
    }
}
