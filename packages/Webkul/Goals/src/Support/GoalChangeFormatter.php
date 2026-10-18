<?php

namespace Webkul\Goals\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns the rows of the history of edits into what a person reads: the name of the field, and the
 * value before and after ("R$ 50.000", "40%", "Entrou em: 1º contato", "retirados: 12, 19").
 */
final class GoalChangeFormatter
{
    private const MONEY = ['revenue_target', 'ticket', 'cpl_target', 'budget'];

    /**
     * @param  Collection<int, \Webkul\Goals\Models\GoalChange>  $changes  with user_name
     * @return list<array<string, mixed>>
     */
    public static function format(Collection $changes): array
    {
        $names = self::names();

        return $changes->map(function ($change) use ($names) {
            [$old, $new] = self::values($change->field, $change->old_value, $change->new_value, $names);

            return [
                'id'      => $change->id,
                'at'      => $change->changed_at->toIso8601String(),
                'user'    => $change->user_name,
                'subject' => $change->subject,
                'month'   => $change->year_month,
                'funnel'  => $change->funnel,
                'field'   => self::fieldLabel($change->field),
                'old'     => $old,
                'new'     => $new,
            ];
        })->values()->all();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function values(string $field, ?string $old, ?string $new, array $names): array
    {
        if ($field === 'removed') {
            return ['', trans('goals::app.history.removed')];
        }

        if ($field === 'working_days') {
            return self::workingDays($old, $new);
        }

        return [self::value($field, $old, $names), self::value($field, $new, $names)];
    }

    private static function value(string $field, ?string $value, array $names): string
    {
        if ($value === null) {
            return '—';
        }

        if (in_array($field, self::MONEY, true)) {
            return 'R$ '.number_format((int) $value, 0, ',', '.');
        }

        if (str_ends_with($field, '_rate')) {
            return $value.'%';
        }

        if ($field === 'overrides') {
            $steps = (array) json_decode($value, true);

            return $steps ? implode(', ', array_map(fn ($step) => trans('goals::app.steps.'.$step), $steps)) : trans('goals::app.history.none');
        }

        if ($field === 'alerts_enabled') {
            return trans($value === '1' ? 'goals::app.history.alerts_on' : 'goals::app.history.alerts_off');
        }

        if ($field === 'alert_rules') {
            $rules = (array) json_decode($value, true);

            return $rules ? implode('; ', array_map(fn ($rule) => self::alertRule($rule), $rules)) : trans('goals::app.history.no_rules');
        }

        if ($field === 'default_weekdays') {
            return implode(', ', array_map(fn ($day) => trans('goals::app.weekdays_short.'.$day), (array) json_decode($value, true)));
        }

        if (str_starts_with($field, 'step_rules.')) {
            return self::rules((array) json_decode($value, true), $names);
        }

        return $value;
    }

    /**
     * "22 dias úteis → 20 dias úteis (retirados: 12, 19)"
     *
     * @return array{0: string, 1: string}
     */
    private static function workingDays(?string $old, ?string $new): array
    {
        $before = (array) json_decode((string) $old, true);
        $after = (array) json_decode((string) $new, true);

        $day = fn (string $date) => CarbonImmutable::parse($date)->format('d');
        $removed = array_map($day, array_values(array_diff($before, $after)));
        $added = array_map($day, array_values(array_diff($after, $before)));

        $detail = array_filter([
            $removed ? trans('goals::app.history.removed_days', ['days' => implode(', ', $removed)]) : null,
            $added ? trans('goals::app.history.added_days', ['days' => implode(', ', $added)]) : null,
        ]);

        return [
            trans('goals::app.history.working_days', ['count' => count($before)]),
            trans('goals::app.history.working_days', ['count' => count($after)]).($detail ? ' ('.implode('; ', $detail).')' : ''),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     */
    private static function rules(array $rules, array $names): string
    {
        if (! $rules) {
            return trans('goals::app.history.no_rules');
        }

        return implode('; ', array_map(function (array $rule) use ($names) {
            return match ($rule['type'] ?? '') {
                'stage'       => trans('goals::app.history.rule_stage', ['stages' => implode(', ', array_map(fn ($id) => $names['stages'][$id] ?? "#{$id}", $rule['stage_ids'] ?? []))]),
                'tag'         => trans('goals::app.history.rule_tag', ['tags' => implode(', ', array_map(fn ($id) => $names['tags'][$id] ?? "#{$id}", $rule['tag_ids'] ?? []))]),
                'appointment' => trans('goals::app.history.rule_appointment'),
                default       => '?',
            };
        }, $rules));
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private static function alertRule(array $rule): string
    {
        $metric = match ($rule['kind'] ?? '') {
            'pace'  => trans('goals::app.tracking.'.($rule['metric'] === 'revenue' ? 'revenue' : 'steps.'.$rule['metric'])),
            'rate'  => trans('goals::app.tracking.steps.'.$rule['metric']),
            default => '',
        };

        return trim(trans('goals::app.history.alert_'.$rule['kind'], ['metric' => $metric, 'percent' => $rule['percent']]));
    }

    private static function fieldLabel(string $field): string
    {
        $key = 'goals::app.fields.'.str_replace('.', '_', $field);
        $label = trans($key);

        return $label === $key ? $field : $label;
    }

    /**
     * @return array{stages: array<int, string>, tags: array<int, string>}
     */
    private static function names(): array
    {
        return [
            'stages' => DB::table('lead_pipeline_stages')->pluck('name', 'id')->all(),
            'tags'   => DB::table('tags')->pluck('name', 'id')->all(),
        ];
    }
}
