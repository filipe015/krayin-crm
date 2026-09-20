<?php

namespace Webkul\WhatsApp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\WhatsApp\Contracts\Message as MessageContract;

class Message extends Model implements MessageContract
{
    protected $table = 'whatsapp_messages';

    protected $fillable = [
        'conversation_id',
        'direction',
        'sender_name',
        'content',
        'message_type',
        'external_message_id',
        'status',
        'source',
        'ack',
        'media_path',
        'media_mime',
        'media_filename',
        'sent_by_user_id',
        'error',
        'delivered_at',
        'read_at',
        'edited_at',
        'revoked_at',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'created_at'   => 'datetime',
        'updated_at'   => 'datetime',
        'delivered_at' => 'datetime',
        'read_at'      => 'datetime',
        'edited_at'    => 'datetime',
        'revoked_at'   => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ConversationProxy::modelClass(), 'conversation_id');
    }
}
