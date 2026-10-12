<?php

namespace Webkul\WhatsApp\Models;

use Illuminate\Database\Eloquent\Model;

class ReminderSetting extends Model
{
    protected $table = 'whatsapp_reminder_settings';

    protected $fillable = [
        'enabled', 'stage_id', 'keep_stage_ids', 'activity_type', 'template_name',
        'template_language', 'variables', 'offsets', 'session_id',
    ];

    protected $casts = [
        'enabled'        => 'boolean',
        'keep_stage_ids' => 'array',
        'variables'      => 'array',
        'offsets'        => 'array',
    ];
}
