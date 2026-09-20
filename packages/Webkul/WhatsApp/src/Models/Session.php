<?php

namespace Webkul\WhatsApp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\WhatsApp\Contracts\Session as SessionContract;

class Session extends Model implements SessionContract
{
    protected $table = 'whatsapp_sessions';

    protected $fillable = [
        'channel',
        'name',
        'external_id',
        'status',
        'phone',
        'credentials',
        'status_changed_at',
    ];

    protected $casts = [
        'credentials'       => 'encrypted:array',
        'status_changed_at' => 'datetime',
    ];

    public function conversations(): HasMany
    {
        return $this->hasMany(ConversationProxy::modelClass(), 'session_id');
    }
}
