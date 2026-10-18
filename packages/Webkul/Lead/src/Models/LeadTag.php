<?php

namespace Webkul\Lead\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\Event;

/**
 * The link between a deal and a tag. A model of its own (instead of a bare table) so that putting a tag
 * on a deal, from any screen, the API or an automation, announces itself: other modules react to
 * "lead.tag.attached" without every place that tags a deal having to know about them.
 */
class LeadTag extends Pivot
{
    protected $table = 'lead_tags';

    public $incrementing = false;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::created(fn (self $pivot) => Event::dispatch('lead.tag.attached', [(int) $pivot->lead_id, (int) $pivot->tag_id]));
    }
}
