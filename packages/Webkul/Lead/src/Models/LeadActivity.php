<?php

namespace Webkul\Lead\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\Event;

/**
 * The link between a deal and an activity (note, task, meeting...). It announces itself as
 * "lead.activity.attached" for the modules that care (see LeadTag).
 */
class LeadActivity extends Pivot
{
    protected $table = 'lead_activities';

    public $incrementing = false;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::created(fn (self $pivot) => Event::dispatch('lead.activity.attached', [(int) $pivot->lead_id, (int) $pivot->activity_id]));
    }
}
