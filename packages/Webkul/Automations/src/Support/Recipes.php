<?php

namespace Webkul\Automations\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * "Usar receita": five ready rules. Pipelines are guessed by name (Pré-Vendas, Closer, Onboarding,
 * Recuperação) when this CRM has them; everything else is left for the person to pick. A recipe is
 * always born in test mode, so its first runs only show in the history what it would do.
 */
final class Recipes
{
    /**
     * @param  Collection<int, array{id: int, name: string, stages: list<array{id: int, name: string, code: string}>}>  $pipelines
     * @return list<array<string, mixed>>
     */
    public static function all(Collection $pipelines): array
    {
        $pre = self::find($pipelines, ['pre-venda', 'pre venda', 'prevenda', 'sdr']);
        $closer = self::find($pipelines, ['closer', 'fechamento']);
        $onboarding = self::find($pipelines, ['onboarding', 'pos-venda', 'pos venda']);
        $recovery = self::find($pipelines, ['recupera']);

        return [
            [
                'key' => 'meeting_to_closer',
                'rule' => self::rule('meeting_to_closer', 'activity_created', ['activity_type' => 'meeting'],
                    $pre ? [['type' => 'pipeline', 'pipeline_id' => $pre['id']]] : [['type' => 'pipeline', 'pipeline_id' => null]],
                    [self::transfer($closer)]),
            ],
            [
                'key' => 'won_to_onboarding',
                'rule' => self::rule('won_to_onboarding', 'stage_entered', self::stageTrigger($closer, 'won'), [], [self::transfer($onboarding)]),
            ],
            [
                'key' => 'lost_to_recovery',
                'rule' => self::rule('lost_to_recovery', 'stage_entered', self::stageTrigger($pre, 'lost'), [], [self::transfer($recovery)]),
            ],
            [
                'key' => 'new_lead_owner_notice',
                'rule' => self::rule('new_lead_owner_notice', 'lead_created', ['source_id' => null, 'type_id' => null], [], [
                    ['type' => 'assign_owner', 'user_id' => null],
                    ['type' => 'notify_whatsapp', 'to' => '', 'message' => trans('automations::app.recipes.new_lead_owner_notice.message')],
                ]),
            ],
            [
                'key' => 'stage_follow_up',
                'rule' => self::rule('stage_follow_up', 'stage_entered', ['pipeline_id' => null, 'stage_id' => null, 'from_stage_id' => null], [], [
                    ['type' => 'create_task', 'title' => trans('automations::app.recipes.stage_follow_up.task'), 'due_in_days' => 1, 'assignee' => 'owner', 'user_id' => null],
                ]),
            ],
        ];
    }

    private static function rule(string $key, string $trigger, array $config, array $conditions, array $actions): array
    {
        return [
            'id' => null,
            'name' => trans("automations::app.recipes.$key.name"),
            'status' => 'test',
            'trigger_type' => $trigger,
            'trigger_config' => $config,
            'conditions' => $conditions,
            'actions' => $actions,
            'run_once_per_lead' => true,
        ];
    }

    private static function transfer(?array $pipeline): array
    {
        $first = $pipeline ? collect($pipeline['stages'])->first(fn ($stage) => ! in_array($stage['code'], ['won', 'lost'], true)) : null;

        return ['type' => 'transfer_pipeline', 'pipeline_id' => $pipeline['id'] ?? null, 'stage_id' => $first['id'] ?? null];
    }

    private static function stageTrigger(?array $pipeline, string $code): array
    {
        $stage = $pipeline ? collect($pipeline['stages'])->firstWhere('code', $code) : null;

        return ['pipeline_id' => $pipeline['id'] ?? null, 'stage_id' => $stage['id'] ?? null, 'from_stage_id' => null];
    }

    /**
     * @param  list<string>  $needles
     */
    private static function find(Collection $pipelines, array $needles): ?array
    {
        foreach ($pipelines as $pipeline) {
            $name = Str::of($pipeline['name'])->ascii()->lower()->replace(['–', '—', '_'], '-')->toString();

            foreach ($needles as $needle) {
                if (str_contains($name, $needle)) {
                    return $pipeline;
                }
            }
        }

        return null;
    }
}
