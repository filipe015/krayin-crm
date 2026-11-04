<?php

namespace Webkul\WhatsApp\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Webkul\Lead\Models\LostReason;

/**
 * Configurações → Motivos de perda: the standard reasons to pick when a deal is lost, one list per pipeline.
 */
class LostReasonController extends Controller
{
    public function index(): View
    {
        return view('whatsapp::lost-reasons.index', [
            'pipelines' => DB::table('lead_pipelines')->orderBy('name')->get(['id', 'name'])->map(fn ($pipeline) => ['id' => (int) $pipeline->id, 'name' => $pipeline->name])->values(),
            'reasons' => LostReason::query()->orderBy('sort_order')->orderBy('id')->get()->map(fn (LostReason $reason) => $this->present($reason))->values(),
            'urls' => [
                'store' => route('admin.lost-reasons.store'),
                'update' => route('admin.lost-reasons.update', ['id' => '__ID__']),
                'delete' => route('admin.lost-reasons.delete', ['id' => '__ID__']),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, true);

        $data['sort_order'] = (int) LostReason::query()->where('lead_pipeline_id', $data['lead_pipeline_id'])->max('sort_order') + 1;

        return response()->json(['data' => $this->present(LostReason::query()->create($data))]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $reason = LostReason::query()->findOrFail($id);
        $reason->update($this->validated($request, false));

        return response()->json(['data' => $this->present($reason->refresh())]);
    }

    /**
     * Deals already lost with this reason keep the text they were saved with.
     */
    public function destroy(int $id): JsonResponse
    {
        $reason = LostReason::query()->findOrFail($id);

        DB::table('leads')->where('lost_reason_id', $reason->id)->update(['lost_reason_id' => null]);

        $reason->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'is_active' => ['required', 'boolean'],
        ];

        if ($creating) {
            $rules['lead_pipeline_id'] = ['required', 'integer', Rule::exists('lead_pipelines', 'id')];
        }

        $data = $request->validate($rules);
        $data['name'] = trim($data['name']);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(LostReason $reason): array
    {
        return ['id' => $reason->id, 'lead_pipeline_id' => (int) $reason->lead_pipeline_id, 'name' => $reason->name, 'is_active' => (bool) $reason->is_active];
    }
}
