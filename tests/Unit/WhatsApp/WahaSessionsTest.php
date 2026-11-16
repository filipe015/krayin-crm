<?php

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Webkul\WhatsApp\Channels\Waha\WahaClient;
use Webkul\WhatsApp\Channels\Waha\WahaException;
use Webkul\WhatsApp\Support\WahaSessions;

it('reads WAHA_SESSIONS as a trimmed list, empty meaning every session', function () {
    expect(WahaSessions::allowed(''))->toBeNull()
        ->and(WahaSessions::allowed(' , '))->toBeNull()
        ->and(WahaSessions::allowed('crm'))->toBe(['crm'])
        ->and(WahaSessions::allowed(' crm , agencia ,crm,'))->toBe(['crm', 'agencia'])
        ->and(WahaSessions::allowed(['a', ' b ']))->toBe(['a', 'b']);
});

it('lists every WAHA session when no list is configured', function () {
    $http = new Factory;
    $http->fake(['*' => Factory::response([['name' => 'crm'], ['name' => 'agencia'], ['name' => 'assertiva']])]);

    $client = new WahaClient($http, 'https://waha.test', 'k');

    expect(array_column($client->sessions(), 'name'))->toBe(['crm', 'agencia', 'assertiva'])
        ->and($client->allows('qualquer'))->toBeTrue();
});

it('lists and controls only the allowed sessions', function () {
    $http = new Factory;
    $http->fake([
        'waha.test/api/sessions?*' => Factory::response([['name' => 'crm'], ['name' => 'agencia'], ['name' => 'assertiva']]),
        '*'                       => Factory::response(['ok' => true]),
    ]);

    $client = new WahaClient($http, 'https://waha.test', 'k', 15, ['crm', 'assertiva']);

    expect(array_column($client->sessions(), 'name'))->toBe(['crm', 'assertiva']);

    $client->controlSession('crm', 'restart');

    $refused = [];

    foreach ([
        'control' => fn () => $client->controlSession('agencia', 'logout'),
        'qr'      => fn () => $client->qrCode('agencia'),
        'code'    => fn () => $client->requestPairingCode('agencia', '5511999999999'),
        'text'    => fn () => $client->sendText('agencia', '5511@c.us', 'oi'),
        'media'   => fn () => $client->sendMedia('agencia', '5511@c.us', 'image', 'image/png', 'a.png', 'AA=='),
        'typing'  => fn () => $client->setPresence('agencia', '5511@c.us', 'typing'),
        'watch'   => fn () => $client->subscribePresence('agencia', '5511@c.us'),
    ] as $name => $call) {
        try {
            $call();
        } catch (WahaException $exception) {
            $refused[$name] = $exception->status;
        }
    }

    expect($refused)->toBe(['control' => 403, 'qr' => 403, 'code' => 403, 'text' => 403, 'media' => 403, 'typing' => 403, 'watch' => 403]);

    // Nothing about the refused session ever reached WAHA.
    $http->assertNotSent(fn (Request $request) => str_contains($request->url(), 'agencia') || ($request->data()['session'] ?? null) === 'agencia');
    $http->assertSent(fn (Request $request) => $request->url() === 'https://waha.test/api/sessions/crm/restart');
});
