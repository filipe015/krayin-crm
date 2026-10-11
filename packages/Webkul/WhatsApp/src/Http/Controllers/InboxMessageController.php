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
use Webkul\WhatsApp\Support\OutboundMedia;

class InboxMessageController extends Controller
{
    public function store(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'text'  => ['nullable', 'string', 'max:4096', 'required_without:file'],
            'file'  => ['nullable', 'file', 'max:'.OutboundMedia::MAX_KB],
            'voice' => ['nullable', 'boolean'],
        ]);

        $conversation = Conversation::query()->whereNotNull('session_id')->with('session')->findOrFail($id);

        abort_if($conversation->session?->status !== 'WORKING', 409, trans('whatsapp::app.inbox.session_offline'));
        abort_if(SendTarget::for($conversation) === [], 422, trans('whatsapp::app.inbox.no_identity'));

        $upload = $request->file('file');
        $media = null;

        if ($upload !== null) {
            $media = OutboundMedia::classify($upload->getMimeType() ?: $upload->getClientMimeType(), (bool) ($data['voice'] ?? false))
                ?? OutboundMedia::classify($upload->getClientMimeType(), (bool) ($data['voice'] ?? false));

            abort_if($media === null, 422, trans('whatsapp::app.inbox.file_type_not_allowed'));
        }

        $text = isset($data['text']) ? trim((string) $data['text']) : '';
        $user = auth()->guard('user')->user();

        $path = null;

        if ($upload !== null) {
            $extension = strtolower($upload->getClientOriginalExtension() ?: $upload->extension() ?: 'bin');
            $path = $upload->storeAs('whatsapp/outbound', Str::uuid().'.'.preg_replace('/[^a-z0-9]/', '', $extension), 'local');
        }

        $message = DB::transaction(function () use ($conversation, $text, $user, $media, $upload, $path) {
            $message = Message::query()->create([
                'conversation_id' => $conversation->id,
                'direction'       => 'outbound',
                'status'          => 'queued',
                'source'          => 'crm',
                'content'         => $text !== '' ? $text : null,
                'message_type'    => $media['type'] ?? 'text',
                'media_path'      => $path !== null ? 'local:'.$path : null,
                'media_mime'      => $media['mime'] ?? null,
                'media_filename'  => $upload !== null ? Str::limit($upload->getClientOriginalName(), 200, '') : null,
                'sent_by_user_id' => $user->id,
                'sender_name'     => $user->name,
            ]);

            $conversation->last_message_at = $message->created_at;
            $conversation->last_message_preview = Str::limit($text !== '' ? $text : '['.($media['type'] ?? 'text').']', 280, '');
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
