<?php

namespace Webkul\WhatsApp\Support;

use Illuminate\Support\Str;
use Webkul\WhatsApp\Models\Message;

/**
 * State changes of a message that waits for its time to be sent.
 */
final class ScheduledMessage
{
    /**
     * Turns a due message into a regular queued one. The conditional update makes sure two
     * runs of the scheduler never release (and so send) the same message twice.
     */
    public static function release(Message $message): bool
    {
        $claimed = Message::query()
            ->whereKey($message->id)
            ->where('status', 'scheduled')
            ->update(['status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);

        if ($claimed !== 1) {
            return false;
        }

        $conversation = $message->conversation;

        if ($conversation !== null) {
            $conversation->last_message_at = now();
            $conversation->last_message_preview = Str::limit($message->content ?: '['.$message->message_type.']', 280, '');
            $conversation->status = 'open';
            $conversation->save();
        }

        return true;
    }

    public static function fail(Message $message, string $error): void
    {
        Message::query()
            ->whereKey($message->id)
            ->where('status', 'scheduled')
            ->update(['status' => 'failed', 'error' => Str::limit($error, 300, ''), 'updated_at' => now()]);
    }
}
