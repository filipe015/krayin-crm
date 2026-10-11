<?php

namespace Webkul\WhatsApp\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Webkul\WhatsApp\Channels\Cloud\CloudApiException;
use Webkul\WhatsApp\Channels\Cloud\CloudClient;
use Webkul\WhatsApp\Channels\Waha\SendTarget;
use Webkul\WhatsApp\Channels\Waha\WahaClient;
use Webkul\WhatsApp\Channels\Waha\WahaException;
use Webkul\WhatsApp\Models\Message;
use Webkul\WhatsApp\Models\Session;
use Webkul\WhatsApp\Support\ConversationWindow;

class SendWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * A retry could send the same text twice when the first attempt actually got through.
     */
    public int $tries = 1;

    /**
     * Worst case is the lock wait (30s) plus the throttle gap and two attempts (15s for text,
     * 25s for media), and it has to stay under the queue's retry_after (90s) or a slow send
     * would be picked up twice.
     */
    public int $timeout = 85;

    private const MIN_GAP_MS = 1200;

    private const JITTER_MS = 800;

    private const STATUS_RANK = ['queued' => 0, 'sending' => 1, 'sent' => 2, 'delivered' => 3, 'read' => 4];

    public function __construct(public int $messageId) {}

    public function handle(): void
    {
        $message = Message::query()->with('conversation.session')->find($this->messageId);

        if ($message === null || $message->direction !== 'outbound' || $message->status !== 'queued') {
            return;
        }

        $message->status = 'sending';
        $message->save();

        $conversation = $message->conversation;
        $session = $conversation->session;
        $targets = SendTarget::for($conversation);

        $reachable = $session?->channel === 'cloud'
            ? filled($conversation->contact_phone)
            : $targets !== [];

        if ($session === null || ! $reachable) {
            $this->markFailed($message->id, 'The conversation has no WhatsApp identity to send to.');

            return;
        }

        try {
            Cache::lock('whatsapp.send.'.$session->id, 90)->block(30, function () use ($message, $session, $targets) {
                $this->throttle($session->id);

                try {
                    $session->channel === 'cloud'
                        ? $this->deliverCloud($message, $session)
                        : $this->deliver($message, $session->external_id, $targets);
                } finally {
                    Cache::put($this->lastSendKey($session->id), microtime(true), 300);
                }
            });
        } catch (LockTimeoutException) {
            $this->markFailed($message->id, 'The sending queue for this number was busy. Try again.');
        }
    }

    /**
     * @param  list<string>  $targets
     */
    private function deliver(Message $message, string $session, array $targets): void
    {
        $client = WahaClient::fromConfig();
        $lastError = null;

        foreach ($targets as $chatId) {
            try {
                $response = $message->media_path !== null
                    ? $this->sendMedia($client, $message, $session, $chatId)
                    : $client->sendText($session, $chatId, (string) $message->content);

                $this->markSent($message->id, WahaClient::messageId($response));

                return;
            } catch (WahaException $exception) {
                // It may have been sent: leave it "sending". The webhook confirms it, or
                // the scheduled cleanup fails it after a few minutes without news.
                if ($exception->outcomeUnknown) {
                    return;
                }

                $lastError = $exception->getMessage();
            }
        }

        $this->markFailed($message->id, $lastError ?? 'The message could not be sent.');
    }

    /**
     * Official WhatsApp number: free-form messages only inside the 24-hour window,
     * approved templates any time.
     */
    private function deliverCloud(Message $message, Session $session): void
    {
        $conversation = $message->conversation;
        $to = preg_replace('/\D/', '', (string) $conversation->contact_phone);
        $template = is_array($message->template_payload) ? $message->template_payload : null;

        if ($template === null && ! ConversationWindow::isOpen($conversation->last_inbound_at)) {
            $this->markFailed($message->id, trans('whatsapp::app.inbox.window_closed_failed'));

            return;
        }

        try {
            $client = CloudClient::forSession($session);

            if ($template !== null) {
                $response = $client->sendTemplate($to, (string) $template['name'], (string) $template['language'], (array) ($template['components'] ?? []));
            } elseif ($message->media_path !== null) {
                $response = $this->sendCloudMedia($client, $message, $to);
            } else {
                $response = $client->sendText($to, (string) $message->content);
            }

            $this->markSent($message->id, CloudClient::messageId($response));
        } catch (CloudApiException $exception) {
            // It may have been sent: leave it "sending". The status webhook confirms it, or
            // the scheduled cleanup fails it after a few minutes without news.
            if ($exception->outcomeUnknown) {
                return;
            }

            $this->markFailed(
                $message->id,
                $exception->isWindowClosed() ? trans('whatsapp::app.inbox.window_closed_failed') : $exception->getMessage(),
            );
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws CloudApiException
     */
    private function sendCloudMedia(CloudClient $client, Message $message, string $to): array
    {
        $stored = substr((string) $message->media_path, strlen('local:'));
        $disk = Storage::disk('local');

        if (! str_starts_with((string) $message->media_path, 'local:') || ! $disk->exists($stored)) {
            throw new CloudApiException('The file to send is no longer available. Attach it again.');
        }

        $mime = (string) ($message->media_mime ?: 'application/octet-stream');
        $filename = (string) ($message->media_filename ?: basename($stored));
        $type = in_array($message->message_type, ['image', 'audio', 'video'], true) ? $message->message_type : 'document';

        $mediaId = $client->uploadMedia($mime, $filename, (string) $disk->get($stored));

        return $client->sendMedia($to, $type, $mediaId, $message->content, $filename);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws WahaException
     */
    private function sendMedia(WahaClient $client, Message $message, string $session, string $chatId): array
    {
        $stored = substr((string) $message->media_path, strlen('local:'));
        $disk = Storage::disk('local');

        if (! str_starts_with((string) $message->media_path, 'local:') || ! $disk->exists($stored)) {
            throw new WahaException('The file to send is no longer available. Attach it again.');
        }

        $kind = ['image' => 'image', 'audio' => 'voice', 'video' => 'video'][$message->message_type] ?? 'file';

        return $client->sendMedia(
            $session,
            $chatId,
            $kind,
            (string) ($message->media_mime ?: 'application/octet-stream'),
            (string) ($message->media_filename ?: basename($stored)),
            base64_encode((string) $disk->get($stored)),
            $message->content,
        );
    }

    private function markSent(int $id, ?string $externalId): void
    {
        DB::transaction(function () use ($id, $externalId) {
            $message = Message::query()->lockForUpdate()->find($id);

            if ($message === null) {
                return;
            }

            if ($externalId !== null && $message->external_message_id === null) {
                $this->absorbEcho($message, $externalId);

                $message->external_message_id = $externalId;
            }

            if (in_array($message->status, ['queued', 'sending'], true)) {
                $message->status = 'sent';
                $message->ack ??= 1;
            }

            $message->error = null;
            $message->save();
        });
    }

    /**
     * When the webhook echo of this very message got here first without being matched,
     * it created a row of its own. Fold it into ours instead of leaving a duplicate.
     */
    private function absorbEcho(Message $message, string $externalId): void
    {
        $echo = Message::query()
            ->where('conversation_id', $message->conversation_id)
            ->where('external_message_id', $externalId)
            ->where('id', '!=', $message->id)
            ->first();

        if ($echo === null) {
            return;
        }

        if ((self::STATUS_RANK[$echo->status] ?? 0) > (self::STATUS_RANK[$message->status] ?? 0)) {
            $message->status = $echo->status;
            $message->ack = $echo->ack;
        }

        if ($echo->media_path !== null && str_starts_with((string) $message->media_path, 'local:')) {
            $this->dropLocalCopy($message->media_path);
            $message->media_path = $echo->media_path;
        }

        $message->delivered_at ??= $echo->delivered_at;
        $message->read_at ??= $echo->read_at;

        $echo->delete();
    }

    private function dropLocalCopy(?string $mediaPath): void
    {
        if ($mediaPath !== null && str_starts_with($mediaPath, 'local:')) {
            Storage::disk('local')->delete(substr($mediaPath, strlen('local:')));
        }
    }

    private function markFailed(int $id, string $error): void
    {
        Message::query()
            ->whereKey($id)
            ->whereIn('status', ['queued', 'sending'])
            ->update(['status' => 'failed', 'error' => Str::limit($error, 300, ''), 'updated_at' => now()]);
    }

    /**
     * Spaces messages of one number apart, with some jitter, to look less like a bot.
     */
    private function throttle(int $sessionId): void
    {
        $gap = (self::MIN_GAP_MS + random_int(0, self::JITTER_MS)) * 1000;
        $elapsed = (int) ((microtime(true) - (float) Cache::get($this->lastSendKey($sessionId), 0)) * 1_000_000);

        if ($elapsed < $gap) {
            usleep($gap - $elapsed);
        }
    }

    private function lastSendKey(int $sessionId): string
    {
        return 'whatsapp.last-send.'.$sessionId;
    }
}
