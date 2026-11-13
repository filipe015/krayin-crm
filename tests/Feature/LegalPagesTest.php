<?php

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use Webkul\WhatsApp\Support\MetaSignedRequest;

/**
 * Public pages for the Meta App Review: privacy, terms, data deletion and Meta's deletion callback.
 */
function metaSignedRequest(array $payload, string $secret): string
{
    $encodedPayload = MetaSignedRequest::base64UrlEncode(json_encode($payload));
    $signature = MetaSignedRequest::base64UrlEncode(hash_hmac('sha256', $encodedPayload, $secret, true));

    return $signature.'.'.$encodedPayload;
}

beforeEach(function () {
    config(['whatsapp.cloud.app_secret' => 'segredo-de-teste', 'app.url' => 'https://crm.exemplo.test']);
});

it('shows the legal pages without login, with the company data', function (string $path, string $title) {
    test()->get($path)
        ->assertOk()
        ->assertSee($title)
        ->assertSee('Teway CRM')
        ->assertSee('Teway Marketing')
        ->assertSee('42.534.224/0001-23')
        ->assertSee('comercial@telwaymarketing.com.br');
})->with([
    ['/privacidade', 'Política de Privacidade'],
    ['/termos', 'Termos de Uso'],
    ['/exclusao-de-dados', 'Exclusão de Dados'],
]);

it('takes the company data from the config', function () {
    config(['legal.trade_name' => 'Outra Empresa Ltda']);

    test()->get('/privacidade')->assertOk()->assertSee('Outra Empresa Ltda');
});

it('answers the Meta data deletion callback with a coherent url and code', function () {
    $signed = metaSignedRequest(['algorithm' => 'HMAC-SHA256', 'user_id' => '1234567890', 'issued_at' => time()], 'segredo-de-teste');

    $response = test()->post('/meta/data-deletion', ['signed_request' => $signed])->assertOk();

    $code = $response->json('confirmation_code');

    expect($code)->toBe(MetaSignedRequest::confirmationCode('1234567890', 'segredo-de-teste'))
        ->and($code)->toMatch('/^[A-F0-9]{16}$/')
        ->and($response->json('url'))->toBe('https://crm.exemplo.test/exclusao-de-dados/status/'.$code);

    // Same user, same code.
    expect(test()->post('/meta/data-deletion', ['signed_request' => $signed])->json('confirmation_code'))->toBe($code);
});

it('falls back to META_APP_SECRET when the WhatsApp Cloud secret is empty', function () {
    config(['whatsapp.cloud.app_secret' => null, 'legal.meta_app_secret' => 'outro-segredo']);

    $signed = metaSignedRequest(['algorithm' => 'HMAC-SHA256', 'user_id' => '42'], 'outro-segredo');

    test()->post('/meta/data-deletion', ['signed_request' => $signed])->assertOk()->assertJsonStructure(['url', 'confirmation_code']);
});

it('rejects an invalid signed_request with 400', function (?string $signed) {
    test()->post('/meta/data-deletion', array_filter(['signed_request' => $signed]))->assertStatus(400);
})->with([
    'wrong secret' => fn () => metaSignedRequest(['algorithm' => 'HMAC-SHA256', 'user_id' => '1'], 'segredo-errado'),
    'no user_id' => fn () => metaSignedRequest(['algorithm' => 'HMAC-SHA256'], 'segredo-de-teste'),
    'wrong algo' => fn () => metaSignedRequest(['algorithm' => 'none', 'user_id' => '1'], 'segredo-de-teste'),
    'garbage' => 'abc',
    'missing' => null,
]);

it('shows the status page of a deletion request', function () {
    test()->get('/exclusao-de-dados/status/ABCDEF0123456789')
        ->assertOk()
        ->assertSee('Solicitação recebida')
        ->assertSee('ABCDEF0123456789');
});

it('takes a deletion request from the public form and e-mails the company', function () {
    Mail::fake();

    test()->withoutMiddleware([ValidateCsrfToken::class, ThrottleRequests::class])
        ->post('/exclusao-de-dados', ['name' => 'Fulano de Tal', 'email' => 'fulano@example.test', 'phone' => '+55 61 90000-0000'])
        ->assertRedirect('/exclusao-de-dados')
        ->assertSessionHas('deletion_requested', true);
});

it('keeps working when the e-mail cannot be sent', function () {
    Mail::shouldReceive('raw')->andThrow(new RuntimeException('SMTP fora do ar'));

    test()->withoutMiddleware([ValidateCsrfToken::class, ThrottleRequests::class])
        ->post('/exclusao-de-dados', ['name' => 'Fulano', 'email' => 'fulano@example.test'])
        ->assertRedirect('/exclusao-de-dados')
        ->assertSessionHas('deletion_requested', true);
});
