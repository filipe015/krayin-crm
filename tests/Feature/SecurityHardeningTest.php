<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Webkul\User\Models\User;

uses(DatabaseTransactions::class);

/**
 * Security gaps closed in the first round: the Inbox follows each user's visibility, and the screens
 * anybody can reach (login, forgotten password) limit the attempts.
 */
function inboxSeller(string $visibility = 'individual'): User
{
    $roleId = DB::table('roles')->insertGetId([
        'name' => 'ZZ vendedor '.uniqid(), 'description' => 'teste', 'permission_type' => 'custom',
        'permissions' => json_encode(['leads', 'leads.view', 'whatsapp', 'whatsapp.send', 'whatsapp.manage']),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $id = DB::table('users')->insertGetId([
        'name' => 'ZZ Vendedor', 'email' => 'zz-'.uniqid().'@example.test', 'password' => Hash::make('secret-123'),
        'status' => 1, 'role_id' => $roleId, 'view_permission' => $visibility, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return User::query()->findOrFail($id);
}

function inboxConversation(?int $leadOwnerId, ?int $assignedTo = null, string $phone = '5511999990000'): int
{
    $session = DB::table('whatsapp_sessions')->value('id') ?? DB::table('whatsapp_sessions')->insertGetId(['name' => 'ZZ sessão', 'channel' => 'waha', 'external_id' => 'zz-'.uniqid(), 'status' => 'WORKING', 'created_at' => now(), 'updated_at' => now()]);
    $leadId = null;

    if ($leadOwnerId !== null) {
        $stage = DB::table('lead_pipeline_stages')->whereNotIn('code', ['won', 'lost'])->orderBy('id')->first();

        $leadId = DB::table('leads')->insertGetId(['title' => 'ZZ lead', 'lead_value' => 0, 'status' => 1, 'user_id' => $leadOwnerId, 'lead_pipeline_id' => $stage->lead_pipeline_id, 'lead_pipeline_stage_id' => $stage->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    return DB::table('whatsapp_conversations')->insertGetId([
        'lead_id' => $leadId, 'session_id' => $session, 'contact_phone' => $phone, 'contact_name' => 'ZZ contato', 'assigned_user_id' => $assignedTo,
        'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('shows a seller only the conversations of their own leads, the queue and the ones assigned to them', function () {
    $seller = inboxSeller();
    $other = getDefaultAdmin();

    $mine = inboxConversation($seller->id);
    $theirs = inboxConversation($other->id);
    $queue = inboxConversation(null);
    $assigned = inboxConversation($other->id, $seller->id);

    $ids = collect(test()->actingAs($seller, 'user')->getJson(route('admin.whatsapp.inbox.conversations', ['tab' => 'all']))->assertOk()->json('data'))->pluck('id');

    expect($ids->all())->toContain($mine, $queue, $assigned)->not->toContain($theirs);
});

it('does not open, read or fetch media of a conversation outside the seller\'s view', function () {
    $seller = inboxSeller();
    $theirs = inboxConversation(getDefaultAdmin()->id);

    test()->actingAs($seller, 'user')->getJson(route('admin.whatsapp.inbox.messages', ['id' => $theirs]))->assertNotFound();
    test()->actingAs($seller, 'user')->postJson(route('admin.whatsapp.inbox.read', ['id' => $theirs]))->assertNotFound();
    test()->actingAs($seller, 'user')->postJson(route('admin.whatsapp.inbox.claim', ['id' => $theirs]))->assertNotFound();
});

it('keeps the whole Inbox for a user whose view is global', function () {
    $seller = inboxSeller('global');
    $theirs = inboxConversation(getDefaultAdmin()->id);

    $ids = collect(test()->actingAs($seller, 'user')->getJson(route('admin.whatsapp.inbox.conversations', ['tab' => 'all']))->assertOk()->json('data'))->pluck('id');

    expect($ids->all())->toContain($theirs);
});

it('blocks the sixth login attempt for the same e-mail within a minute', function () {
    $email = 'zz-limite-'.uniqid().'@example.test';

    foreach (range(1, 5) as $attempt) {
        test()->post(route('admin.session.store'), ['email' => $email, 'password' => 'wrong'])->assertRedirect();
    }

    $blocked = test()->from(route('admin.session.create'))->post(route('admin.session.store'), ['email' => $email, 'password' => 'wrong']);

    $blocked->assertRedirect(route('admin.session.create'))->assertSessionHas('error');

    expect(session('error'))->toContain('Muitas tentativas');

    RateLimiter::clear('login-email|'.$email);
});

it('limits the forgotten password requests for the same e-mail', function () {
    $email = 'zz-esqueci-'.uniqid().'@example.test';

    foreach (range(1, 3) as $attempt) {
        test()->post(route('admin.forgot_password.store'), ['email' => $email])->assertRedirect();
    }

    test()->from(route('admin.forgot_password.create'))->post(route('admin.forgot_password.store'), ['email' => $email])->assertSessionHas('error');
});
