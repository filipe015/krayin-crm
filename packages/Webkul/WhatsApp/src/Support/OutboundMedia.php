<?php

namespace Webkul\WhatsApp\Support;

/**
 * What the Inbox accepts to send, and how each kind maps to WAHA and to a message type.
 */
final class OutboundMedia
{
    public const MAX_KB = 16384;

    private const MIMES = [
        'image' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
        'audio' => [
            'audio/ogg', 'application/ogg', 'audio/opus', 'audio/mpeg', 'audio/mp3', 'audio/mp4', 'audio/x-m4a',
            'audio/m4a', 'audio/aac', 'audio/webm', 'audio/wav', 'audio/x-wav', 'audio/wave',
        ],
        'video' => ['video/mp4', 'video/3gpp', 'video/quicktime', 'video/webm'],
        'document' => [
            'application/pdf', 'text/plain', 'text/csv', 'application/zip', 'application/rtf',
            'application/msword', 'application/vnd.ms-excel', 'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ],
    ];

    /**
     * Browsers record voice notes as audio-only webm/mp4, which the server's file sniffing
     * often calls video, so a recording is accepted by its declared kind instead.
     */
    private const RECORDING_MIMES = ['audio/webm', 'video/webm', 'audio/mp4', 'video/mp4', 'audio/ogg', 'application/ogg'];

    /**
     * @return array{type: string, kind: string, mime: string}|null  type is the message type, kind the WAHA endpoint family.
     */
    public static function classify(?string $mime, bool $asVoice = false): ?array
    {
        $mime = strtolower(trim(explode(';', (string) $mime)[0]));

        if ($asVoice && in_array($mime, self::RECORDING_MIMES, true)) {
            return ['type' => 'audio', 'kind' => 'voice', 'mime' => $mime];
        }

        foreach (self::MIMES as $type => $mimes) {
            if (in_array($mime, $mimes, true)) {
                return ['type' => $type, 'kind' => ['image' => 'image', 'audio' => 'voice', 'video' => 'video', 'document' => 'file'][$type], 'mime' => $mime];
            }
        }

        return null;
    }
}
