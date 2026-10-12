<?php

namespace Webkul\WhatsApp\Support;

use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Turns a browser recording (webm/mp4/wav) into the mono OGG/Opus that WhatsApp plays as a
 * voice note. The Cloud API takes no webm, so this runs before a voice message is stored.
 */
final class VoiceTranscoder
{
    /**
     * The configured ffmpeg, or the first one found on the PATH.
     */
    public static function binary(?string $configured = null): ?string
    {
        $configured ??= config('whatsapp.ffmpeg');

        if (is_string($configured) && $configured !== '') {
            return is_file($configured) && is_executable($configured) ? $configured : null;
        }

        return (new ExecutableFinder)->find('ffmpeg');
    }

    public static function available(?string $configured = null): bool
    {
        return self::binary($configured) !== null;
    }

    /**
     * @return string  the OGG/Opus file contents
     *
     * @throws RuntimeException
     */
    public static function toOggOpus(string $source, ?string $configured = null): string
    {
        $binary = self::binary($configured) ?? throw new RuntimeException('ffmpeg is not available.');

        $target = tempnam(sys_get_temp_dir(), 'wa-voice-');

        try {
            $process = new Process([
                $binary, '-y', '-v', 'error', '-i', $source,
                '-vn', '-map_metadata', '-1',
                '-ac', '1', '-ar', '16000',
                '-c:a', 'libopus', '-b:a', '32k', '-application', 'voip',
                '-f', 'ogg', $target,
            ]);
            $process->setTimeout(30);
            $process->run();

            $contents = $process->isSuccessful() ? (string) file_get_contents($target) : '';

            if ($contents === '') {
                throw new RuntimeException('ffmpeg could not convert the audio: '.trim($process->getErrorOutput()));
            }

            return $contents;
        } finally {
            @unlink($target);
        }
    }
}
