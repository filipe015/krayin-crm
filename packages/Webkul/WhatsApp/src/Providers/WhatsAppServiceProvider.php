<?php

namespace Webkul\WhatsApp\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Webkul\WhatsApp\Console\FailStuckMessages;

class WhatsAppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/whatsapp.php', 'whatsapp');
        $this->mergeConfigFrom(__DIR__.'/../Config/menu.php', 'menu.admin');
        $this->mergeConfigFrom(__DIR__.'/../Config/acl.php', 'acl');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'whatsapp');
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'whatsapp');

        Route::middleware('api')
            ->prefix(config('app.admin_path').'/whatsapp')
            ->group(__DIR__.'/../Routes/webhook.php');

        Route::middleware(['web', 'admin_locale', 'user'])
            ->prefix(config('app.admin_path').'/leads')
            ->group(__DIR__.'/../Routes/admin.php');

        Route::middleware(['web', 'admin_locale', 'user'])
            ->prefix(config('app.admin_path').'/whatsapp/inbox')
            ->group(__DIR__.'/../Routes/inbox.php');

        if ($this->app->runningInConsole()) {
            $this->commands([FailStuckMessages::class]);
        }

        $this->callAfterResolving(Schedule::class, fn (Schedule $schedule) => $schedule
            ->command('whatsapp:fail-stuck-messages')
            ->everyMinute()
            ->withoutOverlapping());
    }
}
