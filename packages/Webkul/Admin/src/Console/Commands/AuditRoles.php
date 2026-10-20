<?php

namespace Webkul\Admin\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Webkul\User\Models\Role;

/**
 * A review of who can do what: each profile with its users, the visibility they were given and the
 * sensitive permissions it carries, and the points that deserve a second look.
 */
class AuditRoles extends Command
{
    protected $signature = 'roles:audit';

    protected $description = 'Lists the profiles, their users and visibility and the sensitive permissions, with points of attention';

    /** What changes the installation itself, deletes data or opens the system to the outside. */
    private const SENSITIVE = [
        'settings.user.users', 'settings.user.roles', 'settings.user.groups', 'settings.other_settings.api', 'settings.automation.webhooks',
        'settings.automation.workflows', 'settings.automation.data_transfer', 'whatsapp.connections', 'configuration',
    ];

    public function handle(): int
    {
        $attention = [];
        $rows = [];

        foreach (Role::query()->orderBy('id')->get() as $role) {
            $users = DB::table('users')->where('role_id', $role->id)->get(['name', 'status', 'view_permission']);
            $active = $users->where('status', 1);
            $views = $active->groupBy('view_permission')->map->count();
            $permissions = (array) $role->permissions;

            $sensitive = $role->permission_type === 'all'
                ? ['tudo']
                : array_values(array_filter(self::SENSITIVE, fn ($key) => in_array($key, $permissions, true)));

            $deletes = $role->permission_type === 'all' ? [] : array_values(array_filter($permissions, fn ($key) => str_ends_with($key, '.delete')));

            $rows[] = [
                $role->name,
                $role->permission_type === 'all' ? 'tudo' : 'personalizado',
                $active->count().'/'.$users->count(),
                $views->map(fn ($count, $view) => $view.': '.$count)->implode(', ') ?: '—',
                implode(', ', $sensitive) ?: '—',
                count($deletes),
            ];

            if ($role->permission_type === 'all' && $active->count() > 1) {
                $attention[] = "O perfil \"{$role->name}\" (acesso total) tem {$active->count()} usuários ativos: dê acesso total só a quem administra o CRM.";
            }

            if ($role->permission_type === 'custom' && $sensitive !== []) {
                $attention[] = "O perfil \"{$role->name}\" tem permissões sensíveis (".implode(', ', $sensitive).').';
            }

            foreach ($active->where('view_permission', 'global') as $user) {
                if ($role->permission_type === 'custom' && in_array('leads.edit', $permissions, true) && $role->name !== 'Gestor') {
                    $attention[] = "{$user->name} ({$role->name}) vê todos os negócios (visão global) e pode editá-los.";
                }
            }
        }

        foreach (DB::table('users')->whereNull('role_id')->orWhereNotIn('role_id', Role::query()->select('id'))->get(['name']) as $user) {
            $attention[] = "{$user->name} não tem perfil válido.";
        }

        $this->table(['Perfil', 'Tipo', 'Usuários ativos/total', 'Visão dos ativos', 'Permissões sensíveis', 'Exclusões'], $rows);

        if ($attention) {
            $this->newLine();
            $this->warn('Pontos de atenção:');

            foreach (array_unique($attention) as $line) {
                $this->line(' - '.$line);
            }
        } else {
            $this->info('Nada fora do esperado.');
        }

        return self::SUCCESS;
    }
}
