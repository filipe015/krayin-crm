<?php

namespace Webkul\WhatsApp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\Lead\Models\LeadProxy;
use Webkul\WhatsApp\Contracts\Conversation as ConversationContract;

class Conversation extends Model implements ConversationContract
{
    protected $table = 'whatsapp_conversations';

    protected $fillable = [
        'lead_id',
        'canal_origem',
        'external_conversation_id',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(LeadProxy::modelClass());
    }

    public function messages(): HasMany
    {
        return $this->hasMany(MessageProxy::modelClass(), 'conversation_id');
    }
}
