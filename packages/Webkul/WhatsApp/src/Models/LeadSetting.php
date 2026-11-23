<?php

namespace Webkul\WhatsApp\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\WhatsApp\Support\LeadCreationSettings;

/**
 * How WhatsApp conversations become leads in this installation (a single row). Read through
 * LeadCreationSettings, which falls back to the WHATSAPP_AUTO_LEAD* env while it does not exist.
 */
class LeadSetting extends Model
{
    protected $table = 'whatsapp_lead_settings';

    protected $fillable = ['mode', 'pipeline_id', 'stage_id', 'source', 'tags', 'user_id'];

    protected static function booted(): void
    {
        // Incoming messages read the settings through a short cache.
        static::saved(fn () => LeadCreationSettings::forget());
        static::deleted(fn () => LeadCreationSettings::forget());
    }
}
