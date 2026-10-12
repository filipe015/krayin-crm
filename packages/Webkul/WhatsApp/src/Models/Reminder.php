<?php

namespace Webkul\WhatsApp\Models;

use Illuminate\Database\Eloquent\Model;

class Reminder extends Model
{
    protected $table = 'whatsapp_reminders';

    protected $fillable = ['activity_id', 'lead_id', 'conversation_id', 'offset_minutes', 'message_id', 'appointment_at'];

    protected $casts = ['appointment_at' => 'datetime'];
}
