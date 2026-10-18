<?php

namespace Webkul\Goals\Support;

use InvalidArgumentException;
use Webkul\Goals\Models\GoalSetting;

/**
 * When the CRM warns about the goals by itself. Each rule is one of:
 *   {"kind":"pace","metric":"leads","percent":80}     a step (or the revenue) is below 80% of what was expected by today
 *   {"kind":"rate","metric":"scheduled","percent":80} the real conversion into a step is below 80% of the planned rate
 *   {"kind":"cpl","percent":120}                      the cost per lead is above 120% of the target
 *   {"kind":"budget","percent":90}                    90% or more of the planned investment is spent
 */
final class GoalAlertSettings
{
    public const KINDS = ['pace', 'rate', 'cpl', 'budget'];

    public const PACE_METRICS = ['leads', 'qualified', 'scheduled', 'attended', 'sales', 'revenue'];

    public const RATE_METRICS = ['qualified', 'scheduled', 'attended', 'sales'];

    /**
     * @return array{enabled: bool, rules: list<array<string, mixed>>}
     */
    public static function get(): array
    {
        GoalSettingsService::get();

        $row = GoalSetting::query()->first();

        return ['enabled' => (bool) $row->alerts_enabled, 'rules' => $row->alert_rules ?? self::defaults()];
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     */
    public static function save(array $rules, bool $enabled, ?int $userId = null): void
    {
        $rules = self::valid($rules);

        GoalSettingsService::get();
        $row = GoalSetting::query()->first();

        GoalAudit::diff('settings', ['alert_rules' => $row->alert_rules ?? self::defaults(), 'alerts_enabled' => (int) $row->alerts_enabled], ['alert_rules' => $rules, 'alerts_enabled' => (int) $enabled], $userId);

        $row->update(['alert_rules' => $rules, 'alerts_enabled' => $enabled, 'updated_by' => $userId]);
    }

    /**
     * The starting point: warn when leads, appointments, sales or revenue fall below 80% of the pace,
     * when the cost per lead is 20% above the target and when 90% of the investment is gone.
     *
     * @return list<array<string, mixed>>
     */
    public static function defaults(): array
    {
        return [
            ['kind' => 'pace', 'metric' => 'leads', 'percent' => 80],
            ['kind' => 'pace', 'metric' => 'scheduled', 'percent' => 80],
            ['kind' => 'pace', 'metric' => 'sales', 'percent' => 80],
            ['kind' => 'pace', 'metric' => 'revenue', 'percent' => 80],
            ['kind' => 'cpl', 'percent' => 120],
            ['kind' => 'budget', 'percent' => 90],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     * @return list<array<string, mixed>>
     */
    private static function valid(array $rules): array
    {
        $valid = [];

        foreach ($rules as $rule) {
            $kind = $rule['kind'] ?? null;
            $percent = filter_var($rule['percent'] ?? null, FILTER_VALIDATE_INT);

            if (! in_array($kind, self::KINDS, true)) {
                throw new InvalidArgumentException('Unknown alert kind.');
            }

            if ($percent === false || $percent < 1 || $percent > 500) {
                throw new InvalidArgumentException('The percent must be a whole number from 1 to 500.');
            }

            $metrics = ['pace' => self::PACE_METRICS, 'rate' => self::RATE_METRICS][$kind] ?? null;
            $entry = ['kind' => $kind, 'percent' => $percent];

            if ($metrics) {
                if (! in_array($rule['metric'] ?? null, $metrics, true)) {
                    throw new InvalidArgumentException('Pick what the alert watches.');
                }

                $entry = ['kind' => $kind, 'metric' => $rule['metric'], 'percent' => $percent];
            }

            // the same rule twice would only warn twice
            $valid[json_encode($entry)] = $entry;
        }

        return array_values($valid);
    }
}
