<?php

namespace Webkul\Automations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One execution (or non-execution) of a rule on a lead: the history screen.
 */
class AutomationRun extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_OK = 'ok';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DRY_RUN = 'dry_run';

    /** Fase 2: the flow is paused by an "Esperar" block (automation_waits); the same row gets the rest. */
    public const STATUS_WAITING = 'waiting';

    public const STATUSES = [self::STATUS_OK, self::STATUS_SKIPPED, self::STATUS_FAILED, self::STATUS_DRY_RUN, self::STATUS_RUNNING, self::STATUS_WAITING];

    public const UPDATED_AT = null;

    protected $table = 'automation_runs';

    protected $fillable = [
        'rule_id',
        'lead_id',
        'trigger',
        'status',
        'steps',
        'error',
        'duration_ms',
        'depth',
        'idempotency_key',
        'created_at',
    ];

    protected $casts = [
        'steps' => 'array',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'rule_id');
    }
}
