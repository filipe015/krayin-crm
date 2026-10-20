<?php

namespace Webkul\Admin\Providers;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Routing\Router;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Webkul\Admin\Bouncer;
use Webkul\Admin\Exceptions\Handler;
use Webkul\Admin\Http\Middleware\Bouncer as BouncerMiddleware;
use Webkul\Admin\Http\Middleware\Locale;
use Webkul\Admin\Http\Middleware\SanitizeUrl;
use Webkul\Contact\Models\Organization;
use Webkul\Contact\Models\Person;
use Webkul\Lead\Models\Lead;
use Webkul\Product\Models\Product;
use Webkul\Quote\Models\Quote;
use Webkul\Warehouse\Models\Warehouse;

class AdminServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(Router $router): void
    {
        $router->aliasMiddleware('user', BouncerMiddleware::class);

        $router->aliasMiddleware('admin_locale', Locale::class);

        $router->aliasMiddleware('sanitize_url', SanitizeUrl::class);

        include __DIR__.'/../Http/helpers.php';

        $this->registerAuthLimiters();

        Route::middleware(['web', 'admin_locale', 'user'])
            ->prefix(config('app.admin_path'))
            ->group(__DIR__.'/../Routes/Admin/web.php');

        Route::middleware(['web', 'admin_locale'])
            ->group(__DIR__.'/../Routes/Front/web.php');

        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'admin');

        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'admin');

        Blade::anonymousComponentPath(__DIR__.'/../Resources/views/components', 'admin');

        $this->app->bind(ExceptionHandler::class, Handler::class);

        Relation::morphMap([
            'leads' => Lead::class,
            'organizations' => Organization::class,
            'persons' => Person::class,
            'products' => Product::class,
            'quotes' => Quote::class,
            'warehouses' => Warehouse::class,
        ]);

        $this->app->register(EventServiceProvider::class);
    }

    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->registerFacades();

        $this->registerConfig();
    }

    /**
     * Register Bouncer as a singleton.
     */
    protected function registerFacades(): void
    {
        $loader = AliasLoader::getInstance();

        $loader->alias('Bouncer', \Webkul\Admin\Facades\Bouncer::class);

        $this->app->singleton('bouncer', function () {
            return new Bouncer;
        });
    }

    /**
     * Register package config.
     */
    protected function registerConfig(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/Config/acl.php', 'acl');

        $this->mergeConfigFrom(dirname(__DIR__).'/Config/menu.php', 'menu.admin');

        $this->mergeConfigFrom(dirname(__DIR__).'/Config/core_config.php', 'core_config');

        $this->mergeConfigFrom(dirname(__DIR__).'/Config/attribute_lookups.php', 'attribute_lookups');

        $this->mergeConfigFrom(dirname(__DIR__).'/Config/attribute_entity_types.php', 'attribute_entity_types');

        $this->mergeConfigFrom(dirname(__DIR__).'/Config/support.php', 'support');
    }

    /**
     * Limits on the screens anybody can reach (login, forgotten and reset password): a few attempts a minute
     * for each e-mail (it holds even when the address of the client is spoofed) and a wider one for each address.
     */
    private function registerAuthLimiters(): void
    {
        $tooMany = fn (Request $request, array $headers) => redirect()->back()->withInput($request->only('email'))->with('error', trans('admin::app.users.too-many-attempts', [
            'seconds' => (int) ($headers['Retry-After'] ?? 60),
        ]));

        RateLimiter::for('admin-login', fn (Request $request) => [
            Limit::perMinute(5)->by('login-email|'.Str::lower((string) $request->input('email')))->response($tooMany),
            Limit::perMinute(30)->by('login-ip|'.$request->ip())->response($tooMany),
        ]);

        RateLimiter::for('admin-password', fn (Request $request) => [
            Limit::perMinute(3)->by('password-email|'.Str::lower((string) $request->input('email')))->response($tooMany),
            Limit::perMinute(10)->by('password-ip|'.$request->ip())->response($tooMany),
        ]);
    }
}
