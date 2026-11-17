<?php

namespace Webkul\Automations\Support;

use Webkul\Automations\Models\AutomationRule;

/**
 * Whether an event is the one a rule waits for ("Quando…"). Only ids from the event itself are
 * compared; everything about the lead's current state belongs to the conditions ("Se…").
 *
 * Event contexts:
 *  - stage_entered:    pipeline_id, stage_id, from_stage_id
 *  - lead_created:     source_id, type_id
 *  - tag_added:        tag_id
 *  - activity_created: activity_id, activity_type
 */
final class TriggerMatcher
{
    /**
     * @param  array<string, mixed>  $context
     */
    public static function matches(AutomationRule $rule, string $trigger, array $context): bool
    {
        if ($rule->trigger_type !== $trigger) {
            return false;
        }

        $config = (array) $rule->trigger_config;

        return match ($trigger) {
            AutomationRule::TRIGGER_STAGE_ENTERED => self::same($config['stage_id'] ?? null, $context['stage_id'] ?? null)
                && self::optional($config['from_stage_id'] ?? null, $context['from_stage_id'] ?? null),
            AutomationRule::TRIGGER_LEAD_CREATED => self::optional($config['source_id'] ?? null, $context['source_id'] ?? null)
                && self::optional($config['type_id'] ?? null, $context['type_id'] ?? null),
            AutomationRule::TRIGGER_TAG_ADDED => self::same($config['tag_id'] ?? null, $context['tag_id'] ?? null),
            AutomationRule::TRIGGER_ACTIVITY_CREATED => self::optionalText($config['activity_type'] ?? null, $context['activity_type'] ?? null),
            default => false,
        };
    }

    /** A required id: both present and equal. */
    private static function same(mixed $expected, mixed $actual): bool
    {
        return filled($expected) && filled($actual) && (int) $expected === (int) $actual;
    }

    /** An optional filter: empty in the rule means "any". */
    private static function optional(mixed $expected, mixed $actual): bool
    {
        return blank($expected) || (filled($actual) && (int) $expected === (int) $actual);
    }

    private static function optionalText(mixed $expected, mixed $actual): bool
    {
        return blank($expected) || (string) $expected === (string) $actual;
    }
}
