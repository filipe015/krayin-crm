<?php

namespace Webkul\WhatsApp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\WhatsApp\Channels\Waha\SendTarget;
use Webkul\WhatsApp\Jobs\SendWhatsAppMessage;
use Webkul\WhatsApp\Models\Conversation;
use Webkul\WhatsApp\Models\Message;
use Webkul\WhatsApp\Support\InboxPresenter;

class InboxMessageController extends Controller
{
    public function store(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:4096']]);

        $conversation = Conversation::query()->whereNotNull('session_id')->with('session')->findOrFail($id);

        abort_if($conversation->session?->status !== 'WORKING', 409, trans('whatsapp::app.inbox.session_offline'));
        abort_if(SendTarget::for($conversation) === [], 422, trans('whatsapp::app.inbox.no_identity'));

        $user = auth()->guard('user')->user();

        $message = DB::transaction(function () use ($conversation, $data, $user) {
            $message = Message::query()->create([
                'conversation_id' => $conversation->id,
                'direction'       => 'outbound',
                'status'          => 'queued',
                'source'          => 'crm',
                'content'         => $data['text'],
                'message_type'    => 'text',
                'sent_by_user_id' => $user->id,
                'sender_name'     => $user->name,
            ]);

            $conversation->last_message_at = $message->created_at;
            $conversation->last_message_preview = Str::limit($data['text'], 280, '');
            $conversation->assigned_user_id ??= $user->id;
            $conversation->status = 'open';
            $conversation->save();

            return $message;
        });

        SendWhatsAppMessage::dispatch($message->id);

        return response()->json(['data' => InboxPresenter::message($message->refresh())], 201);
    }

    public function retry(int $id): JsonResponse
    {
        $message = Message::query()
            ->with('conversation.session')
            ->where('direction', 'outbound')
            ->where('source', 'crm')
            ->where('status', 'failed')
            ->findOrFail($id);

        abort_if($message->conversation->session?->status !== 'WORKING', 409, trans('whatsapp::app.inbox.session_offline'));

        // A fresh timestamp keeps the webhook echo of this new attempt inside the window in
        // which it is recognised as the same message.
        $message->status = 'queued';
        $message->error = null;
        $message->created_at = now();
        $message->save();

        SendWhatsAppMessage::dispatch($message->id);

        return response()->json(['data' => InboxPresenter::message($message->refresh())]);
    }
}
