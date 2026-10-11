<?php

namespace Webkul\WhatsApp\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Webkul\WhatsApp\Models\Message;

/**
 * Streams a message attachment from WAHA through the CRM, so the browser never needs
 * the WAHA api key. Contact-controlled files are only shown inline for safe types.
 */
class MessageMediaController extends Controller
{
    private const INLINE_MIME = '#^(image/(jpeg|png|gif|webp)|audio/[\w.+-]+|video/(mp4|webm|ogg|3gpp|quicktime)|application/pdf)$#';

    public function __invoke(int $id): StreamedResponse
    {
        $message = Message::query()->findOrFail($id);

        if (str_starts_with((string) $message->media_path, 'local:')) {
            return $this->local($message);
        }

        $baseUrl = rtrim((string) config('whatsapp.waha.url'), '/');

        abort_unless($message->media_path && str_starts_with($message->media_path, '/api/files/'), 404);
        abort_if($baseUrl === '', 503, 'WAHA_API_URL is not configured.');

        $upstream = Http::withHeaders(['X-Api-Key' => (string) config('whatsapp.waha.api_key')])
            ->withOptions(['stream' => true])
            ->timeout(60)
            ->get($baseUrl.$message->media_path);

        abort_unless($upstream->successful(), 404);

        $mime = $message->media_mime ?: 'application/octet-stream';
        $inline = (bool) preg_match(self::INLINE_MIME, $mime);
        $filename = str_replace('"', '', Str::ascii($message->media_filename ?: basename($message->media_path)));

        return response()->stream(function () use ($upstream) {
            $body = $upstream->toPsrResponse()->getBody();

            while (! $body->eof()) {
                echo $body->read(8192);
                flush();
            }
        }, 200, [
            'Content-Type'            => $inline ? $mime : 'application/octet-stream',
            'Content-Disposition'     => ($inline ? 'inline' : 'attachment').'; filename="'.$filename.'"',
            'Content-Security-Policy' => 'sandbox',
            'X-Content-Type-Options'  => 'nosniff',
            'Cache-Control'           => 'private, max-age=86400',
        ]);
    }

    /**
     * A file the CRM itself is sending (or sent and WAHA has not echoed back yet).
     */
    private function local(Message $message): StreamedResponse
    {
        $stored = substr((string) $message->media_path, strlen('local:'));
        $disk = Storage::disk('local');

        abort_unless($disk->exists($stored), 404);

        $mime = $message->media_mime ?: 'application/octet-stream';
        $inline = (bool) preg_match(self::INLINE_MIME, $mime);
        $filename = str_replace('"', '', Str::ascii($message->media_filename ?: basename($stored)));

        return $disk->response($stored, $filename, [
            'Content-Type'            => $inline ? $mime : 'application/octet-stream',
            'Content-Security-Policy' => 'sandbox',
            'X-Content-Type-Options'  => 'nosniff',
            'Cache-Control'           => 'private, max-age=3600',
        ], $inline ? 'inline' : 'attachment');
    }
}
