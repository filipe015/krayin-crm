<?php

namespace Webkul\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Core\Contracts\KanbanCardPreference as KanbanCardPreferenceContract;

class KanbanCardPreference extends Model implements KanbanCardPreferenceContract
{
    /**
     * Table name.
     *
     * @var string
     */
    protected $table = 'kanban_card_preferences';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
        'src',
        'preferences',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'preferences' => 'json',
    ];
}
