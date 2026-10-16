<?php

namespace Webkul\WhatsApp\Api\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\Attribute\Repositories\AttributeRepository;
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

    /**
     * Custom fields ("Campos Personalizados") of leads or persons, system ones included, so an
     * integration can see which codes exist before sending values in "custom".
     */
    public function attributes(Request $request): JsonResponse
    {
        $data = $request->validate(['entity' => ['nullable', 'in:leads,persons']]);

        $rows = DB::table('attributes')
            ->where('entity_type', $data['entity'] ?? 'leads')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'code', 'name', 'type', 'is_user_defined', 'is_required']);

        return $this->ok($rows->map(fn ($row) => [
            'id'              => $row->id,
            'code'            => $row->code,
            'name'            => $row->name,
            'type'            => $row->type,
            'is_user_defined' => (bool) $row->is_user_defined,
            'is_required'     => (bool) $row->is_required,
        ])->values());
    }

    /**
     * Creates a custom field. Idempotent: an existing field with the same code, or with the same
     * name (ignoring case and accents), is returned instead.
     */
    public function createAttribute(Request $request, AttributeRepository $attributes): JsonResponse
    {
        $data = $request->validate([
            'entity' => ['required', 'in:leads,persons'],
            'name'   => ['required', 'string', 'max:100'],
            'type'   => ['required', 'in:text,textarea,date,datetime,price,email,phone,boolean'],
            'code'   => ['nullable', 'regex:/^[a-z][a-z0-9_]{1,59}$/'],
        ]);

        $normalize = fn (string $text) => trim(preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($text))));

        $existing = DB::table('attributes')->where('entity_type', $data['entity'])->get(['id', 'code', 'name', 'type'])
            ->first(fn ($row) => (! empty($data['code']) && $row->code === $data['code']) || $normalize($row->name) === $normalize($data['name']));

        if ($existing) {
            return $this->ok(['id' => $existing->id, 'code' => $existing->code, 'name' => $existing->name, 'type' => $existing->type, 'created' => false]);
        }

        $code = $data['code'] ?? Str::limit(Str::slug($data['name'], '_'), 55, '');

        $base = $code !== '' ? $code : 'campo';
        $code = $base;

        for ($i = 2; DB::table('attributes')->where('entity_type', $data['entity'])->where('code', $code)->exists(); $i++) {
            $code = $base.'_'.$i;
        }

        $attribute = $attributes->create([
            'code'            => $code,
            'name'            => trim($data['name']),
            'type'            => $data['type'],
            'entity_type'     => $data['entity'],
            'is_required'     => 0,
            'is_unique'       => 0,
            'quick_add'       => 0,
            'is_user_defined' => 1,
        ]);

        DB::table('attributes')->where('id', $attribute->id)->update([
            'sort_order' => ((int) DB::table('attributes')->where('entity_type', $data['entity'])->max('sort_order')) + 1,
        ]);

        return $this->ok(['id' => $attribute->id, 'code' => $code, 'name' => trim($data['name']), 'type' => $data['type'], 'created' => true], [], 201);
    }

    public function users(): JsonResponse
    {
        return $this->ok(DB::table('users')->where('status', 1)->orderBy('name')->get(['id', 'name', 'email']));
    }

    /**
     * Creates a lead source (idempotent by name, case-insensitive).
     */
    public function createSource(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);

        $name = trim($data['name']);

        $existing = DB::table('lead_sources')->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first(['id', 'name']);

        if ($existing) {
            return $this->ok(['id' => $existing->id, 'name' => $existing->name, 'created' => false]);
        }

        $id = DB::table('lead_sources')->insertGetId(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);

        return $this->ok(['id' => $id, 'name' => $name, 'created' => true], [], 201);
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
