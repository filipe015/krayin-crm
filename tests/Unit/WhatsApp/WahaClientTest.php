<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Webkul\WhatsApp\Channels\Waha\SendTarget;
use Webkul\WhatsApp\Channels\Waha\WahaClient;
use Webkul\WhatsApp\Channels\Waha\WahaException;
use Webkul\WhatsApp\Models\Conversation;

function wahaClient(Factory $http, ?string $url = 'https://waha.test/', ?string $key = 'secret-key'): WahaClient
{
    return new WahaClient($http, $url, $key);
}

it('posts the text to sendText with the api key header and returns the response', function () {
    $http = new Factory;
    $http->fake(['*' => Factory::response(['id' => 'true_556195684042@c.us_3EB0AAAA'], 201)]);

    $response = wahaClient($http)->sendText('teste', '556195684042@c.us', 'olá');

    $http->assertSent(fn (Request $request) => $request->url() === 'https://waha.test/api/sendText'
        && $request->method() === 'POST'
        && $request->hasHeader('X-Api-Key', 'secret-key')
        && $request->data() === ['session' => 'teste', 'chatId' => '556195684042@c.us', 'text' => 'olá']);

    expect($response['id'])->toBe('true_556195684042@c.us_3EB0AAAA');
});

it('turns a WAHA error into an exception carrying the reason and status', function () {
    $http = new Factory;
    $http->fake(['*' => Factory::response(['statusCode' => 500, 'exception' => ['message' => '2 UNKNOWN: no LID found for 5561@s.whatsapp.net']], 500)]);

    try {
        wahaClient($http)->sendText('teste', '5561@c.us', 'oi');
    } catch (WahaException $exception) {
        expect($exception->getMessage())->toBe('WAHA answered 500: 2 UNKNOWN: no LID found for 5561@s.whatsapp.net')
            ->and($exception->status)->toBe(500)
            ->and($exception->outcomeUnknown)->toBeFalse();

        return;
    }

    $this->fail('A WahaException was expected.');
});

it('does not treat a timeout as a failure, since the message may have been sent', function () {
    $http = new Factory;
    $http->fake(fn () => throw new ConnectionException('cURL error 28'));

    try {
        wahaClient($http)->sendText('teste', '5561@c.us', 'oi');
    } catch (WahaException $exception) {
        expect($exception->outcomeUnknown)->toBeTrue()
            ->and($exception->getMessage())->not->toContain('cURL');

        return;
    }

    $this->fail('A WahaException was expected.');
});

it('refuses to send when WAHA is not configured', function (?string $url, ?string $key) {
    expect(fn () => wahaClient(new Factory, $url, $key)->sendText('teste', '5561@c.us', 'oi'))
        ->toThrow(WahaException::class, 'must be configured');
})->with([
    'no url' => [null, 'key'],
    'no key' => ['https://waha.test', ''],
]);

it('extracts the bare message id from the shapes WAHA may answer with', function (array $response, ?string $expected) {
    expect(WahaClient::messageId($response))->toBe($expected);
})->with([
    'compound id'        => [['id' => 'true_556195684042@c.us_3EB0AAAA'], '3EB0AAAA'],
    'bare id'            => [['id' => '3EB0AAAA'], '3EB0AAAA'],
    'serialized id'      => [['id' => ['_serialized' => 'true_5561@c.us_3EB0BBBB']], '3EB0BBBB'],
    'engine data'        => [['_data' => ['Info' => ['ID' => '3EB0CCCC']]], '3EB0CCCC'],
    'key'                => [['key' => ['id' => '3EB0DDDD']], '3EB0DDDD'],
    'nothing recognised' => [['status' => 'ok'], null],
]);

it('sends to the LID first and falls back to the phone', function (array $attributes, array $expected) {
    expect(SendTarget::for(new Conversation($attributes)))->toBe($expected);
})->with([
    'lid and phone' => [['contact_lid' => '111@lid', 'contact_phone' => '551188887777'], ['111@lid', '551188887777@c.us']],
    'only phone'    => [['contact_phone' => '551188887777'], ['551188887777@c.us']],
    'only lid'      => [['contact_lid' => '111@lid'], ['111@lid']],
    'nothing known' => [[], []],
]);

it('lists sessions and controls them through the session endpoints', function () {
    $http = new Factory;
    $http->fake([
        'waha.test/api/sessions?all=true' => Factory::response([['name' => 'default', 'status' => 'WORKING', 'me' => ['id' => '556195684042@c.us']]]),
        '*'                               => Factory::response([], 201),
    ]);

    $client = wahaClient($http);

    expect($client->sessions()[0]['status'])->toBe('WORKING');

    $client->controlSession('default', 'restart');

    $http->assertSent(fn (Request $request) => $request->url() === 'https://waha.test/api/sessions/default/restart'
        && $request->method() === 'POST'
        && $request->hasHeader('X-Api-Key', 'secret-key'));
});

it('fetches the pairing QR and the pairing code', function () {
    $http = new Factory;
    $http->fake([
        'waha.test/api/default/auth/qr'           => Factory::response(['mimetype' => 'image/png', 'data' => 'aGk=']),
        'waha.test/api/default/auth/request-code' => Factory::response(['code' => 'ABCD-1234']),
    ]);

    $client = wahaClient($http);

    expect($client->qrCode('default'))->toBe(['mimetype' => 'image/png', 'data' => 'aGk='])
        ->and($client->requestPairingCode('default', '556195684042'))->toBe('ABCD-1234');

    $http->assertSent(fn (Request $request) => str_ends_with($request->url(), '/auth/request-code')
        && $request->data() === ['phoneNumber' => '556195684042']);
});

it('refuses a QR response without image data', function () {
    $http = new Factory;
    $http->fake(['*' => Factory::response(['mimetype' => 'image/png'])]);

    expect(fn () => wahaClient($http)->qrCode('default'))->toThrow(WahaException::class);
});

it('sends media inline as base64 to the endpoint of its kind', function (string $kind, string $path, bool $converts, bool $captions) {
    $http = new Factory;
    $http->fake(['*' => Factory::response(['id' => 'true_5561@c.us_AAA'], 201)]);

    wahaClient($http)->sendMedia('teste', '5561@c.us', $kind, 'image/png', 'a.png', base64_encode('x'), 'legenda');

    $http->assertSent(function (Request $request) use ($path, $converts, $captions) {
        $data = $request->data();

        return $request->url() === 'https://waha.test'.$path
            && $request->hasHeader('X-Api-Key', 'secret-key')
            && $data['session'] === 'teste'
            && $data['chatId'] === '5561@c.us'
            && $data['file'] === ['mimetype' => 'image/png', 'filename' => 'a.png', 'data' => base64_encode('x')]
            && (($data['convert'] ?? false) === $converts)
            && (array_key_exists('caption', $data) === $captions);
    });
})->with([
    'image' => ['image', '/api/sendImage', false, true],
    'file'  => ['file', '/api/sendFile', false, true],
    'voice' => ['voice', '/api/sendVoice', true, false],
    'video' => ['video', '/api/sendVideo', true, true],
]);

it('rejects an unknown media kind before calling WAHA', function () {
    $http = new Factory;
    $http->fake();

    expect(fn () => wahaClient($http)->sendMedia('teste', '5561@c.us', 'sticker', 'image/webp', 'a.webp', 'eA=='))
        ->toThrow(WahaException::class);

    $http->assertNothingSent();
});
