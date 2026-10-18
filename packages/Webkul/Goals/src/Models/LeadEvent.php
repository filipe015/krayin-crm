<?php

namespace Webkul\Goals\Models;

use Illuminate\Database\Eloquent\Model;

class LeadEvent extends Model
{
    public const CREATED = 'created';

    public const STAGE_ENTERED = 'stage_entered';

    public const TAG_ADDED = 'tag_added';

    public const APPOINTMENT_CREATED = 'appointment_created';

    public const UPDATED_AT = null;

    protected $fillable = ['lead_id', 'kind', 'pipeline_id', 'stage_id', 'tag_id', 'activity_id', 'user_id', 'occurred_at', 'source'];

    protected $casts = ['occurred_at' => 'datetime'];
}
