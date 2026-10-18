<?php

namespace Webkul\Goals\Models;

use Illuminate\Database\Eloquent\Model;

class GoalChange extends Model
{
    protected $table = 'goal_changes';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['changed_at' => 'datetime'];
}
