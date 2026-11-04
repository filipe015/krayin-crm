<?php

namespace Webkul\Lead\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * A standard reason for losing a deal, owned by one pipeline.
 */
class LostReason extends Model
{
    protected $table = 'lead_lost_reasons';

    protected $fillable = ['lead_pipeline_id', 'name', 'sort_order', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    /**
     * The reasons a user can pick for a pipeline, as [{id, name}].
     *
     * @return list<array{id: int, name: string}>
     */
    public static function optionsFor(int $pipelineId): array
    {
        return static::query()
            ->where('lead_pipeline_id', $pipelineId)
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn (self $reason) => ['id' => (int) $reason->id, 'name' => $reason->name])
            ->all();
    }

    /**
     * What to save on the lead when it is lost in this pipeline. When the pipeline has reasons, one of
     * them is required (the free text is gone); a pipeline with none keeps the old free text.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function resolve(int $pipelineId, mixed $reasonId, ?string $freeText = null): array
    {
        $options = collect(static::optionsFor($pipelineId));

        if ($options->isEmpty()) {
            return $freeText !== null && $freeText !== '' ? ['lost_reason' => $freeText, 'lost_reason_id' => null] : [];
        }

        $picked = $options->firstWhere('id', (int) $reasonId);

        if ($picked === null) {
            throw ValidationException::withMessages(['lost_reason_id' => trans('admin::app.leads.lost-reason-required')]);
        }

        return ['lost_reason' => $picked['name'], 'lost_reason_id' => $picked['id']];
    }
}
