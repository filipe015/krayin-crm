<?php

namespace Webkul\WhatsApp\Channels\Cloud;

use DateTimeImmutable;
use DateTimeZone;
use Webkul\WhatsApp\Channels\Events\InboundMessage;
use Webkul\WhatsApp\Channels\Events\MessageAck;

/**
 * Turns a WhatsApp Cloud API webhook into the channel-neutral events the Inbox already
 * understands. The chat id is "<wa_id>@c.us", the same shape the WAHA channel uses, so
 * conversations are matched by the existing phone logic (Brazilian ninth digit included).
 */
final class CloudWebhookParser
{
    /**
     * @param  array<string, mixed>  $payload
     * @return list<InboundMessage|MessageAck>
     */
    public static function parse(array $payload): array
    {
        $events = [];

        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                if (($change['field'] ?? null) !== 'messages' || ! is_array($change['value'] ?? null)) {
                    continue;
                }

                $value = $change['value'];
                $session = (string) ($value['metadata']['phone_number_id'] ?? '');

                if ($session === '') {
                    continue;
                }

                $names = [];

                foreach ((array) ($value['contacts'] ?? []) as $contact) {
                    if (isset($contact['wa_id'])) {
                        $names[(string) $contact['wa_id']] = ($contact['profile']['name'] ?? null) ?: null;
                    }
                }

                foreach ((array) ($value['messages'] ?? []) as $message) {
                    $event = self::message($session, (array) $message, $names);

                    if ($event !== null) {
                        $events[] = $event;
                    }
                }

                foreach ((array) ($value['statuses'] ?? []) as $status) {
                    $event = self::status($session, (array) $status);

                    if ($event !== null) {
                        $events[] = $event;
                    }
                }
            }
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  array<string, ?string>  $names
     */
    private static function message(string $session, array $message, array $names): ?InboundMessage
    {
        $from = preg_replace('/\D/', '', (string) ($message['from'] ?? ''));
        $id = (string) ($message['id'] ?? '');

        if ($from === '' || $id === '') {
            return null;
        }

        $type = (string) ($message['type'] ?? 'text');

        // Reactions and system notices are not conversation content.
        if (in_array($type, ['reaction', 'system', 'request_welcome'], true)) {
            return null;
        }

        [$mapped, $body, $mediaId, $mime, $filename] = self::content($type, $message);

        return new InboundMessage(
            session: $session,
            chatId: $from.'@c.us',
            phone: $from,
            lid: null,
            contactName: $names[$from] ?? null,
            externalId: $id,
            fromMe: false,
            source: 'cloud',
            type: $mapped,
            body: $body,
            mediaPath: $mediaId !== null ? 'cloud:'.$mediaId : null,
            mediaMime: $mime,
            mediaFilename: $filename,
            sentAt: self::moment($message['timestamp'] ?? null),
            channel: 'cloud',
            buttonReply: $type === 'button'
                || ($type === 'interactive' && in_array($message['interactive']['type'] ?? null, ['button_reply', 'list_reply'], true)),
        );
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array{0: string, 1: ?string, 2: ?string, 3: ?string, 4: ?string}  type, body, media id, mime, filename
     */
    private static function content(string $type, array $message): array
    {
        return match ($type) {
            'text'     => ['text', self::string($message['text']['body'] ?? null), null, null, null],
            'image'    => ['image', self::string($message['image']['caption'] ?? null), self::string($message['image']['id'] ?? null), self::mime($message['image']['mime_type'] ?? null), null],
            'video'    => ['video', self::string($message['video']['caption'] ?? null), self::string($message['video']['id'] ?? null), self::mime($message['video']['mime_type'] ?? null), null],
            'audio'    => ['audio', null, self::string($message['audio']['id'] ?? null), self::mime($message['audio']['mime_type'] ?? null), null],
            'voice'    => ['audio', null, self::string($message['voice']['id'] ?? null), self::mime($message['voice']['mime_type'] ?? null), null],
            'document' => ['document', self::string($message['document']['caption'] ?? null), self::string($message['document']['id'] ?? null), self::mime($message['document']['mime_type'] ?? null), self::string($message['document']['filename'] ?? null)],
            'sticker'  => ['sticker', null, self::string($message['sticker']['id'] ?? null), self::mime($message['sticker']['mime_type'] ?? null), null],
            'location' => ['location', self::locationText($message['location'] ?? []), null, null, null],
            'contacts' => ['contact', self::contactsText((array) ($message['contacts'] ?? [])), null, null, null],
            'button'   => ['text', self::string($message['button']['text'] ?? null), null, null, null],
            'interactive' => ['text', self::interactiveText((array) ($message['interactive'] ?? [])), null, null, null],
            default    => ['text', null, null, null, null],
        };
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private static function status(string $session, array $status): ?MessageAck
    {
        $id = (string) ($status['id'] ?? '');

        if ($id === '') {
            return null;
        }

        $ack = match ((string) ($status['status'] ?? '')) {
            'sent'      => 1,
            'delivered' => 2,
            'read'      => 3,
            'failed'    => -1,
            default     => null,
        };

        if ($ack === null) {
            return null;
        }

        $error = null;

        if ($ack === -1) {
            $first = (array) (($status['errors'][0] ?? []));
            $error = self::string($first['error_data']['details'] ?? ($first['message'] ?? ($first['title'] ?? null)));
        }

        return new MessageAck($session, [$id], $ack, 'cloud', $error);
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private static function mime(mixed $mime): ?string
    {
        return is_string($mime) && $mime !== '' ? strtolower(trim(explode(';', $mime)[0])) : null;
    }

    private static function moment(mixed $timestamp): DateTimeImmutable
    {
        $time = is_numeric($timestamp) ? (int) $timestamp : time();

        return (new DateTimeImmutable('@'.$time))->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * @param  array<string, mixed>  $location
     */
    private static function locationText(array $location): ?string
    {
        $parts = array_filter([
            $location['name'] ?? null,
            $location['address'] ?? null,
            isset($location['latitude'], $location['longitude']) ? $location['latitude'].', '.$location['longitude'] : null,
        ], fn ($part) => is_string($part) && $part !== '');

        return $parts === [] ? null : implode("\n", $parts);
    }

    /**
     * @param  list<array<string, mixed>>  $contacts
     */
    private static function contactsText(array $contacts): ?string
    {
        $lines = [];

        foreach ($contacts as $contact) {
            $name = $contact['name']['formatted_name'] ?? null;
            $phone = $contact['phones'][0]['phone'] ?? null;

            $line = trim(implode(' ', array_filter([$name, $phone], fn ($part) => is_string($part) && $part !== '')));

            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines === [] ? null : implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $interactive
     */
    private static function interactiveText(array $interactive): ?string
    {
        return self::string($interactive['button_reply']['title'] ?? null)
            ?? self::string($interactive['list_reply']['title'] ?? null);
    }
}
