<?php

namespace Webkul\Admin\Support;

use InvalidArgumentException;
use Webkul\User\Models\Role;

/**
 * The ready-made profiles every installation can start from. Each one lists only what the function
 * needs (least privilege): nobody but the Administrator gets users, profiles, API keys, webhooks,
 * automation or the WhatsApp connections. A profile does not carry the user's visibility (all / group / only
 * their own): that is chosen on each user, so each profile names the one it is meant for.
 */
final class DefaultRoles
{
    /**
     * @return list<array{name: string, description: string, view: string, permissions: list<string>}>
     */
    public static function definitions(): array
    {
        $lead = ['leads', 'leads.view', 'leads.create', 'leads.create.quick-create', 'leads.edit'];
        $person = ['contacts', 'contacts.persons', 'contacts.persons.view', 'contacts.persons.create', 'contacts.persons.create.quick-create', 'contacts.persons.edit'];
        $organization = ['contacts.organizations', 'contacts.organizations.create', 'contacts.organizations.create.quick-create', 'contacts.organizations.edit'];
        $activity = ['activities', 'activities.create', 'activities.edit'];
        $chat = ['whatsapp', 'whatsapp.send', 'whatsapp.quick-replies'];
        $agenda = ['agenda', 'agenda.create'];

        return [
            [
                'name'        => 'Gestor',
                'description' => 'Acompanha e organiza o time: todos os negócios, relatórios, metas e anúncios, sem acesso a usuários, perfis, chaves de API nem automações. Visão recomendada: global.',
                'view'        => 'global',
                'permissions' => [
                    'dashboard', ...$lead, 'leads.delete', ...$person, 'contacts.persons.delete', ...$organization, 'contacts.organizations.delete',
                    ...$activity, 'activities.delete', 'quotes', 'quotes.create', 'quotes.edit', 'quotes.delete', 'quotes.mail', 'quotes.print',
                    'products', 'products.view', 'products.create', 'products.create.quick-create', 'products.edit',
                    ...$chat, 'whatsapp.manage', ...$agenda, 'ads', 'ads.settings', 'goals',
                    'settings', 'settings.other_settings', 'settings.other_settings.goals', 'settings.other_settings.goals.edit',
                    'settings.other_settings.agenda', 'settings.other_settings.agenda.edit',
                    'settings.other_settings.tags', 'settings.other_settings.tags.create', 'settings.other_settings.tags.edit', 'settings.other_settings.tags.delete',
                ],
            ],
            [
                'name'        => 'Vendedor',
                'description' => 'Trabalha os próprios negócios: cadastra e edita, conversa no WhatsApp, agenda e faz cotações; não exclui nada. Visão recomendada: individual.',
                'view'        => 'individual',
                'permissions' => [
                    'dashboard', ...$lead, ...$person, ...$organization, ...$activity,
                    'quotes', 'quotes.create', 'quotes.edit', 'quotes.mail', 'quotes.print', 'products', 'products.view',
                    ...$chat, ...$agenda, 'goals',
                ],
            ],
            [
                'name'        => 'SDR (pré-venda)',
                'description' => 'Faz o primeiro contato e qualifica: leads, contatos, WhatsApp e agenda; sem cotações nem serviços. Visão recomendada: individual.',
                'view'        => 'individual',
                'permissions' => ['dashboard', ...$lead, ...$person, ...$organization, ...$activity, ...$chat, ...$agenda, 'goals'],
            ],
            [
                'name'        => 'Atendimento (WhatsApp)',
                'description' => 'Responde a caixa de entrada do WhatsApp, assume e encerra conversas e cria o negócio a partir dela; sem painel nem relatórios. Visão recomendada: grupo.',
                'view'        => 'group',
                'permissions' => [...$chat, 'whatsapp.manage', ...$lead, ...$person, 'activities', 'activities.create', ...$agenda],
            ],
            [
                'name'        => 'Marketing / Tráfego',
                'description' => 'Cuida de anúncios, metas e formulários: vê os negócios para medir resultado, sem editá-los. Visão recomendada: global.',
                'view'        => 'global',
                'permissions' => [
                    'dashboard', 'leads', 'leads.view', 'ads', 'ads.settings', 'goals',
                    'settings', 'settings.other_settings', 'settings.other_settings.goals', 'settings.other_settings.goals.edit',
                    'settings.other_settings.web_forms', 'settings.other_settings.web_forms.view', 'settings.other_settings.web_forms.create', 'settings.other_settings.web_forms.edit',
                    'settings.other_settings.tags', 'settings.other_settings.tags.create', 'settings.other_settings.tags.edit',
                ],
            ],
            [
                'name'        => 'Somente leitura',
                'description' => 'Só consulta: painel, negócios, contatos, agenda, metas e anúncios, sem criar, editar nem excluir. Visão recomendada: grupo.',
                'view'        => 'group',
                'permissions' => [
                    'dashboard', 'leads', 'leads.view', 'contacts', 'contacts.persons', 'contacts.persons.view', 'contacts.organizations',
                    'activities', 'agenda', 'goals', 'ads', 'products', 'products.view',
                ],
            ],
        ];
    }

    /**
     * Every permission plus the ones above it in the tree ("leads.edit" brings "leads"): the screen of
     * profiles needs the parents ticked for the child to show up.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    public static function expand(array $keys): array
    {
        $known = collect(config('acl'))->pluck('key')->flip();
        $all = [];

        foreach ($keys as $key) {
            if (! $known->has($key)) {
                throw new InvalidArgumentException("Unknown permission: {$key}");
            }

            $path = '';

            foreach (explode('.', $key) as $part) {
                $path = $path === '' ? $part : $path.'.'.$part;

                if ($known->has($path)) {
                    $all[$path] = true;
                }
            }
        }

        return array_keys($all);
    }

    /**
     * Creates the profiles that do not exist yet (matched by name). A profile that exists is left alone
     * unless $update is on, which rewrites its permissions to the standard ones.
     *
     * @param  list<string>|null  $only  names to install (all when null)
     * @return array{created: list<string>, updated: list<string>, skipped: list<string>}
     */
    public static function install(bool $update = false, ?array $only = null): array
    {
        $result = ['created' => [], 'updated' => [], 'skipped' => []];

        foreach (self::definitions() as $definition) {
            if ($only !== null && ! in_array($definition['name'], $only, true)) {
                continue;
            }

            $attributes = [
                'description'     => $definition['description'],
                'permission_type' => 'custom',
                'permissions'     => self::expand($definition['permissions']),
            ];

            $role = Role::query()->where('name', $definition['name'])->first();

            if (! $role) {
                Role::query()->create(['name' => $definition['name']] + $attributes);
                $result['created'][] = $definition['name'];
            } elseif ($update && $role->permission_type === 'custom') {
                $role->update($attributes);
                $result['updated'][] = $definition['name'];
            } else {
                $result['skipped'][] = $definition['name'];
            }
        }

        return $result;
    }
}
