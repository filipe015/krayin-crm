<?php

namespace Webkul\WhatsApp\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class WhatsAppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/whatsapp.php', 'whatsapp');
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
    }
}
