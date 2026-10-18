<?php

namespace Webkul\Goals\Models;

use Illuminate\Database\Eloquent\Model;

class GoalSetting extends Model
{
    protected $table = 'goal_settings';

    protected $guarded = [];

    protected $casts = ['step_rules' => 'array', 'default_weekdays' => 'array', 'alert_rules' => 'array', 'alerts_enabled' => 'boolean'];
}
