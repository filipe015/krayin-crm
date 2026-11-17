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

    public const TRIGGERS = [
        self::TRIGGER_STAGE_ENTERED,
        self::TRIGGER_LEAD_CREATED,
        self::TRIGGER_TAG_ADDED,
        self::TRIGGER_ACTIVITY_CREATED,
    ];

    public const CONDITIONS = ['pipeline', 'stage', 'source', 'tag', 'owner', 'field'];

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
        'created_by',
    ];

    protected $casts = [
        'trigger_config' => 'array',
        'conditions' => 'array',
        'actions' => 'array',
        'run_once_per_lead' => 'boolean',
    ];

    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class, 'rule_id');
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
