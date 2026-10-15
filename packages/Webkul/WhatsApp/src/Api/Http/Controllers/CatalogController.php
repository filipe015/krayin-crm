<?php

namespace Webkul\WhatsApp\Api\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\Lead\Repositories\PipelineRepository;

/**
 * Small read-only lists an integration needs to build requests: funnels and stages, people,
 * sources and tags.
 */
class CatalogController extends ApiController
{
    public function ping(): JsonResponse
    {
        return $this->ok([
            'name'        => config('app.name'),
            'api_version' => 'v1',
            'time'        => now()->toIso8601String(),
            'timezone'    => config('app.timezone'),
        ]);
    }

    public function pipelines(): JsonResponse
    {
        $stages = DB::table('lead_pipeline_stages')->orderBy('sort_order')->get(['id', 'lead_pipeline_id', 'code', 'name', 'sort_order'])->groupBy('lead_pipeline_id');

        return $this->ok(DB::table('lead_pipelines')->orderBy('id')->get(['id', 'name', 'is_default'])->map(fn ($pipeline) => [
            'id'         => $pipeline->id,
            'name'       => $pipeline->name,
            'is_default' => (bool) $pipeline->is_default,
            'stages'     => ($stages[$pipeline->id] ?? collect())->map(fn ($stage) => [
                'id'   => $stage->id,
                'name' => $stage->name,
                'code' => $stage->code,
            ])->values(),
        ])->values());
    }

    /**
     * Creates a funnel with its stages (used to replicate another system's board). Idempotent by
     * name: an existing funnel is returned untouched. "Ganho" and "Perdido" are added at the end
     * when the list has no stage of type won/lost.
     */
    public function createPipeline(Request $request, PipelineRepository $pipelines): JsonResponse
    {
        $data = $request->validate([
            'name'                 => ['required', 'string', 'max:100'],
            'stages'               => ['required', 'array', 'min:1', 'max:40'],
            'stages.*.name'        => ['required', 'string', 'max:100'],
            'stages.*.type'        => ['nullable', 'in:open,won,lost'],
            'stages.*.probability' => ['nullable', 'integer', 'between:0,100'],
        ]);

        $existing = DB::table('lead_pipelines')->where('name', $data['name'])->first();

        if ($existing) {
            return $this->ok($this->presentPipeline((int) $existing->id) + ['created' => false]);
        }

        $stages = [];
        $codes = [];

        foreach ($data['stages'] as $stage) {
            $type = $stage['type'] ?? 'open';

            $base = $type === 'open' ? (Str::slug($stage['name']) ?: 'etapa') : $type;
            $code = $base;

            for ($i = 2; in_array($code, $codes, true) || in_array($code, ['won', 'lost'], true) && $type === 'open'; $i++) {
                $code = $base.'-'.$i;
            }

            $codes[] = $code;

            $stages[] = [
                'name'        => $stage['name'],
                'code'        => $code,
                'probability' => $stage['probability'] ?? ($type === 'won' ? 100 : ($type === 'lost' ? 0 : 10)),
            ];
        }

        if (! in_array('won', $codes, true)) {
            $stages[] = ['name' => 'Ganho', 'code' => 'won', 'probability' => 100];
        }

        if (! in_array('lost', $codes, true)) {
            $stages[] = ['name' => 'Perdido', 'code' => 'lost', 'probability' => 0];
        }

        foreach ($stages as $index => &$stage) {
            $stage['sort_order'] = $index + 1;
        }

        unset($stage);

        $pipeline = $pipelines->create([
            'name'        => $data['name'],
            'rotten_days' => 30,
            'is_default'  => 0,
            'stages'      => $stages,
        ]);

        return $this->ok($this->presentPipeline((int) $pipeline->id) + ['created' => true], [], 201);
    }

    private function presentPipeline(int $id): array
    {
        $pipeline = DB::table('lead_pipelines')->where('id', $id)->first(['id', 'name', 'is_default']);

        return [
            'id'         => $pipeline->id,
            'name'       => $pipeline->name,
            'is_default' => (bool) $pipeline->is_default,
            'stages'     => DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $id)->orderBy('sort_order')
                ->get(['id', 'name', 'code'])->map(fn ($stage) => ['id' => $stage->id, 'name' => $stage->name, 'code' => $stage->code])->values(),
        ];
    }

    public function users(): JsonResponse
    {
        return $this->ok(DB::table('users')->where('status', 1)->orderBy('name')->get(['id', 'name', 'email']));
    }

    public function sources(): JsonResponse
    {
        return $this->ok(DB::table('lead_sources')->orderBy('name')->get(['id', 'name']));
    }

    /**
     * Creates a tag (idempotent by name, case-insensitive) so imported labels have somewhere to land.
     */
    public function createTag(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'  => ['required', 'string', 'max:100'],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $existing = DB::table('tags')->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($data['name']))])->first(['id', 'name', 'color']);

        if ($existing) {
            return $this->ok(['id' => $existing->id, 'name' => $existing->name, 'color' => $existing->color, 'created' => false]);
        }

        $id = DB::table('tags')->insertGetId([
            'name'       => trim($data['name']),
            'color'      => $data['color'] ?? '#8979FF',
            'user_id'    => DB::table('users')->orderBy('id')->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->ok(['id' => $id, 'name' => trim($data['name']), 'color' => $data['color'] ?? '#8979FF', 'created' => true], [], 201);
    }

    public function tags(): JsonResponse
    {
        return $this->ok(DB::table('tags')->orderBy('name')->get(['id', 'name', 'color']));
    }
}
