<?php

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Webkul\Goals\Support\GoalAlertSettings;
use Webkul\Goals\Support\GoalAlerts;
use Webkul\Goals\Support\GoalPlanService;
use Webkul\Goals\Support\GoalSettingsService;
use Webkul\Notifications\Models\Notification;

uses(DatabaseTransactions::class);

function alertsPlan(): void
{
    GoalSettingsService::save(['qualified' => [], 'scheduled' => [], 'attended' => []], [1, 2, 3, 4, 5]);
    GoalPlanService::save('2026-10', '', ['revenue_target' => 50000, 'ticket' => 2500, 'qualification_rate' => 50, 'scheduling_rate' => 50, 'attendance_rate' => 80, 'closing_rate' => 40, 'cpl_target' => 30, 'budget' => 8000]);
}

function goalNotifications(): int
{
    return Notification::query()->where('kind', 'goals')->count();
}

it('starts with default rules and saves new ones, validated', function () {
    expect(GoalAlertSettings::get())->toMatchArray(['enabled' => true])
        ->and(GoalAlertSettings::get()['rules'])->toHaveCount(6);

    GoalAlertSettings::save([['kind' => 'cpl', 'percent' => 150], ['kind' => 'cpl', 'percent' => 150], ['kind' => 'pace', 'metric' => 'sales', 'percent' => 70]], false);

    expect(GoalAlertSettings::get())->toBe(['enabled' => false, 'rules' => [['kind' => 'cpl', 'percent' => 150], ['kind' => 'pace', 'metric' => 'sales', 'percent' => 70]]]);

    expect(fn () => GoalAlertSettings::save([['kind' => 'pace', 'metric' => 'nope', 'percent' => 80]], true))->toThrow(InvalidArgumentException::class)
        ->and(fn () => GoalAlertSettings::save([['kind' => 'cpl', 'percent' => 0]], true))->toThrow(InvalidArgumentException::class);
});

it('saves the rules through the screen', function () {
    $admin = getDefaultAdmin();

    test()->actingAs($admin)->postJson(route('admin.goals.settings.alerts'), ['enabled' => true, 'rules' => [['kind' => 'budget', 'percent' => 95]]])
        ->assertOk()->assertJsonPath('alerts.rules.0.percent', 95);

    test()->actingAs($admin)->postJson(route('admin.goals.settings.alerts'), ['enabled' => true, 'rules' => [['kind' => 'pace', 'percent' => 80]]])->assertStatus(422);
    test()->actingAs($admin)->postJson(route('admin.goals.settings.alerts'), ['enabled' => true, 'rules' => [['kind' => 'cpl', 'percent' => 900]]])->assertStatus(422);
});

it('warns everybody once a day about a pace that is off', function () {
    alertsPlan();
    GoalAlertSettings::save([['kind' => 'pace', 'metric' => 'leads', 'percent' => 80]], true);

    $wednesday = CarbonImmutable::parse('2026-10-14');

    expect(GoalAlerts::run($wednesday))->toBe(1)->and(goalNotifications())->toBe(1);

    $notification = Notification::query()->where('kind', 'goals')->first();

    expect($notification->user_id)->toBeNull()->and($notification->title)->toContain('Leads')
        ->and($notification->level)->toBe('danger');

    // same day again: nothing new; next day: a new warning
    expect(GoalAlerts::run($wednesday))->toBe(0)->and(GoalAlerts::run($wednesday->addDay()))->toBe(1)->and(goalNotifications())->toBe(2);
});

it('is quiet on days off, when turned off, and without a plan', function () {
    alertsPlan();
    GoalAlertSettings::save([['kind' => 'pace', 'metric' => 'leads', 'percent' => 80]], true);

    expect(GoalAlerts::run(CarbonImmutable::parse('2026-10-17')))->toBe(0); // Saturday

    GoalAlertSettings::save([['kind' => 'pace', 'metric' => 'leads', 'percent' => 80]], false);

    expect(GoalAlerts::run(CarbonImmutable::parse('2026-10-14')))->toBe(0)
        ->and(GoalAlerts::run(CarbonImmutable::parse('2026-11-11')))->toBe(0)
        ->and(goalNotifications())->toBe(0);
});

it('checks each kind of rule against the progress', function () {
    $progress = [
        'steps'        => [['step' => 'leads', 'target' => 100, 'expected' => 50, 'actual' => 45, 'per_day' => 5]],
        'revenue'      => ['target' => 10000, 'expected' => 5000, 'actual' => 5000, 'per_day' => 500],
        'rates'        => [['from' => 'leads', 'to' => 'qualified', 'actual' => 30, 'planned' => 50]],
        'cost'         => ['spend' => 7400, 'budget' => 8000, 'cpl' => 45, 'cpl_target' => 30],
        'working_days' => ['elapsed' => 10, 'total' => 22, 'left' => 12],
    ];

    // 45 of 50 expected = 90%: fine at 80, off at 95 (a warning, not yet "danger")
    expect(GoalAlerts::check(['kind' => 'pace', 'metric' => 'leads', 'percent' => 80], $progress))->toBeNull()
        ->and(GoalAlerts::check(['kind' => 'pace', 'metric' => 'leads', 'percent' => 95], $progress)['level'])->toBe('warning')
        ->and(GoalAlerts::check(['kind' => 'pace', 'metric' => 'revenue', 'percent' => 100], $progress))->toBeNull()
        ->and(GoalAlerts::check(['kind' => 'rate', 'metric' => 'qualified', 'percent' => 80], $progress))->not->toBeNull()
        ->and(GoalAlerts::check(['kind' => 'rate', 'metric' => 'qualified', 'percent' => 50], $progress))->toBeNull()
        ->and(GoalAlerts::check(['kind' => 'cpl', 'percent' => 120], $progress))->not->toBeNull()
        ->and(GoalAlerts::check(['kind' => 'cpl', 'percent' => 160], $progress))->toBeNull()
        ->and(GoalAlerts::check(['kind' => 'budget', 'percent' => 90], $progress))->not->toBeNull()
        ->and(GoalAlerts::check(['kind' => 'budget', 'percent' => 95], $progress))->toBeNull();
});
