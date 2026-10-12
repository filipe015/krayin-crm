<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Webkul\WhatsApp\Channels\Cloud\CloudApiException;
use Webkul\WhatsApp\Channels\Cloud\CloudClient;
use Webkul\WhatsApp\Channels\Cloud\CloudSignature;
use Webkul\WhatsApp\Channels\Cloud\CloudWebhookParser;
use Webkul\WhatsApp\Channels\Events\InboundMessage;
use Webkul\WhatsApp\Channels\Events\MessageAck;
use Webkul\WhatsApp\Http\Controllers\CloudTemplateController;
use Webkul\WhatsApp\Support\ConversationWindow;
use Webkul\WhatsApp\Support\OutboundMedia;
use Webkul\WhatsApp\Support\VoiceTranscoder;

function cloudClient(Factory $http): CloudClient
{
    return new CloudClient($http, 'token-123456789012345678', '1100', '2200', 'v23.0');
}

function cloudEnvelope(array $value): array
{
    return ['object' => 'whatsapp_business_account', 'entry' => [['id' => '2200', 'changes' => [['field' => 'messages', 'value' => $value]]]]];
}

// ---------------------------------------------------------------------------- signature

it('accepts a body signed with the app secret and rejects anything else', function () {
    $body = '{"object":"whatsapp_business_account"}';
    $secret = 'a-long-enough-app-secret';
    $signature = 'sha256='.hash_hmac('sha256', $body, $secret);

    expect(CloudSignature::isValid($body, $signature, $secret))->toBeTrue()
        ->and(CloudSignature::isValid($body.' ', $signature, $secret))->toBeFalse()
        ->and(CloudSignature::isValid($body, $signature, 'another-secret-value-123'))->toBeFalse()
        ->and(CloudSignature::isValid($body, null, $secret))->toBeFalse()
        ->and(CloudSignature::isValid($body, $signature, 'short'))->toBeFalse();
});

// ---------------------------------------------------------------------------- parser

it('parses an incoming text with the contact name, as the same event WAHA produces', function () {
    $events = CloudWebhookParser::parse(cloudEnvelope([
        'metadata' => ['display_phone_number' => '556199990000', 'phone_number_id' => '1100'],
        'contacts' => [['profile' => ['name' => 'Maria'], 'wa_id' => '556195684042']],
        'messages' => [['from' => '556195684042', 'id' => 'wamid.AAA', 'timestamp' => '1760000000', 'type' => 'text', 'text' => ['body' => 'Olá!']]],
    ]));

    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(InboundMessage::class)
        ->and($events[0]->session)->toBe('1100')
        ->and($events[0]->channel)->toBe('cloud')
        ->and($events[0]->chatId)->toBe('556195684042@c.us')
        ->and($events[0]->phone)->toBe('556195684042')
        ->and($events[0]->contactName)->toBe('Maria')
        ->and($events[0]->externalId)->toBe('wamid.AAA')
        ->and($events[0]->type)->toBe('text')
        ->and($events[0]->body)->toBe('Olá!')
        ->and($events[0]->fromMe)->toBeFalse()
        ->and($events[0]->sentAt->getTimestamp())->toBe(1760000000);
});

it('parses media by id, keeping caption, mime and document name', function () {
    $events = CloudWebhookParser::parse(cloudEnvelope([
        'metadata' => ['phone_number_id' => '1100'],
        'messages' => [
            ['from' => '5561', 'id' => 'w1', 'timestamp' => '1', 'type' => 'image', 'image' => ['id' => 'M1', 'mime_type' => 'image/jpeg', 'caption' => 'foto']],
            ['from' => '5561', 'id' => 'w2', 'timestamp' => '1', 'type' => 'audio', 'audio' => ['id' => 'M2', 'mime_type' => 'audio/ogg; codecs=opus', 'voice' => true]],
            ['from' => '5561', 'id' => 'w3', 'timestamp' => '1', 'type' => 'document', 'document' => ['id' => 'M3', 'mime_type' => 'application/pdf', 'filename' => 'contrato.pdf']],
        ],
    ]));

    expect($events[0]->type)->toBe('image')
        ->and($events[0]->mediaPath)->toBe('cloud:M1')
        ->and($events[0]->body)->toBe('foto')
        ->and($events[1]->type)->toBe('audio')
        ->and($events[1]->mediaMime)->toBe('audio/ogg')
        ->and($events[2]->type)->toBe('document')
        ->and($events[2]->mediaFilename)->toBe('contrato.pdf');
});

it('turns buttons, list answers, locations and contacts into readable text', function () {
    $events = CloudWebhookParser::parse(cloudEnvelope([
        'metadata' => ['phone_number_id' => '1100'],
        'messages' => [
            ['from' => '5561', 'id' => 'a', 'timestamp' => '1', 'type' => 'button', 'button' => ['text' => 'Quero saber mais', 'payload' => 'x']],
            ['from' => '5561', 'id' => 'b', 'timestamp' => '1', 'type' => 'interactive', 'interactive' => ['type' => 'list_reply', 'list_reply' => ['id' => '1', 'title' => 'Plano Pro']]],
            ['from' => '5561', 'id' => 'c', 'timestamp' => '1', 'type' => 'location', 'location' => ['latitude' => -15.7, 'longitude' => -47.9, 'name' => 'Escritório']],
            ['from' => '5561', 'id' => 'd', 'timestamp' => '1', 'type' => 'contacts', 'contacts' => [['name' => ['formatted_name' => 'João'], 'phones' => [['phone' => '+55 61 99999-0000']]]]],
        ],
    ]));

    expect($events[0]->body)->toBe('Quero saber mais')
        ->and($events[1]->body)->toBe('Plano Pro')
        ->and($events[2]->type)->toBe('location')
        ->and($events[2]->body)->toContain('Escritório')
        ->and($events[3]->type)->toBe('contact')
        ->and($events[3]->body)->toBe('João +55 61 99999-0000');
});

it('ignores reactions and system notices', function () {
    $events = CloudWebhookParser::parse(cloudEnvelope([
        'metadata' => ['phone_number_id' => '1100'],
        'messages' => [
            ['from' => '5561', 'id' => 'r', 'timestamp' => '1', 'type' => 'reaction', 'reaction' => ['emoji' => '👍', 'message_id' => 'x']],
            ['from' => '5561', 'id' => 's', 'timestamp' => '1', 'type' => 'system', 'system' => ['body' => 'changed number']],
        ],
    ]));

    expect($events)->toBe([]);
});

it('maps delivery statuses to acks, with the reason of a failure', function () {
    $events = CloudWebhookParser::parse(cloudEnvelope([
        'metadata' => ['phone_number_id' => '1100'],
        'statuses' => [
            ['id' => 'w1', 'status' => 'sent', 'timestamp' => '1', 'recipient_id' => '5561'],
            ['id' => 'w1', 'status' => 'delivered', 'timestamp' => '2', 'recipient_id' => '5561'],
            ['id' => 'w1', 'status' => 'read', 'timestamp' => '3', 'recipient_id' => '5561'],
            ['id' => 'w2', 'status' => 'failed', 'timestamp' => '4', 'recipient_id' => '5561', 'errors' => [['code' => 131026, 'title' => 'Message undeliverable', 'error_data' => ['details' => 'Number is not on WhatsApp']]]],
            ['id' => 'w3', 'status' => 'deleted', 'timestamp' => '5'],
        ],
    ]));

    expect($events)->toHaveCount(4)
        ->and($events[0])->toBeInstanceOf(MessageAck::class)
        ->and(array_map(fn ($event) => $event->status(), $events))->toBe(['sent', 'delivered', 'read', 'failed'])
        ->and($events[3]->error)->toBe('Number is not on WhatsApp')
        ->and($events[0]->channel)->toBe('cloud');
});

it('ignores other webhook fields and payloads without a phone number', function () {
    expect(CloudWebhookParser::parse(['entry' => [['changes' => [['field' => 'message_template_status_update', 'value' => ['event' => 'APPROVED']]]]]]))->toBe([])
        ->and(CloudWebhookParser::parse(cloudEnvelope(['messages' => [['from' => '5561', 'id' => 'x', 'type' => 'text', 'text' => ['body' => 'oi']]]])))->toBe([])
        ->and(CloudWebhookParser::parse([]))->toBe([]);
});

// ---------------------------------------------------------------------------- client

it('sends text through the number with the bearer token', function () {
    $http = new Factory;
    $http->fake(['*' => Factory::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    $response = cloudClient($http)->sendText('556195684042', 'Olá');

    $http->assertSent(fn (Request $request) => $request->url() === 'https://graph.facebook.com/v23.0/1100/messages'
        && $request->hasHeader('Authorization', 'Bearer token-123456789012345678')
        && $request->data() === [
            'messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => '556195684042',
            'type' => 'text', 'text' => ['preview_url' => false, 'body' => 'Olá'],
        ]);

    expect(CloudClient::messageId($response))->toBe('wamid.OUT');
});

it('sends a template with body parameters', function () {
    $http = new Factory;
    $http->fake(['*' => Factory::response(['messages' => [['id' => 'wamid.T']]], 200)]);

    cloudClient($http)->sendTemplate('5561', 'lembrete', 'pt_BR', [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Ana']]]]);

    $http->assertSent(fn (Request $request) => $request->data()['type'] === 'template'
        && $request->data()['template']['name'] === 'lembrete'
        && $request->data()['template']['language'] === ['code' => 'pt_BR']
        && $request->data()['template']['components'][0]['parameters'][0]['text'] === 'Ana');
});

it('turns a Graph error into an exception that knows about the closed window', function () {
    $http = new Factory;
    $http->fake(['*' => Factory::response(['error' => ['message' => 'Re-engagement message', 'code' => 131047]], 400)]);

    try {
        cloudClient($http)->sendText('5561', 'oi');
    } catch (CloudApiException $exception) {
        expect($exception->isWindowClosed())->toBeTrue()
            ->and($exception->status)->toBe(400)
            ->and($exception->outcomeUnknown)->toBeFalse()
            ->and($exception->getMessage())->toContain('131047');

        return;
    }

    $this->fail('A CloudApiException was expected.');
});

it('does not treat a timeout as a failure', function () {
    $http = new Factory;
    $http->fake(fn () => throw new ConnectionException('cURL error 28'));

    try {
        cloudClient($http)->sendText('5561', 'oi');
    } catch (CloudApiException $exception) {
        expect($exception->outcomeUnknown)->toBeTrue();

        return;
    }

    $this->fail('A CloudApiException was expected.');
});

it('lists the templates of the business account and resolves a media id', function () {
    $http = new Factory;
    $http->fake([
        'graph.facebook.com/v23.0/2200/message_templates*' => Factory::response(['data' => [['name' => 'a', 'status' => 'APPROVED']]]),
        'graph.facebook.com/v23.0/M1'                       => Factory::response(['url' => 'https://lookaside.fbsbx.com/x', 'mime_type' => 'image/jpeg']),
    ]);

    $client = cloudClient($http);

    expect($client->templates())->toHaveCount(1)
        ->and($client->mediaInfo('M1'))->toBe(['url' => 'https://lookaside.fbsbx.com/x', 'mime_type' => 'image/jpeg']);
});

// ---------------------------------------------------------------------------- templates, window, media rules

it('describes a template: variables, supported or not', function () {
    $simple = CloudTemplateController::describe([
        'name' => 'lembrete', 'language' => 'pt_BR', 'category' => 'utility',
        'components' => [
            ['type' => 'BODY', 'text' => 'Olá {{1}}, sua consulta é {{2}}.'],
            ['type' => 'FOOTER', 'text' => 'Equipe'],
            ['type' => 'BUTTONS', 'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Ok']]],
        ],
    ]);

    $mediaHeader = CloudTemplateController::describe([
        'name' => 'promo', 'language' => 'pt_BR', 'category' => 'MARKETING',
        'components' => [['type' => 'HEADER', 'format' => 'IMAGE'], ['type' => 'BODY', 'text' => 'Oferta']],
    ]);

    $urlVariable = CloudTemplateController::describe([
        'name' => 'rastreio', 'language' => 'pt_BR', 'category' => 'UTILITY',
        'components' => [['type' => 'BODY', 'text' => 'Seu pedido'], ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'url' => 'https://x.com/{{1}}']]]],
    ]);

    expect($simple['variables'])->toBe(2)
        ->and($simple['supported'])->toBeTrue()
        ->and($simple['category'])->toBe('UTILITY')
        ->and($mediaHeader['supported'])->toBeFalse()
        ->and($urlVariable['supported'])->toBeFalse();
});

it('fills the template body', function () {
    expect(CloudTemplateController::render('Olá {{1}}, às {{2}}. {{3}}', ['Ana', '15h']))->toBe('Olá Ana, às 15h. {{3}}');
});

it('opens the service window for 24 hours after the last customer message', function () {
    $now = Carbon::parse('2026-10-14 12:00:00');

    expect(ConversationWindow::isOpen(null, $now))->toBeFalse()
        ->and(ConversationWindow::isOpen($now->copy()->subHours(23), $now))->toBeTrue()
        ->and(ConversationWindow::isOpen($now->copy()->subHours(25), $now))->toBeFalse();
});

it('is stricter than WAHA about the files the official channel takes', function (string $mime, bool $accepted) {
    $media = OutboundMedia::classify($mime);

    expect($media !== null && OutboundMedia::cloudSupports($media))->toBe($accepted);
})->with([
    'jpeg'      => ['image/jpeg', true],
    'webp'      => ['image/webp', false],
    'mp3'       => ['audio/mpeg', true],
    'wav'       => ['audio/wav', false],
    'webm'      => ['audio/webm', false],
    'mp4 video' => ['video/mp4', true],
    'webm video' => ['video/webm', false],
    'pdf'       => ['application/pdf', true],
    'zip'       => ['application/zip', false],
]);

// ---------------------------------------------------------------------------- voice notes

function fakeFfmpeg(string $body): string
{
    $path = tempnam(sys_get_temp_dir(), 'fake-ffmpeg-');
    file_put_contents($path, "#!/bin/sh\n".$body."\n");
    chmod($path, 0755);

    return $path;
}

it('converts a recording through ffmpeg and returns the converted file', function () {
    // The fake writes to the last argument, which is where the real command puts its output.
    $ffmpeg = fakeFfmpeg('for last; do :; done; printf OggS-converted > "$last"');
    $source = tempnam(sys_get_temp_dir(), 'rec-');
    file_put_contents($source, 'webm-bytes');

    expect(VoiceTranscoder::available($ffmpeg))->toBeTrue()
        ->and(VoiceTranscoder::toOggOpus($source, $ffmpeg))->toBe('OggS-converted');

    unlink($ffmpeg);
    unlink($source);
});

it('fails clearly when ffmpeg errors out or produces nothing', function () {
    $ffmpeg = fakeFfmpeg('echo "boom" >&2; exit 1');
    $source = tempnam(sys_get_temp_dir(), 'rec-');

    expect(fn () => VoiceTranscoder::toOggOpus($source, $ffmpeg))->toThrow(RuntimeException::class, 'boom');

    unlink($ffmpeg);
    unlink($source);
});

it('reports ffmpeg as unavailable when the configured binary does not exist', function () {
    expect(VoiceTranscoder::available('/nonexistent/ffmpeg'))->toBeFalse();
});
