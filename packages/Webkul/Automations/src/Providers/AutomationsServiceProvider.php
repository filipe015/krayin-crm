<?php

namespace Webkul\Automations\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Webkul\Automations\Console\AutomationsTick;
use Webkul\Automations\Console\ConvertRulesToFlows;
use Webkul\Automations\Console\PurgeAutomationRuns;
use Webkul\Automations\Http\Middleware\EnsureAutomationsEnabled;
use Webkul\Automations\Models\AutomationRule;
use Webkul\Automations\Support\EventRouter;
use Webkul\Lead\Models\Lead;

class AutomationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/automations.php', 'automations');

        // The ACL is always known, so the routes are always guarded by it (even if the switch changes).
        $this->mergeConfigFrom(__DIR__.'/../Config/acl.php', 'acl');

        // The visual builder's own Vite build (Vue Flow), loaded only on the automations screen.
        $this->app['config']->set('krayin-vite.viters.automations', [
            'hot_file' => 'automations-vite.hot',
            'build_directory' => 'automations/build',
            'package_assets_directory' => 'src/Resources/assets',
        ]);

        // Off (the default): no menu entry.
        if ($this->app['config']->get('automations.enabled')) {
            $this->mergeConfigFrom(__DIR__.'/../Config/menu.php', 'menu.admin');
        }
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'automations');
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'automations');

        Route::middleware(['web', 'admin_locale', 'user', EnsureAutomationsEnabled::class])
            ->prefix(config('app.admin_path').'/settings/automations')
            ->group(__DIR__.'/../Routes/admin.php');

        $this->hideNativeWorkflows();
        $this->listen();

        if ($this->app->runningInConsole()) {
            $this->commands([PurgeAutomationRuns::class, ConvertRulesToFlows::class, AutomationsTick::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('automations:purge')->dailyAt('03:30')->withoutOverlapping();

            // Fase 2: esperas vencidas + gatilhos de tempo. Sai na hora com o módulo desligado.
            $schedule->command('automations:tick')->everyMinute()->withoutOverlapping(10);
        });
    }

    /**
     * AUTOMATIONS_HIDE_NATIVE=true only takes the native "Workflows" entry out of the menu; the
     * workflows already saved keep running (their listener is untouched).
     */
    public function hideNativeWorkflows(): void
    {
        if (! config('automations.hide_native')) {
            return;
        }

        config(['menu.admin' => array_values(array_filter(
            (array) config('menu.admin', []),
            fn ($item) => ($item['key'] ?? null) !== 'settings.automation.workflows'
        ))]);
    }

    /**
     * The four triggers. Every listener returns at once while the module is off, and none of them
     * can break the save of a lead (EventRouter only logs).
     *
     * Stage changes are read from the model's "updated" event (old stage = getOriginal), not from
     * "lead.update.before/after": the model event covers every path that saves a lead (board, lead
     * page, API, WhatsApp inbox, button rules and the automations' own transfers), including the
     * ones that never dispatch lead.update.*.
     */
    private function listen(): void
    {
        Lead::updated(function (Lead $lead) {
            if (! EventRouter::enabled() || ! $lead->wasChanged('lead_pipeline_stage_id')) {
                return;
            }

            EventRouter::fire(AutomationRule::TRIGGER_STAGE_ENTERED, (int) $lead->id, [
                'pipeline_id' => (int) $lead->lead_pipeline_id,
                'stage_id' => (int) $lead->lead_pipeline_stage_id,
                'from_stage_id' => (int) $lead->getOriginal('lead_pipeline_stage_id'),
                'event_id' => (string) Str::uuid(),
            ]);
        });

        Event::listen('lead.create.after', function ($lead) {
            if (! EventRouter::enabled() || ! $lead instanceof Lead) {
                return;
            }

            EventRouter::fire(AutomationRule::TRIGGER_LEAD_CREATED, (int) $lead->id, [
                'source_id' => $lead->lead_source_id ? (int) $lead->lead_source_id : null,
                'type_id' => $lead->lead_type_id ? (int) $lead->lead_type_id : null,
            ]);
        });

        Event::listen('lead.tag.attached', function (int $leadId, int $tagId) {
            if (! EventRouter::enabled()) {
                return;
            }

            EventRouter::fire(AutomationRule::TRIGGER_TAG_ADDED, $leadId, ['tag_id' => $tagId, 'event_id' => (string) Str::uuid()]);
        });

        Event::listen('lead.activity.attached', function (int $leadId, int $activityId) {
            if (! EventRouter::enabled()) {
                return;
            }

            $type = DB::table('activities')->where('id', $activityId)->value('type');

            if ($type === null || $type === 'system') {
                return;
            }

            EventRouter::fire(AutomationRule::TRIGGER_ACTIVITY_CREATED, $leadId, ['activity_id' => $activityId, 'activity_type' => (string) $type]);
        });
    }
}
