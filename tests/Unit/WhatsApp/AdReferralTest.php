<?php

use Webkul\WhatsApp\Channels\Cloud\CloudWebhookParser;
use Webkul\WhatsApp\Channels\Waha\WahaWebhookParser;
use Webkul\WhatsApp\Support\AdReferral;

/*
 * Ad payloads are hand-made: the real GOWS shape of a click-to-WhatsApp message was not
 * captured yet, so the extractor is checked against the variations it may plausibly take.
 */
function gowsAdMessage(array $message): array
{
    return [
        'id'        => 'false_5511988887777@c.us_3EB0AD01',
        'timestamp' => 1789936561,
        'from'      => '5511988887777@c.us',
        'fromMe'    => false,
        'source'    => 'app',
        'body'      => 'Olá! Quero saber mais sobre isso.',
        'hasMedia'  => false,
        '_data'     => [
            'Info' => [
                'Chat' => '5511988887777@s.whatsapp.net', 'IsGroup' => false, 'ID' => '3EB0AD01', 'PushName' => 'Cliente Teste', 'MediaType' => '',
            ],
            'Message' => $message,
        ],
    ];
}

it('reads the ad from extendedTextMessage.contextInfo.externalAdReply (camelCase)', function () {
    $ad = AdReferral::extract(gowsAdMessage([
        'extendedTextMessage' => [
            'text'        => 'Olá! Quero saber mais sobre isso.',
            'contextInfo' => [
                'externalAdReply' => [
                    'title'        => 'Promoção de verão',
                    'body'         => 'Fale com a gente',
                    'mediaType'    => 1,
                    'thumbnailURL' => 'https://scontent.example.test/t.jpg',
                    'sourceType'   => 'ad',
                    'sourceID'     => '120200000000000001',
                    'sourceURL'    => 'https://fb.me/abc123',
                    'ctwaClid'     => 'ARAkLmNoPq',
                ],
            ],
        ],
    ]));

    expect($ad)->toMatchArray([
        'source_id'     => '120200000000000001',
        'source_url'    => 'https://fb.me/abc123',
        'source_type'   => 'ad',
        'title'         => 'Promoção de verão',
        'body'          => 'Fale com a gente',
        'ctwa_clid'     => 'ARAkLmNoPq',
        'thumbnail_url' => 'https://scontent.example.test/t.jpg',
    ]);
});

it('reads PascalCase keys and an image message', function () {
    $ad = AdReferral::extract(gowsAdMessage([
        'ImageMessage' => [
            'Caption'     => 'foto',
            'ContextInfo' => [
                'ExternalAdReply' => [
                    'Title'     => 'Clínica X',
                    'SourceId'  => '9988',
                    'SourceUrl' => 'https://www.instagram.com/p/xyz',
                    'CtwaClid'  => 'clid-1',
                    'MediaURL'  => 'https://example.test/m.mp4',
                ],
            ],
        ],
    ]));

    expect($ad)->source_id->toBe('9988')
        ->and($ad['title'])->toBe('Clínica X')
        ->and($ad['source_url'])->toBe('https://www.instagram.com/p/xyz')
        ->and($ad['ctwa_clid'])->toBe('clid-1')
        ->and($ad['media_url'])->toBe('https://example.test/m.mp4');
});

it('reads a contextInfo next to a plain conversation and at the top of _data', function () {
    $nextToConversation = AdReferral::extract(gowsAdMessage([
        'conversation' => 'Oi',
        'messageContextInfo' => ['externalAdReply' => ['sourceId' => '1', 'title' => 'A']],
    ]));

    $topLevel = gowsAdMessage([]);
    $topLevel['_data']['contextInfo'] = ['externalAdReply' => ['source_id' => '2', 'ctwa_clid' => 'c2']];

    expect($nextToConversation)->source_id->toBe('1')->title->toBe('A')
        ->and(AdReferral::extract($topLevel))->source_id->toBe('2')->ctwa_clid->toBe('c2');
});

it('reads the Cloud API referral object', function () {
    $ad = AdReferral::extract([
        'from' => '5511988887777', 'id' => 'wamid.X', 'type' => 'text', 'text' => ['body' => 'Oi'],
        'referral' => [
            'source_url' => 'https://fb.me/xyz', 'source_id' => '777', 'source_type' => 'ad',
            'headline' => 'Anúncio Y', 'body' => 'Texto', 'media_type' => 'image', 'image_url' => 'https://example.test/i.jpg',
            'ctwa_clid' => 'ARclid',
        ],
    ]);

    expect($ad)->toMatchArray([
        'source_id' => '777', 'source_url' => 'https://fb.me/xyz', 'source_type' => 'ad', 'title' => 'Anúncio Y',
        'body' => 'Texto', 'ctwa_clid' => 'ARclid', 'media_url' => 'https://example.test/i.jpg',
    ]);
});

it('ignores messages without an ad and link previews without an ad id', function () {
    expect(AdReferral::extract(gowsAdMessage(['conversation' => 'Oi'])))->toBeNull()
        ->and(AdReferral::extract(gowsAdMessage(['extendedTextMessage' => ['text' => 'veja', 'contextInfo' => [
            'externalAdReply' => ['title' => 'Post', 'sourceUrl' => 'https://instagram.com/p/1'],
            'quotedMessage'   => ['conversation' => 'antes'],
        ]]])))->toBeNull()
        ->and(AdReferral::extract([]))->toBeNull();
});

it('accepts a numeric source type and trims empty values', function () {
    $ad = AdReferral::extract(['externalAdReply' => ['sourceID' => 123, 'sourceType' => 0, 'title' => '  ', 'ctwaClid' => '']]);

    expect($ad)->source_id->toBe('123')->source_type->toBe('0')->title->toBeNull()->ctwa_clid->toBeNull();
});

it('passes the ad of an incoming WAHA message to the event, never of one we sent', function () {
    $payload = gowsAdMessage(['extendedTextMessage' => ['text' => 'Oi', 'contextInfo' => ['externalAdReply' => ['sourceID' => '55', 'title' => 'T']]]]);

    $incoming = WahaWebhookParser::parse(['event' => 'message.any', 'session' => 'default', 'payload' => $payload]);
    $sent = WahaWebhookParser::parse(['event' => 'message.any', 'session' => 'default', 'payload' => ['fromMe' => true] + $payload]);

    expect($incoming->ad)->source_id->toBe('55')
        ->and($sent->ad)->toBeNull();
});

it('passes the referral of a Cloud API message to the event', function () {
    $events = CloudWebhookParser::parse(['object' => 'whatsapp_business_account', 'entry' => [[
        'id' => '1', 'changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => '123456'],
            'contacts' => [['wa_id' => '5511988887777', 'profile' => ['name' => 'Cliente']]],
            'messages' => [[
                'from' => '5511988887777', 'id' => 'wamid.AD', 'timestamp' => '1789936561', 'type' => 'text', 'text' => ['body' => 'Oi'],
                'referral' => ['source_id' => '888', 'source_url' => 'https://fb.me/q', 'source_type' => 'ad', 'headline' => 'H', 'ctwa_clid' => 'CL'],
            ]],
        ]]],
    ]]]);

    $message = collect($events)->first(fn ($event) => $event instanceof \Webkul\WhatsApp\Channels\Events\InboundMessage);

    expect($message?->ad)->source_id->toBe('888')->title->toBe('H')->ctwa_clid->toBe('CL');
});
