<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Webkul\Installer\Http\Middleware\CanInstall;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Who may tell us the client's address and scheme (X-Forwarded-*): "*" trusts any sender, which lets a client
        // pick its own address. Set TRUSTED_PROXIES (comma separated addresses or ranges of the proxy, e.g.
        // "172.16.0.0/12,10.0.0.0/8") once the proxy in front of the app is known.
        $middleware->trustProxies(at: env('TRUSTED_PROXIES') ? array_map('trim', explode(',', env('TRUSTED_PROXIES'))) : '*');

        $middleware->append(CanInstall::class);

        $middleware->encryptCookies(except: [
            'dark_mode',
        ]);

        $middleware->validateCsrfTokens(except: [
            'admin/mail/inbound-parse',
            'admin/web-forms/forms/*',
            'web-forms/forms/*',
        ]);

        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
