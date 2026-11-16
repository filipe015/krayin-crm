<?php

namespace Webkul\WhatsApp\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Sends one team notice (WHATSAPP_NOTIFY_*) through WAHA's /api/sendText. It never throws:
 * a failure only becomes a warning in the log.
 */
class SendAgentNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * A retry could deliver the same notice twice when the first attempt got through.
     */
    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        public string $session,
        public string $chatId,
        public string $text,
    ) {}

    public function handle(): void
    {
        $url = trim((string) (config('whatsapp.notify.waha_url') ?: config('whatsapp.waha.url')));
        $key = (string) (config('whatsapp.notify.waha_api_key') ?: config('whatsapp.waha.api_key'));

        if ($url === '' || $key === '') {
            Log::warning('WhatsApp notice skipped: WAHA_API_URL / WAHA_API_KEY are not configured.');

            return;
        }

        try {
            $response = Http::withHeaders(['X-Api-Key' => $key])
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(10)
                ->post(rtrim($url, '/').'/api/sendText', [
                    'session' => $this->session,
                    'chatId'  => $this->chatId,
                    'text'    => $this->text,
                ]);

            if ($response->failed()) {
                Log::warning('WhatsApp notice was refused by WAHA.', [
                    'chat_id' => $this->chatId,
                    'status'  => $response->status(),
                    'body'    => Str::limit((string) $response->body(), 300, ''),
                ]);
            }
        } catch (\Throwable $exception) {
            Log::warning('WhatsApp notice could not be sent.', [
                'chat_id' => $this->chatId,
                'error'   => $exception->getMessage(),
            ]);
        }
    }
}
