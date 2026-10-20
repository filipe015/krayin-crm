<?php

namespace Webkul\WhatsApp\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Webkul\User\Models\User;
use Webkul\WhatsApp\Models\FunnelRule;

/**
 * Configurações → Regras de funil: "when a deal is won in this pipeline, open a card in that one".
 */
class FunnelRuleController extends Controller
{
    public function index(): View
    {
        $pipelines = DB::table('lead_pipelines')->orderBy('name')->get(['id', 'name']);
        $stages = DB::table('lead_pipeline_stages')->whereNotIn('code', ['won', 'lost'])->orderBy('sort_order')->get(['id', 'name', 'lead_pipeline_id'])->groupBy('lead_pipeline_id');

        return view('whatsapp::funnel-rules.index', [
            'rules' => FunnelRule::query()->orderBy('id')->get()->map(fn (FunnelRule $rule) => $this->present($rule))->values(),
            'pipelines' => $pipelines->map(fn ($pipeline) => [
                'id' => (int) $pipeline->id,
                'name' => $pipeline->name,
                'stages' => ($stages[$pipeline->id] ?? collect())->map(fn ($stage) => ['id' => (int) $stage->id, 'name' => $stage->name])->values()->all(),
            ])->values(),
            'users' => User::query()->where('status', 1)->orderBy('name')->get(['id', 'name']),
            'urls' => [
                'store' => route('admin.funnel-rules.store'),
                'update' => route('admin.funnel-rules.update', ['id' => '__ID__']),
                'delete' => route('admin.funnel-rules.delete', ['id' => '__ID__']),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $rule = FunnelRule::query()->create($this->validated($request));

        return response()->json(['data' => $this->present($rule)]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $rule = FunnelRule::query()->findOrFail($id);
        $rule->update($this->validated($request));

        return response()->json(['data' => $this->present($rule->refresh())]);
    }

    public function destroy(int $id): JsonResponse
    {
        FunnelRule::query()->findOrFail($id)->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'is_active' => ['required', 'boolean'],
            'source_pipeline_id' => ['required', 'integer', Rule::exists('lead_pipelines', 'id')],
            'target_pipeline_id' => ['required', 'integer', Rule::exists('lead_pipelines', 'id'), 'different:source_pipeline_id'],
            'target_stage_id' => ['required', 'integer', Rule::exists('lead_pipeline_stages', 'id')],
            'assignment_mode' => ['required', Rule::in(FunnelRule::MODES)],
            'assigned_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'pool_user_ids' => ['nullable', 'array', 'max:100'],
            'pool_user_ids.*' => ['integer', Rule::exists('users', 'id')],
            'copy_value' => ['required', 'boolean'],
            'move_conversation' => ['required', 'boolean'],
        ]);

        // The first stage must be an open one of the target pipeline: a card never opens already won or lost.
        $stage = DB::table('lead_pipeline_stages')->where('id', $data['target_stage_id'])->first();

        abort_unless($stage && (int) $stage->lead_pipeline_id === (int) $data['target_pipeline_id'] && ! in_array($stage->code, ['won', 'lost'], true), 422, trans('whatsapp::app.funnel_rules.stage_invalid'));

        abort_if($data['assignment_mode'] === 'fixed' && empty($data['assigned_user_id']), 422, trans('whatsapp::app.funnel_rules.user_required'));
        abort_if($data['assignment_mode'] === 'round_robin' && empty($data['pool_user_ids']), 422, trans('whatsapp::app.funnel_rules.pool_required'));

        $data['assigned_user_id'] = $data['assignment_mode'] === 'fixed' ? $data['assigned_user_id'] : null;
        $data['pool_user_ids'] = $data['assignment_mode'] === 'round_robin' ? array_values(array_unique(array_map('intval', $data['pool_user_ids']))) : null;

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(FunnelRule $rule): array
    {
        return [
            'id' => $rule->id,
            'name' => (string) $rule->name,
            'is_active' => (bool) $rule->is_active,
            'source_pipeline_id' => (int) $rule->source_pipeline_id,
            'target_pipeline_id' => (int) $rule->target_pipeline_id,
            'target_stage_id' => (int) $rule->target_stage_id,
            'assignment_mode' => $rule->assignment_mode,
            'assigned_user_id' => $rule->assigned_user_id ? (int) $rule->assigned_user_id : null,
            'pool_user_ids' => array_map('intval', (array) $rule->pool_user_ids),
            'copy_value' => (bool) $rule->copy_value,
            'move_conversation' => (bool) $rule->move_conversation,
        ];
    }
}
