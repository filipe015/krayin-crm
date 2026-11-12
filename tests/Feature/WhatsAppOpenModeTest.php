<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Webkul\User\Models\User;

uses(DatabaseTransactions::class);

/**
 * "My account" preference: the lead WhatsApp buttons open the CRM Inbox ("crm", default) or WhatsApp Web ("web").
 */
function openModeUser(): User
{
    $roleId = DB::table('roles')->insertGetId([
        'name' => 'ZZ modo '.uniqid(), 'description' => 'teste', 'permission_type' => 'custom',
        'permissions' => json_encode(['whatsapp', 'whatsapp.send']),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $id = DB::table('users')->insertGetId([
        'name' => 'ZZ Modo', 'email' => 'zz-'.uniqid().'@example.test', 'password' => Hash::make('secret-123'),
        'status' => 1, 'role_id' => $roleId, 'view_permission' => 'global', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return User::findOrFail($id);
}

it('defaults to the Inbox and switches to WhatsApp Web without asking for the password', function () {
    $user = openModeUser();

    $this->actingAs($user, 'user');
    expect($user->whatsapp_open_mode)->toBe('crm');
    expect($user->opensWhatsappInInbox())->toBeTrue();

    $this->get(route('admin.user.account.edit'))->assertOk()->assertSee('name="whatsapp_open_mode"', false);

    $this->put(route('admin.user.account.whatsapp_open_mode'), ['whatsapp_open_mode' => 'web'])->assertRedirect();

    $user->refresh();
    expect($user->whatsapp_open_mode)->toBe('web');
    expect($user->opensWhatsappInInbox())->toBeFalse();

    $this->put(route('admin.user.account.whatsapp_open_mode'), ['whatsapp_open_mode' => 'crm'])->assertRedirect();
    expect($user->refresh()->opensWhatsappInInbox())->toBeTrue();
});

it('rejects an unknown mode', function () {
    $user = openModeUser();

    $this->actingAs($user, 'user')
        ->put(route('admin.user.account.whatsapp_open_mode'), ['whatsapp_open_mode' => 'outro'])
        ->assertSessionHasErrors('whatsapp_open_mode');

    expect($user->refresh()->whatsapp_open_mode)->toBe('crm');
});

it('still renders the leads board and the lead page with the preference in place', function () {
    $admin = getDefaultAdmin();
    $admin->forceFill(['whatsapp_open_mode' => 'web'])->save();

    $this->actingAs($admin, 'user')->get(route('admin.leads.index'))->assertOk();
});
