<?php

namespace Webkul\WhatsApp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Webkul\Lead\Models\Pipeline;
use Webkul\Tag\Models\Tag;
use Webkul\WhatsApp\Models\ButtonRule;

class ButtonRuleController extends Controller
{
    public function list(): JsonResponse
    {
        $pipelines = Pipeline::query()->with(['stages' => fn ($query) => $query->orderBy('sort_order')])->orderBy('id')->get();
        $several = $pipelines->count() > 1;

        return response()->json([
            'data'   => ButtonRule::query()->orderBy('match_text')->get(['id', 'match_text', 'stage_id', 'tag_id']),
            'stages' => $pipelines->flatMap(fn ($pipeline) => $pipeline->stages->map(fn ($stage) => [
                'id'   => $stage->id,
                'name' => $several ? $pipeline->name.' · '.$stage->name : $stage->name,
            ]))->values(),
            'tags'   => Tag::query()->orderBy('name')->limit(200)->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'match_text' => ['required', 'string', 'max:100', Rule::unique('whatsapp_button_rules', 'match_text')],
            'stage_id'   => ['nullable', 'integer', 'exists:lead_pipeline_stages,id'],
            'tag_id'     => ['nullable', 'integer', 'exists:tags,id'],
        ]);

        abort_if(empty($data['stage_id']) && empty($data['tag_id']), 422, trans('whatsapp::app.connections.rule_needs_action'));

        $rule = ButtonRule::query()->create([
            'match_text' => trim($data['match_text']),
            'stage_id'   => $data['stage_id'] ?? null,
            'tag_id'     => $data['tag_id'] ?? null,
        ]);

        return response()->json(['data' => $rule->only(['id', 'match_text', 'stage_id', 'tag_id'])], 201);
    }

    public function destroy(int $id): JsonResponse
    {
        ButtonRule::query()->findOrFail($id)->delete();

        return response()->json(['deleted' => true]);
    }
}
