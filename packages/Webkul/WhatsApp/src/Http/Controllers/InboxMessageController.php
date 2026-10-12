<?php

namespace Webkul\WhatsApp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Webkul\WhatsApp\Channels\Waha\SendTarget;
use Webkul\WhatsApp\Channels\Waha\WahaClient;
use Webkul\WhatsApp\Jobs\SendWhatsAppMessage;
use Webkul\WhatsApp\Models\Conversation;
use Webkul\WhatsApp\Models\Message;
use Webkul\WhatsApp\Support\ConversationWindow;
use Webkul\WhatsApp\Support\InboxPresenter;
use Webkul\WhatsApp\Support\OutboundMedia;
use Webkul\WhatsApp\Support\VoiceTranscoder;

class InboxMessageController extends Controller
{
    public function store(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'text'  => ['nullable', 'string', 'max:4096', 'required_without:file'],
            'file'  => ['nullable', 'file', 'max:'.OutboundMedia::MAX_KB],
            'voice' => ['nullable', 'boolean'],
            'scheduled_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'schedule_title' => ['nullable', 'string', 'max:100'],
        ]);

        $scheduledAt = $this->scheduleTime($data['scheduled_at'] ?? null);

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

        // A recording (or an audio the Cloud API refuses) is converted to a voice note.
        $convertVoice = false;

        if ($conversation->session?->channel === 'cloud') {
            if ($media !== null && $media['type'] === 'audio' && ((bool) ($data['voice'] ?? false) || ! OutboundMedia::cloudSupports($media))) {
                abort_unless(VoiceTranscoder::available(), 422, trans('whatsapp::app.inbox.cloud_audio_unsupported'));

                $convertVoice = true;
            } elseif ($media !== null && ! OutboundMedia::cloudSupports($media)) {
                abort(422, trans('whatsapp::app.inbox.cloud_file_unsupported'));
            }

            // Free-form messages are only allowed inside the 24-hour window; a scheduled one is
            // checked again when it is due.
            abort_if(
                $scheduledAt === null && ! ConversationWindow::isOpen($conversation->last_inbound_at),
                422,
                trans('whatsapp::app.inbox.window_closed'),
            );
        }

        $text = isset($data['text']) ? trim((string) $data['text']) : '';
        $user = auth()->guard('user')->user();

        $path = null;
        $filename = $upload !== null ? Str::limit($upload->getClientOriginalName(), 200, '') : null;

        if ($upload !== null && $convertVoice) {
            try {
                $voice = VoiceTranscoder::toOggOpus((string) $upload->getRealPath());
            } catch (\RuntimeException $exception) {
                report($exception);

                abort(422, trans('whatsapp::app.inbox.voice_convert_failed'));
            }

            $path = 'whatsapp/outbound/'.Str::uuid().'.ogg';
            Storage::disk('local')->put($path, $voice);

            $media = ['type' => 'audio', 'kind' => 'voice', 'mime' => 'audio/ogg'];
            $filename = (pathinfo((string) $filename, PATHINFO_FILENAME) ?: 'audio').'.ogg';
        } elseif ($upload !== null) {
            $extension = strtolower($upload->getClientOriginalExtension() ?: $upload->extension() ?: 'bin');
            $path = $upload->storeAs('whatsapp/outbound', Str::uuid().'.'.preg_replace('/[^a-z0-9]/', '', $extension), 'local');
        }

        $title = trim((string) ($data['schedule_title'] ?? '')) ?: null;

        $message = DB::transaction(function () use ($conversation, $text, $user, $media, $filename, $path, $scheduledAt, $title) {
            $message = Message::query()->create([
                'conversation_id' => $conversation->id,
                'direction'       => 'outbound',
                'status'          => $scheduledAt !== null ? 'scheduled' : 'queued',
                'scheduled_at'    => $scheduledAt,
                'scheduled_title' => $scheduledAt !== null ? $title : null,
                'source'          => 'crm',
                'content'         => $text !== '' ? $text : null,
                'message_type'    => $media['type'] ?? 'text',
                'media_path'      => $path !== null ? 'local:'.$path : null,
                'media_mime'      => $media['mime'] ?? null,
                'media_filename'  => $filename,
                'sent_by_user_id' => $user->id,
                'sender_name'     => $user->name,
            ]);

            // A scheduled message changes nothing in the conversation until it is sent.
            if ($scheduledAt === null) {
                $conversation->last_message_at = $message->created_at;
                $conversation->last_message_preview = Str::limit($text !== '' ? $text : '['.($media['type'] ?? 'text').']', 280, '');
                $conversation->status = 'open';
            }

            $conversation->assigned_user_id ??= $user->id;
            $conversation->save();

            return $message;
        });

        if ($scheduledAt === null) {
            SendWhatsAppMessage::dispatch($message->id);
        }

        return response()->json(['data' => InboxPresenter::message($message->refresh())], 201);
    }

    /**
     * The operator started or stopped writing: pass it on to the customer's WhatsApp.
     * Best effort and after the response, so a slow WAHA never makes typing lag.
     */
    public function typing(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['state' => ['required', 'in:typing,recording,paused']]);

        $conversation = Conversation::query()->whereNotNull('session_id')->with('session:id,external_id,status')->findOrFail($id);

        if (! config('whatsapp.send_typing')
            || $conversation->session?->channel !== 'waha'
            || $conversation->session?->status !== 'WORKING'
            || $conversation->chat_id === null) {
            return response()->json(['sent' => false]);
        }

        // "paused" always goes through; the active states are limited to one per 3 s.
        if ($data['state'] !== 'paused' && ! Cache::add('whatsapp.typing-out.'.$conversation->id, 1, 3)) {
            return response()->json(['sent' => false]);
        }

        $session = $conversation->session->external_id;
        $chatId = $conversation->chat_id;
        $state = $data['state'];

        dispatch(function () use ($session, $chatId, $state) {
            try {
                WahaClient::fromConfig()->setPresence($session, $chatId, $state);
            } catch (\Throwable) {
                // The indicator is a nicety; never fail the operator's typing.
            }
        })->afterResponse();

        return response()->json(['sent' => true]);
    }

    /**
     * Changes a message that has not been sent yet: its time and, optionally, its title
     * and its text (or caption, when it carries a file).
     */
    public function reschedule(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'scheduled_at'   => ['required', 'date_format:Y-m-d\TH:i'],
            'schedule_title' => ['nullable', 'string', 'max:100'],
            'text'           => ['nullable', 'string', 'max:4096'],
        ]);

        $message = $this->scheduledMessage($id);

        if ($request->has('text')) {
            $text = trim((string) ($data['text'] ?? ''));

            abort_if($text === '' && $message->media_path === null, 422, trans('whatsapp::app.inbox.schedule_needs_content'));

            $message->content = $text !== '' ? $text : null;
        }

        if ($request->has('schedule_title')) {
            $message->scheduled_title = trim((string) ($data['schedule_title'] ?? '')) ?: null;
        }

        $message->scheduled_at = $this->scheduleTime($data['scheduled_at']);
        $message->save();

        return response()->json(['data' => InboxPresenter::message($message->refresh())]);
    }

    /**
     * Drops a scheduled message (and the file it was holding) before it is sent.
     */
    public function cancelSchedule(int $id): JsonResponse
    {
        $message = $this->scheduledMessage($id);

        if (str_starts_with((string) $message->media_path, 'local:')) {
            Storage::disk('local')->delete(substr((string) $message->media_path, strlen('local:')));
        }

        $message->delete();

        return response()->json(['deleted' => true]);
    }

    private function scheduledMessage(int $id): Message
    {
        return Message::query()
            ->where('direction', 'outbound')
            ->where('source', 'crm')
            ->where('status', 'scheduled')
            ->findOrFail($id);
    }

    /**
     * Reads "2026-10-15T09:00" in the CRM's timezone and keeps it between a minute from
     * now and a year ahead.
     */
    private function scheduleTime(?string $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        $time = Carbon::createFromFormat('Y-m-d\TH:i', $value, config('app.timezone'));

        abort_if($time->lt(now()->addMinute()), 422, trans('whatsapp::app.inbox.schedule_in_past'));
        abort_if($time->gt(now()->addYear()), 422, trans('whatsapp::app.inbox.schedule_too_far'));

        return $time;
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
