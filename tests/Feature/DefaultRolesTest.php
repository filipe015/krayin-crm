<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Webkul\Admin\Support\DefaultRoles;
use Webkul\User\Models\Role;
use Webkul\User\Models\User;

uses(DatabaseTransactions::class);

/**
 * The standard profiles: every permission they name exists, none carries what only the Administrator
 * should have, and a user holding one reaches what the function needs and nothing of the settings.
 */
function userWithDefaultRole(string $name, string $view = 'individual'): User
{
    DefaultRoles::install(false, [$name]);

    $id = DB::table('users')->insertGetId([
        'name' => 'ZZ '.$name, 'email' => 'zz-'.uniqid().'@example.test', 'password' => Hash::make('secret-123'),
        'status' => 1, 'role_id' => Role::query()->where('name', $name)->value('id'), 'view_permission' => $view, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return User::query()->findOrFail($id);
}

it('only names permissions that exist', function () {
    foreach (DefaultRoles::definitions() as $definition) {
        expect(fn () => DefaultRoles::expand($definition['permissions']))->not->toThrow(InvalidArgumentException::class);
    }

    expect(fn () => DefaultRoles::expand(['leads.nao-existe']))->toThrow(InvalidArgumentException::class);
});

it('never hands out what only the Administrator should have', function () {
    $forbidden = ['settings.user', 'settings.automation', 'settings.other_settings.api', 'whatsapp.connections', 'configuration', 'settings.lead.pipelines'];

    foreach (DefaultRoles::definitions() as $definition) {
        $permissions = DefaultRoles::expand($definition['permissions']);

        foreach ($forbidden as $prefix) {
            expect(collect($permissions)->filter(fn ($key) => $key === $prefix || str_starts_with($key, $prefix.'.')))->toBeEmpty("{$definition['name']} carries {$prefix}");
        }
    }
});

it('expands each permission with the ones above it', function () {
    expect(DefaultRoles::expand(['leads.edit']))->toBe(['leads', 'leads.edit']);
});

it('installs the profiles once and leaves existing ones alone', function () {
    Role::query()->where('name', 'Vendedor')->delete();

    $first = DefaultRoles::install();

    expect($first['created'])->toContain('Gestor', 'Vendedor')->and($first['skipped'])->toBe([]);

    Role::query()->where('name', 'Vendedor')->update(['description' => 'editado à mão']);

    $second = DefaultRoles::install();

    expect($second['created'])->toBe([])->and($second['skipped'])->toContain('Vendedor')
        ->and(Role::query()->where('name', 'Vendedor')->value('description'))->toBe('editado à mão');

    $third = DefaultRoles::install(update: true);

    expect($third['updated'])->toContain('Vendedor')->and(Role::query()->where('name', 'Vendedor')->value('description'))->not->toBe('editado à mão');
});

it('lets a seller work the leads but not the settings', function () {
    $seller = userWithDefaultRole('Vendedor');

    test()->actingAs($seller, 'user')->getJson(route('admin.leads.index'))->assertOk();
    test()->actingAs($seller, 'user')->get(route('admin.settings.users.index'))->assertStatus(401);
    test()->actingAs($seller, 'user')->get(route('admin.settings.roles.index'))->assertStatus(401);
    test()->actingAs($seller, 'user')->get(route('admin.settings.roles.create'))->assertStatus(401);
});

it('lets support work the Inbox but not delete leads', function () {
    $support = userWithDefaultRole('Atendimento (WhatsApp)', 'group');

    test()->actingAs($support, 'user')->get(route('admin.whatsapp.inbox.index'))->assertOk();
    test()->actingAs($support, 'user')->deleteJson(route('admin.leads.delete', ['id' => 1]))->assertStatus(401);
});

it('installs the profiles from the Profiles screen, for those who may create profiles', function () {
    Role::query()->where('name', 'Gestor')->delete();

    test()->actingAs(getDefaultAdmin(), 'user')->post(route('admin.settings.roles.install_defaults'))->assertRedirect(route('admin.settings.roles.index'));

    expect(Role::query()->where('name', 'Gestor')->exists())->toBeTrue();

    $seller = userWithDefaultRole('Vendedor');

    test()->actingAs($seller, 'user')->post(route('admin.settings.roles.install_defaults'))->assertStatus(401);
});
