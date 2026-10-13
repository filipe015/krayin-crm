<?php

namespace Webkul\WhatsApp\Api\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

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

    public function users(): JsonResponse
    {
        return $this->ok(DB::table('users')->where('status', 1)->orderBy('name')->get(['id', 'name', 'email']));
    }

    public function sources(): JsonResponse
    {
        return $this->ok(DB::table('lead_sources')->orderBy('name')->get(['id', 'name']));
    }

    public function tags(): JsonResponse
    {
        return $this->ok(DB::table('tags')->orderBy('name')->get(['id', 'name', 'color']));
    }
}
