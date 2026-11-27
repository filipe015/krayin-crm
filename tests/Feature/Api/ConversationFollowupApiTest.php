<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Bus;
use Webkul\WhatsApp\Api\Models\ApiKey;
use Webkul\WhatsApp\Jobs\SendWhatsAppMessage;
use Webkul\WhatsApp\Models\Conversation;
use Webkul\WhatsApp\Models\Message;
use Webkul\WhatsApp\Models\Session;

uses(DatabaseTransactions::class);

/**
 * Follow-up API on both channels: official (Cloud, 24h window) and unofficial (WAHA, no window).
 * Needs a migrated and seeded database.
 */
function followupHeaders(): array
{
    [, $token] = ApiKey::issue('followup-test', ['read', 'write'], null);

    return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
}

function followupSession(string $channel, string $status = 'WORKING'): Session
{
    return Session::query()->forceCreate([
        'channel' => $channel,
        'name' => 'Teste '.$channel,
        'external_id' => 'ext-'.$channel.'-'.uniqid(),
        'status' => $status,
    ]);
}

function followupConversation(Session $session, array $overrides = []): Conversation
{
    return Conversation::query()->forceCreate(array_merge([
        'canal_origem' => 'whatsapp',
        'session_id' => $session->id,
        'chat_id' => '5561999990000@c.us',
        'contact_phone' => '5561999990000',
        'contact_name' => 'Cliente Teste',
        'status' => 'open',
        'last_message_at' => now()->subDays(3),
        'last_inbound_at' => now()->subDays(3),
    ], $overrides));
}

function followupSent(Conversation $conversation, int $hoursAgo): void
{
    $message = Message::query()->forceCreate([
        'conversation_id' => $conversation->id,
        'direction' => 'outbound',
        'status' => 'sent',
        'source' => 'followup',
        'content' => 'Oi, tudo bem?',
        'message_type' => 'text',
    ]);

    $message->forceFill(['created_at' => now()->subHours($hoursAgo)])->save();
}

beforeEach(function () {
    test()->withoutMiddleware(ThrottleRequests::class);
});

it('keeps listing only official conversations by default', function () {
    $cloud = followupConversation(followupSession('cloud'));
    $waha = followupConversation(followupSession('waha'), ['chat_id' => '5561988880000@c.us']);

    $ids = collect(test()->getJson('/api/v1/conversations?include_unlinked=1', followupHeaders())->assertOk()->json('data'))->pluck('id');

    expect($ids)->toContain($cloud->id)->not->toContain($waha->id);
});

it('lists WAHA conversations, always with an open window', function () {
    $waha = followupConversation(followupSession('waha'));

    $item = collect(test()->getJson('/api/v1/conversations?channel=waha&include_unlinked=1&window=open', followupHeaders())->assertOk()->json('data'))
        ->firstWhere('id', $waha->id);

    expect($item)->not->toBeNull()
        ->and($item['channel'])->toBe('waha')
        ->and($item['window']['open'])->toBeTrue()
        ->and($item['window']['expires_at'])->toBeNull()
        ->and($item['followup']['allowed'])->toBeTrue();
});

it('hides unlinked conversations unless asked and never lists groups', function () {
    $session = followupSession('waha');
    $direct = followupConversation($session);
    $group = followupConversation($session, ['chat_id' => '120363000000000000@g.us', 'contact_phone' => null]);

    $without = collect(test()->getJson('/api/v1/conversations?channel=waha', followupHeaders())->json('data'))->pluck('id');
    $with = collect(test()->getJson('/api/v1/conversations?channel=waha&include_unlinked=1', followupHeaders())->json('data'))->pluck('id');

    expect($without)->not->toContain($direct->id)
        ->and($with)->toContain($direct->id)->not->toContain($group->id);
});

it('sends free text on a WAHA conversation even with the 24h window "closed"', function () {
    Bus::fake();
    $waha = followupConversation(followupSession('waha'));

    test()->postJson("/api/v1/conversations/{$waha->id}/send-text", ['text' => 'Oi!', 'followup' => true], followupHeaders())
        ->assertCreated()
        ->assertJsonPath('data.followup', true);

    Bus::assertDispatched(SendWhatsAppMessage::class);
});

it('still blocks free text outside the window on the official channel', function () {
    Bus::fake();
    $cloud = followupConversation(followupSession('cloud'));

    test()->postJson("/api/v1/conversations/{$cloud->id}/send-text", ['text' => 'Oi!'], followupHeaders())
        ->assertStatus(409)
        ->assertJsonPath('error', 'window_closed');

    Bus::assertNotDispatched(SendWhatsAppMessage::class);
});

it('accepts a WAHA conversation that only has a LID', function () {
    Bus::fake();
    $waha = followupConversation(followupSession('waha'), ['contact_phone' => null, 'contact_lid' => '123456789@lid']);

    test()->postJson("/api/v1/conversations/{$waha->id}/send-text", ['text' => 'Oi!'], followupHeaders())->assertCreated();
});

it('refuses to send to a group', function () {
    Bus::fake();
    $group = followupConversation(followupSession('waha'), ['chat_id' => '120363000000000000@g.us']);

    test()->postJson("/api/v1/conversations/{$group->id}/send-text", ['text' => 'Oi!'], followupHeaders())
        ->assertStatus(422)
        ->assertJsonPath('error', 'not_direct_chat');

    Bus::assertNotDispatched(SendWhatsAppMessage::class);
});

it('keeps templates exclusive to the official channel', function () {
    $waha = followupConversation(followupSession('waha'));

    test()->postJson("/api/v1/conversations/{$waha->id}/send-template", ['name' => 'x', 'language' => 'pt_BR'], followupHeaders())
        ->assertStatus(422)
        ->assertJsonPath('error', 'not_cloud');
});

it('applies the WAHA follow-up limit and gap from the config', function () {
    Bus::fake();
    config(['whatsapp.api_followup.waha' => ['max' => 2, 'gap_hours' => 48, 'daily_cap' => 0]]);
    $waha = followupConversation(followupSession('waha'));

    followupSent($waha, 30);

    test()->postJson("/api/v1/conversations/{$waha->id}/send-text", ['text' => 'Oi', 'followup' => true], followupHeaders())
        ->assertStatus(409)
        ->assertJsonPath('error', 'followup_gap');

    $waha->messages()->delete();
    followupSent($waha, 100);
    followupSent($waha, 60);

    test()->postJson("/api/v1/conversations/{$waha->id}/send-text", ['text' => 'Oi', 'followup' => true], followupHeaders())
        ->assertStatus(409)
        ->assertJsonPath('error', 'followup_limit');
});

it('caps the daily follow-ups per WAHA session', function () {
    Bus::fake();
    config(['whatsapp.api_followup.waha' => ['max' => 3, 'gap_hours' => 48, 'daily_cap' => 1]]);
    $session = followupSession('waha');
    followupSent(followupConversation($session, ['chat_id' => '5561911110000@c.us']), 2);
    $other = followupConversation($session);

    test()->postJson("/api/v1/conversations/{$other->id}/send-text", ['text' => 'Oi', 'followup' => true], followupHeaders())
        ->assertStatus(409)
        ->assertJsonPath('error', 'daily_limit');
});

it('keeps the official limits (2 follow-ups, 24h) by default', function () {
    config(['whatsapp.api_followup.cloud' => ['max' => 2, 'gap_hours' => 24]]);
    $cloud = followupConversation(followupSession('cloud'), ['last_inbound_at' => now()->subHour()]);
    followupSent($cloud, 80);
    followupSent($cloud, 50);

    $item = collect(test()->getJson('/api/v1/conversations?include_unlinked=1', followupHeaders())->json('data'))->firstWhere('id', $cloud->id);

    expect($item['followup']['allowed'])->toBeFalse()
        ->and($item['followup']['reason'])->toBe('followup_limit');
});
