<?php

namespace Webkul\WhatsApp\Support;

use Webkul\WhatsApp\Models\Message;

class InboxPresenter
{
    public static function message(Message $message): array
    {
        return [
            'id'          => $message->id,
            'direction'   => $message->direction,
            'type'        => $message->message_type,
            'content'     => $message->content,
            'status'      => $message->status,
            'error'       => $message->status === 'failed' ? $message->error : null,
            'sender_name' => $message->sender_name,
            'created_at'  => $message->created_at?->toIso8601String(),
            'edited'      => $message->edited_at !== null,
            'revoked'     => $message->revoked_at !== null,
            'scheduled_at' => $message->status === 'scheduled' ? $message->scheduled_at?->toIso8601String() : null,
            'media'       => $message->media_path ? [
                'url'      => route('admin.whatsapp.inbox.messages.media', $message->id),
                'mime'     => $message->media_mime,
                'filename' => $message->media_filename,
            ] : null,
        ];
    }
}
