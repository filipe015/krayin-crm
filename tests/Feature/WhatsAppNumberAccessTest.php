<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Webkul\User\Models\User;

uses(DatabaseTransactions::class);

/**
 * Access by WhatsApp number: a user restricted to some numbers only sees and answers the conversations of those.
 */
function numberSession(string $name): int
{
    return DB::table('whatsapp_sessions')->insertGetId(['name' => 'ZZ '.$name, 'channel' => 'waha', 'external_id' => 'zz-'.uniqid(), 'status' => 'WORKING', 'created_at' => now(), 'updated_at' => now()]);
}

function numberConversation(int $session): int
{
    return DB::table('whatsapp_conversations')->insertGetId([
        'lead_id' => null, 'session_id' => $session, 'contact_phone' => '55119'.random_int(10000000, 99999999), 'contact_name' => 'ZZ contato',
        'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function numberUser(array $sessionIds = [], bool $restricted = true): User
{
    $roleId = DB::table('roles')->insertGetId([
        'name' => 'ZZ numero '.uniqid(), 'description' => 'teste', 'permission_type' => 'custom',
        'permissions' => json_encode(['whatsapp', 'whatsapp.send', 'whatsapp.manage']),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $id = DB::table('users')->insertGetId([
        'name' => 'ZZ Numero', 'email' => 'zz-'.uniqid().'@example.test', 'password' => Hash::make('secret-123'),
        'status' => 1, 'role_id' => $roleId, 'view_permission' => 'global', 'whatsapp_restricted' => $restricted, 'created_at' => now(), 'updated_at' => now(),
    ]);

    foreach ($sessionIds as $sessionId) {
        DB::table('user_whatsapp_sessions')->insert(['user_id' => $id, 'session_id' => $sessionId]);
    }

    return User::query()->findOrFail($id);
}

it('shows only the conversations of the allowed numbers and refuses the others', function () {
    $mine = numberSession('meu');
    $other = numberSession('outro');
    $mineConversation = numberConversation($mine);
    $otherConversation = numberConversation($other);

    $ids = fn (User $user) => collect(test()->actingAs($user, 'user')->getJson(route('admin.whatsapp.inbox.conversations', ['tab' => 'all']))->assertOk()->json('data'))->pluck('id');

    $restricted = numberUser([$mine]);

    expect($ids($restricted))->toContain($mineConversation)->not->toContain($otherConversation);

    test()->actingAs($restricted, 'user')->getJson(route('admin.whatsapp.inbox.messages', ['id' => $otherConversation]))->assertNotFound();
    test()->actingAs($restricted, 'user')->getJson(route('admin.whatsapp.inbox.messages', ['id' => $mineConversation]))->assertOk();

    // Everybody else keeps seeing every number.
    expect($ids(numberUser([$mine], restricted: false)))->toContain($mineConversation, $otherConversation);

    // Restricted with no number at all: nothing.
    expect($ids(numberUser([])))->not->toContain($mineConversation)->not->toContain($otherConversation);
});

it('saves the numbers of a user on the user form', function () {
    $mine = numberSession('meu');
    $other = numberSession('outro');
    $target = numberUser([], restricted: false);

    $payload = fn (array $extra) => $extra + ['name' => 'ZZ Numero', 'email' => $target->email, 'role_id' => $target->role_id, 'view_permission' => 'global', 'status' => 1];

    test()->actingAs(getDefaultAdmin(), 'user')
        ->putJson(route('admin.settings.users.update', $target->id), $payload(['whatsapp_restricted' => 1]))
        ->assertUnprocessable();

    test()->putJson(route('admin.settings.users.update', $target->id), $payload(['whatsapp_restricted' => 1, 'whatsapp_sessions' => [$mine]]))->assertOk();

    expect($target->refresh()->load('whatsappSessions'))->whatsapp_restricted->toBeTrue()
        ->and($target->whatsappSessions->pluck('id')->all())->toBe([$mine]);

    test()->putJson(route('admin.settings.users.update', $target->id), $payload(['whatsapp_restricted' => 0, 'whatsapp_sessions' => [$other]]))->assertOk();

    expect($target->refresh()->load('whatsappSessions'))->whatsapp_restricted->toBeFalse()
        ->and($target->whatsappSessions)->toHaveCount(0);

    test()->putJson(route('admin.settings.users.update', $target->id), $payload(['whatsapp_restricted' => 1, 'whatsapp_sessions' => [999999]]))->assertUnprocessable();
});

it('renders the users screen with the WhatsApp access section', function () {
    numberSession('meu');

    test()->actingAs(getDefaultAdmin(), 'user')->get(route('admin.settings.users.index'))->assertOk()->assertSee('whatsapp_sessions[]', false)->assertSee('Acesso ao WhatsApp');
});
