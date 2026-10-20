<?php

namespace Webkul\Lead\Providers;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class LeadServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot(Router $router)
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        // Users restricted to some pipelines only get those (deals, pipelines and their stages).
        \Webkul\Lead\Models\Lead::addGlobalScope(new \Webkul\Lead\Models\Scopes\AllowedPipelinesScope('lead_pipeline_id'));
        \Webkul\Lead\Models\Pipeline::addGlobalScope(new \Webkul\Lead\Models\Scopes\AllowedPipelinesScope('id'));
        \Webkul\Lead\Models\Stage::addGlobalScope(new \Webkul\Lead\Models\Scopes\AllowedPipelinesScope('lead_pipeline_id'));
    }

    /**
     * Register services.
     *
     * @return void
     */
    public function register() {}
}
