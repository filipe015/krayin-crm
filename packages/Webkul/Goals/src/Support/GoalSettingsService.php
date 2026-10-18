<?php

namespace Webkul\Goals\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Webkul\Goals\Models\GoalSetting;

/**
 * How the steps of the funnel are recognised in the CRM, and the weekdays that count as working days.
 *
 * A step is reached when ANY of its rules is met:
 *   {"type":"stage","pipeline_id":1,"stage_ids":[3,4]}  the deal entered one of these stages
 *   {"type":"tag","tag_ids":[7]}                        the deal got one of these tags
 *   {"type":"appointment"}                              an appointment (meeting) was made for the deal
 * "Lead" (a deal created) and "sale" (a deal won, by its closing date) are fixed.
 */
final class GoalSettingsService
{
    public const STEPS = ['qualified', 'scheduled', 'attended'];

    /**
     * @return array{step_rules: array<string, list<array>>, default_weekdays: list<int>}
     */
    public static function get(): array
    {
        $row = GoalSetting::query()->first() ?? self::create();

        return ['step_rules' => $row->step_rules, 'default_weekdays' => $row->default_weekdays];
    }

    /**
     * @param  array<string, list<array>>  $stepRules
     * @param  list<int>  $weekdays  ISO weekday numbers (1 = Monday ... 7 = Sunday)
     */
    public static function save(array $stepRules, array $weekdays, ?int $userId = null): void
    {
        $rules = self::validRules($stepRules);
        $weekdays = self::validWeekdays($weekdays);

        $row = GoalSetting::query()->first() ?? self::create();
        $old = ['default_weekdays' => $row->default_weekdays];
        $new = ['default_weekdays' => $weekdays];

        foreach (self::STEPS as $step) {
            $old['step_rules.'.$step] = $row->step_rules[$step] ?? [];
            $new['step_rules.'.$step] = $rules[$step];
        }

        GoalAudit::diff('settings', $old, $new, $userId);

        $row->update(['step_rules' => $rules, 'default_weekdays' => $weekdays, 'updated_by' => $userId]);
    }

    /**
     * The starting point: "qualified" is a deal entering the "1º contato" stage when the funnel has one,
     * "scheduled" is an appointment, and "attended" is up to the person.
     */
    private static function create(): GoalSetting
    {
        $qualified = [];

        $pipeline = DB::table('lead_pipelines')->where('is_default', 1)->value('id');

        if ($pipeline) {
            $stage = DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $pipeline)->get(['id', 'name'])
                ->first(fn ($stage) => preg_match('/^\s*1\s*[º°o]?\s*contato/iu', (string) $stage->name) === 1);

            if ($stage) {
                $qualified = [['type' => 'stage', 'pipeline_id' => (int) $pipeline, 'stage_ids' => [(int) $stage->id]]];
            }
        }

        return GoalSetting::query()->create([
            'step_rules'       => ['qualified' => $qualified, 'scheduled' => [['type' => 'appointment']], 'attended' => []],
            'default_weekdays' => GoalCalendar::DEFAULT_WEEKDAYS,
        ]);
    }

    /**
     * @return array<string, list<array>>
     */
    private static function validRules(array $stepRules): array
    {
        $valid = [];

        foreach (self::STEPS as $step) {
            $valid[$step] = [];

            foreach ($stepRules[$step] ?? [] as $rule) {
                $valid[$step][] = match ($rule['type'] ?? null) {
                    'stage'       => self::stageRule($rule),
                    'tag'         => ['type' => 'tag', 'tag_ids' => self::ids($rule['tag_ids'] ?? [], 'tag')],
                    'appointment' => ['type' => 'appointment'],
                    default       => throw new InvalidArgumentException('Unknown rule type.'),
                };
            }
        }

        return $valid;
    }

    private static function stageRule(array $rule): array
    {
        if (! isset($rule['pipeline_id']) || (int) $rule['pipeline_id'] < 1) {
            throw new InvalidArgumentException('A stage rule needs the funnel (pipeline).');
        }

        return ['type' => 'stage', 'pipeline_id' => (int) $rule['pipeline_id'], 'stage_ids' => self::ids($rule['stage_ids'] ?? [], 'stage')];
    }

    /**
     * @return list<int>
     */
    private static function ids(mixed $ids, string $what): array
    {
        $ids = array_values(array_unique(array_map('intval', (array) $ids)));

        if (! $ids || min($ids) < 1) {
            throw new InvalidArgumentException("Pick at least one {$what}.");
        }

        return $ids;
    }

    /**
     * @return list<int>
     */
    private static function validWeekdays(array $weekdays): array
    {
        $weekdays = array_values(array_unique(array_map('intval', $weekdays)));
        sort($weekdays);

        if (! $weekdays || min($weekdays) < 1 || max($weekdays) > 7) {
            throw new InvalidArgumentException('Pick at least one weekday (1 = Monday ... 7 = Sunday).');
        }

        return $weekdays;
    }
}
