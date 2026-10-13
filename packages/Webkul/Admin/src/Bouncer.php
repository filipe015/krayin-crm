<?php

namespace Webkul\Admin;

use Webkul\User\Repositories\UserRepository;

class Bouncer
{
    /**
     * Checks if user allowed or not for certain action
     *
     * @param  string  $permission
     * @return void
     */
    public function hasPermission($permission)
    {
        if (self::isDisabled($permission)) {
            return false;
        }

        if (auth()->guard('user')->check() && auth()->guard('user')->user()->role->permission_type == 'all') {
            return true;
        } else {
            if (! auth()->guard('user')->check() || ! auth()->guard('user')->user()->hasPermission($permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Checks if user allowed or not for certain action
     *
     * @param  string  $permission
     * @return void
     */
    public static function allow($permission)
    {
        abort_if(self::isDisabled($permission), 404);

        if (! auth()->guard('user')->check() || ! auth()->guard('user')->user()->hasPermission($permission)) {
            abort(401, 'This action is unauthorized');
        }
    }

    /**
     * Whether this installation has the feature turned off (config/crm.php), which also turns off
     * everything below it ("settings.inventory" covers "settings.inventory.warehouse").
     */
    public static function isDisabled(?string $permission): bool
    {
        if ($permission === null || $permission === '') {
            return false;
        }

        foreach ((array) config('crm.disabled_features', []) as $feature) {
            if ($permission === $feature || str_starts_with($permission, $feature.'.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Custom-field entity types that belong to features turned off here (config/crm.php).
     *
     * @return list<string>
     */
    public static function disabledEntityTypes(): array
    {
        $disabled = [];

        foreach ((array) config('crm.entity_features', []) as $entity => $feature) {
            if (self::isDisabled($feature)) {
                $disabled[] = $entity;
            }
        }

        return $disabled;
    }

    /**
     * Whether a route belongs to a feature that is turned off here.
     */
    public static function isRouteDisabled(?string $routeName): bool
    {
        if ($routeName === null || $routeName === '') {
            return false;
        }

        foreach ((array) config('crm.feature_routes', []) as $feature => $patterns) {
            if (self::isDisabled($feature) && \Illuminate\Support\Str::is((array) $patterns, $routeName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * This function will return user ids of current user's groups
     *
     * @return array|null
     */
    public function getAuthorizedUserIds()
    {
        $user = auth()->guard('user')->user();

        // No logged user (API key, console): the installation as a whole.
        if (! $user || $user->view_permission == 'global') {
            return null;
        }

        if ($user->view_permission == 'group') {
            return app(UserRepository::class)->getCurrentUserGroupsUserIds();
        } else {
            return [$user->id];
        }
    }
}
