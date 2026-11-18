<?php

namespace Webkul\Automations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A flow paused by an "Esperar" block (Fase 2). Picked up by automations:tick when resume_at is due.
 *
 * @property int $id
 * @property int $rule_id
 * @property int $lead_id
 * @property int|null $run_id
 * @property string $node_id the "Esperar" block (the flow goes on from its exit)
 * @property string $status
 * @property \Illuminate\Support\Carbon $resume_at
 * @property string|null $flow_hash FlowGraph::hash() of the flow when it paused
 * @property array|null $context trigger, depth, chain and the lead's stage when it paused
 * @property int $attempts
 * @property string|null $claim_token
 * @property string|null $reason
 */
class AutomationWait extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_RESUMED = 'resumed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    /** Still to happen: what counts for the limit and the "esperas pendentes" counter. */
    public const OPEN = [self::STATUS_PENDING, self::STATUS_QUEUED, self::STATUS_RUNNING];

    public const FINISHED = [self::STATUS_RESUMED, self::STATUS_CANCELLED, self::STATUS_EXPIRED];

    public const MAX_ATTEMPTS = 5;

    protected $table = 'automation_waits';

    protected $fillable = [
        'rule_id', 'lead_id', 'run_id', 'node_id', 'status', 'resume_at', 'flow_hash', 'context',
        'attempts', 'claim_token', 'claimed_at', 'reason',
    ];

    protected $casts = [
        'resume_at' => 'datetime',
        'claimed_at' => 'datetime',
        'context' => 'array',
        'attempts' => 'integer',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'rule_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class, 'run_id');
    }
}
