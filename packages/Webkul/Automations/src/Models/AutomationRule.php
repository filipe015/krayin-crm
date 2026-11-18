<?php

namespace Webkul\Automations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One "Quando… Se… Então…" rule.
 *
 * @property int $id
 * @property string $name
 * @property string $status on | off | test
 * @property string $trigger_type
 * @property array|null $trigger_config
 * @property array|null $conditions
 * @property array $actions
 * @property bool $run_once_per_lead
 * @property array|null $flow graph of the visual builder (null = old "Quando… Se… Então…" rule)
 */
class AutomationRule extends Model
{
    public const STATUS_ON = 'on';

    public const STATUS_OFF = 'off';

    public const STATUS_TEST = 'test';

    public const STATUSES = [self::STATUS_ON, self::STATUS_OFF, self::STATUS_TEST];

    public const TRIGGER_STAGE_ENTERED = 'stage_entered';

    public const TRIGGER_LEAD_CREATED = 'lead_created';

    public const TRIGGER_TAG_ADDED = 'tag_added';

    public const TRIGGER_ACTIVITY_CREATED = 'activity_created';

    /** Fase 2: time triggers, checked every minute by automations:tick (not by lead events). */
    public const TRIGGER_STAGE_STALE = 'stage_stale';

    public const TRIGGER_NO_ACTIVITY = 'no_activity';

    public const TIME_TRIGGERS = [self::TRIGGER_STAGE_STALE, self::TRIGGER_NO_ACTIVITY];

    public const TRIGGERS = [
        self::TRIGGER_STAGE_ENTERED,
        self::TRIGGER_LEAD_CREATED,
        self::TRIGGER_TAG_ADDED,
        self::TRIGGER_ACTIVITY_CREATED,
        ...self::TIME_TRIGGERS,
    ];

    public const CONDITIONS = ['pipeline', 'stage', 'source', 'tag', 'owner', 'field'];

    /** Conditions a "Condição" block of the visual builder may use (the old ones + lead value). */
    public const FLOW_CONDITIONS = [...self::CONDITIONS, 'lead_value'];

    public const ACTIONS = [
        'move_stage',
        'transfer_pipeline',
        'assign_owner',
        'add_tag',
        'create_task',
        'notify_whatsapp',
        'webhook',
        'mark_lost',
    ];

    protected $table = 'automation_rules';

    protected $fillable = [
        'name',
        'status',
        'trigger_type',
        'trigger_config',
        'conditions',
        'actions',
        'run_once_per_lead',
        'flow',
        'created_by',
    ];

    protected $casts = [
        'trigger_config' => 'array',
        'conditions' => 'array',
        'actions' => 'array',
        'flow' => 'array',
        'run_once_per_lead' => 'boolean',
    ];

    /** Built in (or converted to) the visual builder: the engine walks the graph. */
    public function isFlow(): bool
    {
        return is_array($this->flow) && $this->flow !== [];
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class, 'rule_id');
    }

    public function waits(): HasMany
    {
        return $this->hasMany(AutomationWait::class, 'rule_id');
    }

    public function isTimeTriggered(): bool
    {
        return in_array($this->trigger_type, self::TIME_TRIGGERS, true);
    }

    public function isLive(): bool
    {
        return $this->status === self::STATUS_ON;
    }

    public function isTest(): bool
    {
        return $this->status === self::STATUS_TEST;
    }
}
