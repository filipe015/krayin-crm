<?php

namespace Webkul\Automations\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * "Usar modelo": five ready flows (Fase 2 added "sem resposta em 24h" with an Esperar block and "parado
 * 3 dias na etapa" with a time trigger; won → Onboarding and lost → Recuperação are NOT recipes: transferring a won/lost lead drops it from the won/lost reports, use Regras de funil for those). Pipelines are guessed by name (Pré-Vendas, Closer, Onboarding,
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

        return [
            [
                'key' => 'meeting_to_closer',
                'rule' => self::rule('meeting_to_closer', 'activity_created', ['activity_type' => 'meeting'],
                    $pre ? [['type' => 'pipeline', 'pipeline_id' => $pre['id']]] : [['type' => 'pipeline', 'pipeline_id' => null]],
                    [self::transfer($closer)]),
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
            // Fase 2 (tempo).
            [
                'key' => 'no_reply_follow_up',
                'rule' => self::noReplyFollowUp(),
            ],
            [
                'key' => 'stage_stale_alert',
                'rule' => self::rule('stage_stale_alert', 'stage_stale', ['pipeline_id' => $pre['id'] ?? null, 'stage_id' => null, 'amount' => 3, 'unit' => 'days'], [], [
                    ['type' => 'create_task', 'title' => trans('automations::app.recipes.stage_stale_alert.task'), 'due_in_days' => 0, 'assignee' => 'owner', 'user_id' => null],
                    ['type' => 'notify_whatsapp', 'to' => '', 'message' => trans('automations::app.recipes.stage_stale_alert.message')],
                ], false),
            ],
        ];
    }

    /**
     * Lead criado → Esperar 24 horas (cancela se mudar de etapa) → tarefa de follow-up → aviso no WhatsApp.
     */
    private static function noReplyFollowUp(): array
    {
        $task = ['type' => 'create_task', 'title' => trans('automations::app.recipes.no_reply_follow_up.task'), 'due_in_days' => 0, 'assignee' => 'owner', 'user_id' => null];
        $notice = ['type' => 'notify_whatsapp', 'to' => '', 'message' => trans('automations::app.recipes.no_reply_follow_up.message')];

        $flow = [
            'version' => FlowGraph::VERSION,
            'nodes' => [
                ['id' => 'trigger', 'type' => FlowGraph::TRIGGER, 'config' => ['trigger_type' => 'lead_created', 'source_id' => null, 'type_id' => null], 'position' => ['x' => 0, 'y' => 0]],
                ['id' => 'wait-24h', 'type' => FlowGraph::WAIT, 'config' => ['amount' => 24, 'unit' => 'hours', 'cancel_on_stage_change' => true], 'position' => ['x' => 0, 'y' => 140]],
                ['id' => 'action-1', 'type' => FlowGraph::ACTION, 'config' => $task, 'position' => ['x' => 0, 'y' => 280]],
                ['id' => 'action-2', 'type' => FlowGraph::ACTION, 'config' => $notice, 'position' => ['x' => 0, 'y' => 420]],
            ],
            'edges' => [
                ['id' => 'e-trigger-wait', 'source' => 'trigger', 'target' => 'wait-24h', 'branch' => null],
                ['id' => 'e-wait-action-1', 'source' => 'wait-24h', 'target' => 'action-1', 'branch' => null],
                ['id' => 'e-action-1-action-2', 'source' => 'action-1', 'target' => 'action-2', 'branch' => null],
            ],
            'meta' => ['source' => 'recipe'],
        ];

        return [
            'id' => null,
            'name' => trans('automations::app.recipes.no_reply_follow_up.name'),
            'status' => 'test',
            'trigger_type' => 'lead_created',
            'trigger_config' => ['source_id' => null, 'type_id' => null],
            'conditions' => [],
            'actions' => [],
            'run_once_per_lead' => true,
            'flow' => $flow,
        ];
    }

    private static function rule(string $key, string $trigger, array $config, array $conditions, array $actions, bool $once = true): array
    {
        $rule = [
            'id' => null,
            'name' => trans("automations::app.recipes.$key.name"),
            'status' => 'test',
            'trigger_type' => $trigger,
            'trigger_config' => $config,
            'conditions' => $conditions,
            'actions' => $actions,
            'run_once_per_lead' => $once,
        ];

        // "Usar modelo" in the visual builder: the same rule as a ready flow (gatilho → condição → ações).
        $rule['flow'] = FlowConverter::fromLegacy($rule, 'recipe');

        return $rule;
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
