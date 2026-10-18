<?php

namespace Webkul\Goals\Models;

use Illuminate\Database\Eloquent\Model;

class GoalPlan extends Model
{
    protected $table = 'goal_plans';

    protected $guarded = [];

    protected $casts = ['overrides' => 'array'];
}
