<?php

namespace Webkul\WhatsApp\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Webkul\WhatsApp\Console\FailStuckMessages;
use Webkul\WhatsApp\Console\SendScheduledMessages;
use Webkul\WhatsApp\Console\SyncAppointmentReminders;

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

        // Outgoing webhooks: lead and appointment events.
        \Webkul\WhatsApp\Api\Support\WebhookEvents::register();

        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'whatsapp');
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'whatsapp');

        Route::middleware('api')
            ->prefix(config('app.admin_path').'/whatsapp')
            ->group(__DIR__.'/../Routes/webhook.php');

        // Public REST API (API key, not the admin session). Limited per key/IP.
        Route::middleware(['api', 'throttle:120,1', \Webkul\WhatsApp\Api\Http\Middleware\AuthenticateApiKey::class])
            ->prefix('api/v1')
            ->group(__DIR__.'/../Routes/api.php');

        Route::middleware(['web', 'admin_locale', 'user'])
            ->prefix(config('app.admin_path').'/leads')
            ->group(__DIR__.'/../Routes/admin.php');

        Route::middleware(['web', 'admin_locale', 'user'])
            ->prefix(config('app.admin_path').'/agenda')
            ->group(__DIR__.'/../Routes/agenda.php');

        Route::middleware(['web', 'admin_locale', 'user'])
            ->prefix(config('app.admin_path').'/settings/agenda')
            ->group(__DIR__.'/../Routes/agenda-settings.php');

        Route::middleware(['web', 'admin_locale', 'user'])
            ->prefix(config('app.admin_path').'/ads')
            ->group(__DIR__.'/../Routes/ads.php');

        Route::middleware(['web', 'admin_locale', 'user'])
            ->prefix(config('app.admin_path').'/settings/api')
            ->group(__DIR__.'/../Routes/api-settings.php');

        Route::middleware(['web', 'admin_locale', 'user'])
            ->prefix(config('app.admin_path').'/whatsapp/inbox')
            ->group(__DIR__.'/../Routes/inbox.php');

        if ($this->app->runningInConsole()) {
            $this->commands([FailStuckMessages::class, SendScheduledMessages::class, SyncAppointmentReminders::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('whatsapp:fail-stuck-messages')->everyMinute()->withoutOverlapping();
            $schedule->command('whatsapp:send-scheduled')->everyMinute()->withoutOverlapping();
            $schedule->command('whatsapp:sync-reminders')->everyMinute()->withoutOverlapping();
        });
    }
}
