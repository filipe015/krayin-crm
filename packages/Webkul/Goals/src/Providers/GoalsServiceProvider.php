<?php

namespace Webkul\Goals\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Webkul\Goals\Observers\LeadObserver;
use Webkul\Goals\Support\LeadEventRecorder;
use Webkul\Lead\Models\Lead;

class GoalsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        // What happens to a deal is recorded as it happens (see lead_events).
        Lead::observe(LeadObserver::class);

        Event::listen('lead.tag.attached', fn (int $leadId, int $tagId) => LeadEventRecorder::tagAdded($leadId, $tagId));
        Event::listen('lead.activity.attached', fn (int $leadId, int $activityId) => LeadEventRecorder::activityAttached($leadId, $activityId));
    }
}
