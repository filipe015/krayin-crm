<?php

namespace Webkul\WhatsApp\Api\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Webkul\WhatsApp\Api\Models\ApiKey;

/**
 * "Authorization: Bearer kcrm_…" (or "X-Api-Key"). GET needs the "read" ability; anything that
 * changes data needs "write".
 */
class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken() ?: (string) $request->header('X-Api-Key', '');

        $key = $token !== '' ? ApiKey::findByToken($token) : null;

        if (! $key) {
            return response()->json(['error' => 'unauthenticated', 'message' => 'Chave de API ausente ou inválida.'], 401);
        }

        $needed = $request->isMethodSafe() ? 'read' : 'write';

        if (! $key->can($needed)) {
            return response()->json(['error' => 'forbidden', 'message' => 'Esta chave não tem a permissão "'.$needed.'".'], 403);
        }

        // Writing on every call would make the key a hot row; once a minute is enough to see it is alive.
        if (! $key->last_used_at || $key->last_used_at->diffInSeconds(now()) >= 60) {
            $key->forceFill(['last_used_at' => now(), 'last_used_ip' => $request->ip()])->save();
        }

        $request->attributes->set('api_key', $key);

        // Messages come in the installation's language, as in the admin.
        app()->setLocale(core()->getConfigData('general.general.locale_settings.locale') ?: app()->getLocale());

        return $next($request);
    }
}
