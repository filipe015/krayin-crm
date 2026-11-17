<?php

namespace Webkul\Automations\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * With AUTOMATIONS_ENABLED=false the screen does not exist.
 */
class EnsureAutomationsEnabled
{
    public function handle(Request $request, Closure $next): mixed
    {
        abort_unless((bool) config('automations.enabled', false), 404);

        return $next($request);
    }
}
