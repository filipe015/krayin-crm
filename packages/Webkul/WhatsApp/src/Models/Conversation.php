<?php

namespace Webkul\WhatsApp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\Lead\Models\LeadProxy;
use Webkul\User\Models\UserProxy;
use Webkul\WhatsApp\Contracts\Conversation as ConversationContract;

class Conversation extends Model implements ConversationContract
{
    protected $table = 'whatsapp_conversations';

    protected $fillable = [
        'lead_id',
        'session_id',
        'chat_id',
        'canal_origem',
        'external_conversation_id',
        'contact_phone',
        'contact_lid',
        'contact_name',
        'assigned_user_id',
        'status',
        'last_message_at',
        'last_message_preview',
        'last_inbound_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'last_inbound_at' => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(LeadProxy::modelClass());
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(SessionProxy::modelClass(), 'session_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(UserProxy::modelClass(), 'assigned_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(MessageProxy::modelClass(), 'conversation_id');
    }
}
