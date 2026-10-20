<?php

namespace Webkul\Lead\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Lead\Contracts\Pipeline as PipelineContract;

class Pipeline extends Model implements PipelineContract
{
    protected $table = 'lead_pipelines';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'type',
        'counts_in_reports',
        'rotten_days',
        'is_default',
    ];

    public const TYPES = ['commercial', 'post_sale', 'other'];

    protected $casts = ['counts_in_reports' => 'boolean'];

    /** @var list<int>|null remembered for the request */
    private static ?array $counted = null;

    protected static function booted(): void
    {
        static::saved(fn () => self::$counted = null);
        static::deleted(fn () => self::$counted = null);
    }

    /**
     * The pipelines whose deals count in the sales reports (revenue, average ticket, goals, ads): the
     * ones with "Conta nos relatórios de vendas" on. A pipeline of post-sale follow-up is usually off.
     *
     * @return list<int>
     */
    public static function countedIds(): array
    {
        return self::$counted ??= static::query()->where('counts_in_reports', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * The counted pipelines the logged user may also use (the same list for an unrestricted user): what the
     * reports built with raw queries have to stay inside.
     *
     * @return list<int>
     */
    public static function reportableIds(): array
    {
        $allowed = bouncer()->getAllowedPipelineIds();

        return $allowed === null ? self::countedIds() : array_values(array_intersect(self::countedIds(), $allowed));
    }

    public static function forgetCounted(): void
    {
        self::$counted = null;
    }

    /**
     * Get the leads.
     */
    public function leads()
    {
        return $this->hasMany(LeadProxy::modelClass(), 'lead_pipeline_id');
    }

    /**
     * Get the stages that owns the pipeline.
     */
    public function stages()
    {
        return $this->hasMany(StageProxy::modelClass(), 'lead_pipeline_id')->orderBy('sort_order', 'ASC');
    }
}
