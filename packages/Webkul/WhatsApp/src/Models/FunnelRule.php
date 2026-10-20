<?php

namespace Webkul\WhatsApp\Models;

use Illuminate\Database\Eloquent\Model;

class FunnelRule extends Model
{
    public const MODES = ['owner', 'fixed', 'round_robin'];

    protected $fillable = [
        'name',
        'is_active',
        'source_pipeline_id',
        'target_pipeline_id',
        'target_stage_id',
        'assignment_mode',
        'assigned_user_id',
        'pool_user_ids',
        'last_assigned_user_id',
        'copy_value',
        'move_conversation',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'pool_user_ids' => 'array',
        'copy_value' => 'boolean',
        'move_conversation' => 'boolean',
    ];
}
