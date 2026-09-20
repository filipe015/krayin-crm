<?php

namespace Webkul\WhatsApp\Channels\Waha;

use DateTimeImmutable;
use Webkul\WhatsApp\Channels\Events\InboundMessage;
use Webkul\WhatsApp\Channels\Events\MessageAck;
use Webkul\WhatsApp\Channels\Events\MessageEdited;
use Webkul\WhatsApp\Channels\Events\MessageRevoked;
use Webkul\WhatsApp\Channels\Events\SessionStatus;

/**
 * Turns a WAHA (GOWS engine) webhook envelope into a channel-neutral event.
 *
 * Field locations were confirmed against real GOWS 2026.8.2 payloads: the chat is
 * always `_data.Info.Chat` (also for fromMe), and the real phone of an `@lid` chat
 * lives in `SenderAlt` (incoming) or `RecipientAlt` (fromMe).
 */
final class WahaWebhookParser
{
    public static function parse(array $envelope): InboundMessage|MessageAck|MessageEdited|MessageRevoked|SessionStatus|null
    {
        $session = (string) ($envelope['session'] ?? '');
        $payload = $envelope['payload'] ?? null;

        if ($session === '' || ! is_array($payload)) {
            return null;
        }

        return match ($envelope['event'] ?? null) {
            'message', 'message.any' => self::message($session, $payload),
            'message.ack'            => self::ack($session, $payload),
            'message.edited'         => self::edited($session, $payload),
            'message.revoked'        => self::revoked($session, $payload),
            'session.status'         => self::sessionStatus($session, $envelope['me'] ?? null, $payload),
            default                  => null,
        };
    }

    private static function message(string $session, array $payload): ?InboundMessage
    {
        $info = $payload['_data']['Info'] ?? [];
        $chat = (string) ($info['Chat'] ?? $payload['from'] ?? '');

        if ($chat === '' || ! empty($info['IsGroup']) || preg_match('/@(g\.us|broadcast|newsletter)$/', $chat)) {
            return null;
        }

        $externalId = (string) ($info['ID'] ?? self::bareId((string) ($payload['id'] ?? '')));

        if ($externalId === '') {
            return null;
        }

        $media = is_array($payload['media'] ?? null) ? $payload['media'] : null;
        $mime = self::mime($media['mimetype'] ?? null);
        $body = self::body($payload);

        if ($body === null && $media === null && empty($payload['hasMedia']) && empty($payload['location']) && empty($payload['vCards'])) {
            return null;
        }

        $fromMe = (bool) ($payload['fromMe'] ?? false);
        $isLid = str_ends_with($chat, '@lid');

        $phone = self::phone($info[$fromMe ? 'RecipientAlt' : 'SenderAlt'] ?? null)
            ?? ($isLid ? null : self::phone($chat));

        $mediaPath = isset($media['url']) ? parse_url((string) $media['url'], PHP_URL_PATH) : null;

        return new InboundMessage(
            session: $session,
            chatId: preg_replace('/@s\.whatsapp\.net$/', '@c.us', $chat),
            phone: $phone,
            lid: $isLid ? $chat : null,
            // On fromMe payloads pushName is the operator's name, never the contact's.
            contactName: $fromMe ? null : (($info['PushName'] ?? '') ?: null),
            externalId: $externalId,
            fromMe: $fromMe,
            source: (string) ($payload['source'] ?? 'app'),
            type: self::type($payload, $mime, $info['MediaType'] ?? null),
            body: $body,
            mediaPath: is_string($mediaPath) && $mediaPath !== '' ? $mediaPath : null,
            mediaMime: $mime,
            mediaFilename: ($media['filename'] ?? null) ?: null,
            sentAt: self::moment($payload['timestamp'] ?? null),
        );
    }

    private static function ack(string $session, array $payload): ?MessageAck
    {
        $ids = array_values(array_filter(array_map('strval', $payload['_data']['MessageIDs'] ?? [])));

        if ($ids === []) {
            $id = self::bareId((string) ($payload['id'] ?? ''));

            $ids = $id === '' ? [] : [$id];
        }

        if ($ids === [] || ! isset($payload['ack'])) {
            return null;
        }

        return new MessageAck($session, $ids, (int) $payload['ack']);
    }

    /**
     * The payload id belongs to WhatsApp's internal protocol message; the message that
     * was edited is named by editedMessageId and the new text arrives in body.
     */
    private static function edited(string $session, array $payload): ?MessageEdited
    {
        $id = self::bareId((string) ($payload['editedMessageId'] ?? ''));
        $body = self::body($payload);

        return $id === '' || $body === null ? null : new MessageEdited($session, $id, $body);
    }

    private static function revoked(string $session, array $payload): ?MessageRevoked
    {
        $id = self::bareId((string) ($payload['revokedMessageId'] ?? ''));

        return $id === '' ? null : new MessageRevoked($session, $id);
    }

    private static function sessionStatus(string $session, mixed $me, array $payload): ?SessionStatus
    {
        $status = strtoupper((string) ($payload['status'] ?? ''));

        if ($status === '') {
            return null;
        }

        $me = is_array($me) ? $me : [];

        return new SessionStatus($session, $status, self::phone($me['id'] ?? null), ($me['pushName'] ?? null) ?: null);
    }

    private static function type(array $payload, ?string $mime, mixed $mediaType): string
    {
        if (! empty($payload['location'])) {
            return 'location';
        }

        if (! empty($payload['vCards'])) {
            return 'contact';
        }

        if (empty($payload['hasMedia']) && $mime === null) {
            return 'text';
        }

        $mediaType = strtolower((string) $mediaType);

        return match (true) {
            $mediaType === 'sticker'                                   => 'sticker',
            $mediaType === 'ptt', str_starts_with((string) $mime, 'audio/') => 'audio',
            $mediaType === 'image', str_starts_with((string) $mime, 'image/') => 'image',
            $mediaType === 'video', str_starts_with((string) $mime, 'video/') => 'video',
            default                                                    => 'document',
        };
    }

    private static function body(array $payload): ?string
    {
        $message = $payload['_data']['Message'] ?? [];

        $body = $payload['body']
            ?? $message['extendedTextMessage']['text']
            ?? $message['conversation']
            ?? null;

        return is_string($body) && $body !== '' ? $body : null;
    }

    private static function mime(mixed $mime): ?string
    {
        return is_string($mime) && $mime !== '' ? strtolower(trim(explode(';', $mime)[0])) : null;
    }

    /**
     * "556195684042:4@s.whatsapp.net" -> "556195684042" (drops the device suffix).
     */
    private static function phone(mixed $jid): ?string
    {
        if (! is_string($jid) || $jid === '') {
            return null;
        }

        $user = explode(':', explode('@', $jid, 2)[0], 2)[0];
        $digits = preg_replace('/\D/', '', $user);

        return strlen($digits) >= 8 && strlen($digits) <= 15 ? $digits : null;
    }

    /**
     * "true_<chat>_3EB0..." -> "3EB0...". Chat ids never contain an underscore.
     */
    private static function bareId(string $id): string
    {
        $position = strrpos($id, '_');

        return $position === false ? $id : substr($id, $position + 1);
    }

    /**
     * WAHA sends seconds, but proxies may forward milliseconds or nanoseconds.
     */
    private static function moment(mixed $timestamp): DateTimeImmutable
    {
        $value = is_numeric($timestamp) ? (float) $timestamp : 0.0;

        if ($value >= 1e16) {
            $value /= 1e9;
        } elseif ($value >= 1e11) {
            $value /= 1e3;
        }

        return $value > 0 ? new DateTimeImmutable('@'.(int) $value) : new DateTimeImmutable;
    }
}
