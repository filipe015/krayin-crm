<?php

namespace Webkul\Goals\Models;

use Illuminate\Database\Eloquent\Model;

class GoalMonth extends Model
{
    protected $table = 'goal_months';

    protected $guarded = [];

    protected $casts = ['working_days' => 'array'];
}
