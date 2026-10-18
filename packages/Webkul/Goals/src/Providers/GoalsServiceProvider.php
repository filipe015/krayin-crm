<?php

namespace Webkul\Goals\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Webkul\Goals\Observers\LeadObserver;
use Webkul\Goals\Support\LeadEventRecorder;
use Webkul\Lead\Models\Lead;

class GoalsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/menu.php', 'menu.admin');
        $this->mergeConfigFrom(__DIR__.'/../Config/acl.php', 'acl');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'goals');
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'goals');

        Route::middleware(['web', 'admin_locale', 'user'])
            ->prefix(config('app.admin_path').'/settings/goals')
            ->group(__DIR__.'/../Routes/settings.php');

        Route::middleware(['web', 'admin_locale', 'user'])
            ->prefix(config('app.admin_path').'/goals')
            ->group(__DIR__.'/../Routes/tracking.php');

        // What happens to a deal is recorded as it happens (see lead_events).
        Lead::observe(LeadObserver::class);

        Event::listen('lead.tag.attached', fn (int $leadId, int $tagId) => LeadEventRecorder::tagAdded($leadId, $tagId));
        Event::listen('lead.activity.attached', fn (int $leadId, int $activityId) => LeadEventRecorder::activityAttached($leadId, $activityId));
    }
}
