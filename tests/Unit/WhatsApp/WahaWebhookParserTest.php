<?php

use Webkul\WhatsApp\Channels\Events\InboundMessage;
use Webkul\WhatsApp\Channels\Events\MessageAck;
use Webkul\WhatsApp\Channels\Events\MessageEdited;
use Webkul\WhatsApp\Channels\Events\MessageRevoked;
use Webkul\WhatsApp\Channels\Events\PresenceChanged;
use Webkul\WhatsApp\Channels\Events\SessionStatus;
use Webkul\WhatsApp\Channels\Waha\WahaWebhookParser;
use Webkul\WhatsApp\Support\PhoneNumberMatcher;

// Shapes mirror real GOWS 2026.8.2 webhooks; numbers, ids and names are fake.
function wahaEnvelope(string $event, array $payload): array
{
    return [
        'id'        => 'evt_test',
        'timestamp' => 1789936561000,
        'event'     => $event,
        'session'   => 'teste',
        'me'        => ['id' => '551100000000@c.us', 'pushName' => 'Operator', 'lid' => '999@lid'],
        'payload'   => $payload,
    ];
}

function wahaIncomingText(array $overrides = [], array $info = []): array
{
    return array_replace_recursive([
        'id'        => 'false_111111111111111@lid_3EB0AAAA',
        'timestamp' => 1789936561,
        'from'      => '111111111111111@lid',
        'fromMe'    => false,
        'source'    => 'app',
        'body'      => 'Oi',
        'hasMedia'  => false,
        'media'     => null,
        '_data'     => ['Info' => array_merge([
            'Chat'      => '111111111111111@lid',
            'IsGroup'   => false,
            'SenderAlt' => '551188887777:4@s.whatsapp.net',
            'ID'        => '3EB0AAAA',
            'PushName'  => 'Maria',
            'MediaType' => '',
        ], $info)],
    ], $overrides);
}

it('parses an incoming text from an @lid chat and recovers the real phone', function () {
    $message = WahaWebhookParser::parse(wahaEnvelope('message.any', wahaIncomingText()));

    expect($message)->toBeInstanceOf(InboundMessage::class)
        ->and($message->chatId)->toBe('111111111111111@lid')
        ->and($message->lid)->toBe('111111111111111@lid')
        ->and($message->phone)->toBe('551188887777')
        ->and($message->contactName)->toBe('Maria')
        ->and($message->externalId)->toBe('3EB0AAAA')
        ->and($message->fromMe)->toBeFalse()
        ->and($message->type)->toBe('text')
        ->and($message->body)->toBe('Oi')
        ->and($message->sentAt->getTimestamp())->toBe(1789936561);
});

it('finds the lead phone even when WhatsApp drops the ninth digit', function () {
    $message = WahaWebhookParser::parse(wahaEnvelope('message.any', wahaIncomingText()));

    expect(PhoneNumberMatcher::matches($message->phone, '(11) 98888-7777'))->toBeTrue();
});

it('uses the chat, the recipient phone and no contact name for messages sent from the phone', function () {
    $message = WahaWebhookParser::parse(wahaEnvelope('message.any', wahaIncomingText([
        'id'     => 'true_111111111111111@lid_3EB0BBBB',
        'fromMe' => true,
        'body'   => 'Olá',
    ], [
        'ID'           => '3EB0BBBB',
        'SenderAlt'    => '551100000000@s.whatsapp.net',
        'RecipientAlt' => '551188887777@s.whatsapp.net',
        'PushName'     => 'Operator',
    ])));

    expect($message->fromMe)->toBeTrue()
        ->and($message->chatId)->toBe('111111111111111@lid')
        ->and($message->phone)->toBe('551188887777')
        ->and($message->contactName)->toBeNull();
});

it('parses a message sent through the API, whose chat is named by phone instead of LID', function () {
    $message = WahaWebhookParser::parse(wahaEnvelope('message.any', [
        'id'       => 'true_551188887777@c.us_3EB0DDDD',
        'timestamp' => 1789939881,
        'from'     => '551188887777@c.us',
        'fromMe'   => true,
        'source'   => 'api',
        'body'     => 'teste via api',
        'hasMedia' => false,
        'media'    => null,
        '_data'    => ['Info' => [
            'Chat' => '551188887777@s.whatsapp.net', 'IsGroup' => false, 'SenderAlt' => '', 'RecipientAlt' => '',
            'ID'   => '3EB0DDDD', 'PushName' => '', 'MediaType' => '',
        ]],
    ]));

    expect($message->fromMe)->toBeTrue()
        ->and($message->source)->toBe('api')
        ->and($message->chatId)->toBe('551188887777@c.us')
        ->and($message->phone)->toBe('551188887777')
        ->and($message->lid)->toBeNull()
        ->and($message->externalId)->toBe('3EB0DDDD');
});

it('keeps only the path of the media url, because WAHA reports localhost', function () {
    $message = WahaWebhookParser::parse(wahaEnvelope('message.any', wahaIncomingText([
        'body'     => null,
        'hasMedia' => true,
        'media'    => [
            'url'      => 'http://localhost:3000/api/files/teste/3EB0AAAA.png',
            'filename' => 'foto.png',
            'mimetype' => 'image/png',
        ],
    ], ['MediaType' => 'document'])));

    expect($message->type)->toBe('image')
        ->and($message->body)->toBeNull()
        ->and($message->mediaPath)->toBe('/api/files/teste/3EB0AAAA.png')
        ->and($message->mediaMime)->toBe('image/png')
        ->and($message->mediaFilename)->toBe('foto.png');
});

it('parses a voice note and drops the codec from the mime type', function () {
    $message = WahaWebhookParser::parse(wahaEnvelope('message.any', wahaIncomingText([
        'body'     => null,
        'hasMedia' => true,
        'media'    => ['url' => 'http://localhost:3000/api/files/teste/x.oga', 'mimetype' => 'audio/ogg; codecs=opus'],
    ], ['MediaType' => 'ptt'])));

    expect($message->type)->toBe('audio')
        ->and($message->mediaMime)->toBe('audio/ogg');
});

it('ignores groups, broadcasts and empty messages', function (array $payload) {
    expect(WahaWebhookParser::parse(wahaEnvelope('message.any', $payload)))->toBeNull();
})->with([
    'group'     => [wahaIncomingText([], ['Chat' => '120363000000000000@g.us', 'IsGroup' => true])],
    'status'    => [wahaIncomingText([], ['Chat' => 'status@broadcast'])],
    'no content' => [wahaIncomingText(['body' => null])],
]);

it('maps acks to statuses using every id in the event', function () {
    $ack = WahaWebhookParser::parse(wahaEnvelope('message.ack', [
        'id'    => 'true_111111111111111@lid_3EB0BBBB',
        'ack'   => 2,
        '_data' => ['MessageIDs' => ['3EB0BBBB', '3EB0CCCC']],
    ]));

    expect($ack)->toBeInstanceOf(MessageAck::class)
        ->and($ack->externalIds)->toBe(['3EB0BBBB', '3EB0CCCC'])
        ->and($ack->status())->toBe('delivered');
});

it('falls back to the bare id of the ack payload', function () {
    $ack = WahaWebhookParser::parse(wahaEnvelope('message.ack', [
        'id'  => 'true_111111111111111@lid_3EB0BBBB',
        'ack' => 1,
    ]));

    expect($ack->externalIds)->toBe(['3EB0BBBB'])
        ->and($ack->status())->toBe('sent');
});

it('reads a batched read receipt, which lists many messages in one event', function () {
    $ack = WahaWebhookParser::parse(wahaEnvelope('message.ack', [
        'id'      => 'true_111111111111111@lid_3EB0AAAA',
        'ack'     => 3,
        'ackName' => 'READ',
        '_data'   => ['MessageIDs' => ['3EB0AAAA', '3EB0BBBB', '3EB0CCCC'], 'Type' => 'read'],
    ]));

    expect($ack->externalIds)->toHaveCount(3)
        ->and($ack->status())->toBe('read');
});

it('parses an edit using the id of the original message and the new text', function () {
    $edit = WahaWebhookParser::parse(wahaEnvelope('message.edited', [
        'id'              => 'false_111111111111111@lid_2A9C3F13',
        'body'            => 'Texto corrigido',
        'editedMessageId' => '2A93952B',
    ]));

    expect($edit)->toBeInstanceOf(MessageEdited::class)
        ->and($edit->externalId)->toBe('2A93952B')
        ->and($edit->body)->toBe('Texto corrigido');
});

it('parses a deletion using revokedMessageId, not the id of the protocol message', function () {
    $revoked = WahaWebhookParser::parse(wahaEnvelope('message.revoked', [
        'after'            => ['id' => 'false_111111111111111@lid_2A769EA5'],
        'before'           => null,
        'revokedMessageId' => '2A7EDA0A',
    ]));

    expect($revoked)->toBeInstanceOf(MessageRevoked::class)
        ->and($revoked->externalId)->toBe('2A7EDA0A');
});

it('ignores edits and deletions that do not say which message they refer to', function () {
    expect(WahaWebhookParser::parse(wahaEnvelope('message.edited', ['body' => 'x'])))->toBeNull()
        ->and(WahaWebhookParser::parse(wahaEnvelope('message.revoked', ['after' => []])))->toBeNull();
});

it('reads the connected number from the session status', function () {
    $status = WahaWebhookParser::parse(wahaEnvelope('session.status', ['name' => 'teste', 'status' => 'working']));

    expect($status)->toBeInstanceOf(SessionStatus::class)
        ->and($status->status)->toBe('WORKING')
        ->and($status->phone)->toBe('551100000000');
});

it('returns null for events it does not handle', function () {
    expect(WahaWebhookParser::parse(wahaEnvelope('presence.update', ['id' => 'x'])))->toBeNull();
});

it('parses a typing indicator of a one-to-one chat', function () {
    $event = WahaWebhookParser::parse([
        'event'   => 'presence.update',
        'session' => 'teste',
        'payload' => ['id' => '556195684042@s.whatsapp.net', 'presences' => [['participant' => '556195684042@c.us', 'lastKnownPresence' => 'typing', 'lastSeen' => null]]],
    ]);

    expect($event)->toBeInstanceOf(PresenceChanged::class)
        ->and($event->chatId)->toBe('556195684042@c.us')
        ->and($event->state)->toBe('typing');
});

it('ignores presence of groups and unknown states', function (string $chat, string $state) {
    expect(WahaWebhookParser::parse([
        'event'   => 'presence.update',
        'session' => 'teste',
        'payload' => ['id' => $chat, 'presences' => [['lastKnownPresence' => $state]]],
    ]))->toBeNull();
})->with([
    'group'   => ['12036300000@g.us', 'typing'],
    'unknown' => ['556195684042@c.us', 'dancing'],
    'empty'   => ['556195684042@c.us', ''],
]);
