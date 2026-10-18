<?php

namespace Webkul\Goals\Support;

use Carbon\CarbonImmutable;
use Webkul\Goals\Models\GoalPlan;
use Webkul\Notifications\Support\Notifier;

/**
 * Looks at the month in progress (the general plan and each funnel's) and warns everybody, through
 * the notification center, about whatever the alert rules say is off. Each alert is sent once a day
 * for the same rule and plan, and only on working days.
 */
final class GoalAlerts
{
    /**
     * @return int how many alerts were sent
     */
    public static function run(?CarbonImmutable $today = null): int
    {
        // The text is stored as written, for every user: in Portuguese, whatever the locale of the scheduler.
        $locale = app()->getLocale();
        app()->setLocale('pt_BR');

        try {
            return self::evaluate($today);
        } finally {
            app()->setLocale($locale);
        }
    }

    private static function evaluate(?CarbonImmutable $today): int
    {
        $today = ($today ?? CarbonImmutable::now())->startOfDay();
        $settings = GoalAlertSettings::get();

        if (! $settings['enabled'] || ! $settings['rules']) {
            return 0;
        }

        $month = $today->format('Y-m');

        if (! in_array($today->toDateString(), GoalPlanService::workingDays($month), true)) {
            return 0;
        }

        $sent = 0;

        foreach (GoalPlan::query()->where('year_month', $month)->orderBy('funnel')->pluck('funnel') as $funnel) {
            $progress = GoalProgress::for($month, $funnel, $today);

            foreach ($settings['rules'] as $rule) {
                $alert = self::check($rule, $progress);

                if (! $alert) {
                    continue;
                }

                $key = 'goals:'.$today->toDateString().':'.md5($funnel).':'.$rule['kind'].'.'.($rule['metric'] ?? '').'.'.$rule['percent'];

                $notification = Notifier::send(
                    $alert['title'].($funnel !== '' ? ' — '.$funnel : ''),
                    $alert['body'],
                    $alert['level'],
                    route('admin.goals.index'),
                    null,
                    $key,
                    'goals',
                    ['month' => $month, 'funnel' => $funnel, 'rule' => $rule],
                );

                $sent += $notification ? 1 : 0;
            }
        }

        return $sent;
    }

    /**
     * What the rule finds in the progress of a plan: null when all is fine.
     *
     * @param  array<string, mixed>  $rule
     * @param  array<string, mixed>  $progress  from GoalProgress::for()
     * @return array{title: string, body: string, level: string}|null
     */
    public static function check(array $rule, array $progress): ?array
    {
        return match ($rule['kind']) {
            'pace'   => self::pace($rule, $progress),
            'rate'   => self::rate($rule, $progress),
            'cpl'    => self::cpl($rule, $progress),
            'budget' => self::budget($rule, $progress),
            default  => null,
        };
    }

    private static function pace(array $rule, array $progress): ?array
    {
        $line = $rule['metric'] === 'revenue'
            ? $progress['revenue']
            : collect($progress['steps'])->firstWhere('step', $rule['metric']);

        $expected = $line['expected'] ?? null;

        if (! $expected || $line['actual'] * 100 >= $expected * $rule['percent']) {
            return null;
        }

        $money = $rule['metric'] === 'revenue';
        $show = fn (int $value) => $money ? self::money($value) : (string) $value;

        return [
            'title' => trans('goals::app.alerts.pace_title', ['metric' => trans('goals::app.tracking.'.($money ? 'revenue' : 'steps.'.$rule['metric']))]),
            'body'  => trans('goals::app.alerts.pace_body', ['actual' => $show($line['actual']), 'expected' => $show($expected), 'percent' => GoalPace::percent($line['actual'], $expected), 'target' => $show((int) $line['target']), 'per_day' => $show((int) $line['per_day'])]),
            'level' => $line['actual'] * 100 < $expected * 50 ? Notifier::DANGER : Notifier::WARNING,
        ];
    }

    private static function rate(array $rule, array $progress): ?array
    {
        $rate = collect($progress['rates'])->firstWhere('to', $rule['metric']);

        if (! $rate || $rate['actual'] === null || ! $rate['planned'] || $rate['actual'] * 100 >= $rate['planned'] * $rule['percent']) {
            return null;
        }

        return [
            'title' => trans('goals::app.alerts.rate_title', ['from' => trans('goals::app.tracking.steps.'.$rate['from']), 'to' => trans('goals::app.tracking.steps.'.$rate['to'])]),
            'body'  => trans('goals::app.alerts.rate_body', ['actual' => $rate['actual'], 'planned' => $rate['planned']]),
            'level' => Notifier::WARNING,
        ];
    }

    private static function cpl(array $rule, array $progress): ?array
    {
        $cost = $progress['cost'];

        if (! $cost['cpl'] || ! $cost['cpl_target'] || $cost['cpl'] * 100 <= $cost['cpl_target'] * $rule['percent']) {
            return null;
        }

        return [
            'title' => trans('goals::app.alerts.cpl_title'),
            'body'  => trans('goals::app.alerts.cpl_body', ['actual' => self::money($cost['cpl']), 'target' => self::money($cost['cpl_target'])]),
            'level' => Notifier::WARNING,
        ];
    }

    private static function budget(array $rule, array $progress): ?array
    {
        $cost = $progress['cost'];

        if (! $cost['budget'] || $cost['spend'] * 100 < $cost['budget'] * $rule['percent']) {
            return null;
        }

        $days = $progress['working_days'];

        return [
            'title' => trans('goals::app.alerts.budget_title', ['percent' => GoalPace::percent($cost['spend'], $cost['budget'])]),
            'body'  => trans('goals::app.alerts.budget_body', ['spent' => self::money($cost['spend']), 'budget' => self::money($cost['budget']), 'elapsed' => $days['elapsed'], 'total' => $days['total']]),
            'level' => $cost['spend'] >= $cost['budget'] ? Notifier::DANGER : Notifier::WARNING,
        ];
    }

    private static function money(int $value): string
    {
        return 'R$ '.number_format($value, 0, ',', '.');
    }
}
