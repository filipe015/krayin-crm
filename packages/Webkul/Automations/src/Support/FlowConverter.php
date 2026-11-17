<?php

namespace Webkul\Automations\Support;

use Webkul\Automations\Models\AutomationRule;

/**
 * Old rule ⇄ flow.
 *
 *  - fromLegacy: "Quando… Se… Então…" becomes a straight line: Gatilho → (1 Condição com todas as
 *    condições, "todas precisam valer", ramo "Não" = fim) → Ação 1 → Ação 2 → … The engine walks it
 *    exactly like the old rule ran (same checks, same keys, stops at the first failed action).
 *  - toLegacy: the old columns are kept filled from the flow (gatilho always; condições/ações when the
 *    flow is that same straight line), so rolling the migration back keeps the rules working.
 */
final class FlowConverter
{
    private const GAP = 140;

    /**
     * @param  array<string, mixed>  $rule  trigger_type, trigger_config, conditions, actions
     * @return array<string, mixed>
     */
    public static function fromLegacy(array $rule, string $source = 'legacy'): array
    {
        $nodes = [[
            'id' => 'trigger',
            'type' => FlowGraph::TRIGGER,
            'config' => ['trigger_type' => (string) ($rule['trigger_type'] ?? '')] + (array) ($rule['trigger_config'] ?? []),
            'position' => ['x' => 0, 'y' => 0],
        ]];

        $edges = [];
        $previous = 'trigger';
        $branch = null;
        $y = 0;

        $conditions = array_values((array) ($rule['conditions'] ?? []));

        if ($conditions !== []) {
            $nodes[] = [
                'id' => 'conditions',
                'type' => FlowGraph::CONDITION,
                'config' => ['match' => 'all', 'rules' => $conditions],
                'end' => ['no'],
                'position' => ['x' => 0, 'y' => $y += self::GAP],
            ];
            $edges[] = ['id' => 'e-trigger-conditions', 'source' => 'trigger', 'target' => 'conditions', 'branch' => null];
            $previous = 'conditions';
            $branch = 'yes';
        }

        foreach (array_values((array) ($rule['actions'] ?? [])) as $index => $action) {
            $id = 'action-'.($index + 1);

            $nodes[] = ['id' => $id, 'type' => FlowGraph::ACTION, 'config' => (array) $action, 'position' => ['x' => 0, 'y' => $y += self::GAP]];
            $edges[] = ['id' => 'e-'.$previous.'-'.$id, 'source' => $previous, 'target' => $id, 'branch' => $branch];
            $previous = $id;
            $branch = null;
        }

        return ['version' => FlowGraph::VERSION, 'nodes' => $nodes, 'edges' => $edges, 'meta' => ['source' => $source]];
    }

    public static function fromRule(AutomationRule $rule): array
    {
        return self::fromLegacy([
            'trigger_type' => $rule->trigger_type,
            'trigger_config' => (array) $rule->trigger_config,
            'conditions' => (array) $rule->conditions,
            'actions' => (array) $rule->actions,
        ]);
    }

    /**
     * The old columns for a flow. A flow that is not a straight line (or has more than 10 actions or
     * an "any" condition) gets conditions = actions = []: the old engine would do nothing with it.
     *
     * @param  array<string, mixed>  $flow
     * @return array{trigger_type: string, trigger_config: array<string, mixed>, conditions: list<array<string, mixed>>, actions: list<array<string, mixed>>}
     */
    public static function toLegacy(array $flow): array
    {
        $trigger = FlowGraph::trigger($flow);
        $config = (array) ($trigger['config'] ?? []);
        $type = (string) ($config['trigger_type'] ?? AutomationRule::TRIGGER_LEAD_CREATED);
        unset($config['trigger_type']);

        $result = ['trigger_type' => $type, 'trigger_config' => $config, 'conditions' => [], 'actions' => []];

        if (! $trigger) {
            return $result;
        }

        $nodes = FlowGraph::nodes($flow);
        $conditions = [];
        $actions = [];
        $visited = [$trigger['id'] => true];
        $current = FlowGraph::next($flow, $trigger['id']);
        $branch = null;

        if ($current !== null && ($nodes[$current]['type'] ?? null) === FlowGraph::CONDITION) {
            $node = $nodes[$current];

            if (($node['config']['match'] ?? 'all') !== 'all' || FlowGraph::next($flow, $current, 'no') !== null) {
                return $result;
            }

            $conditions = array_values((array) ($node['config']['rules'] ?? []));
            $visited[$current] = true;
            $current = FlowGraph::next($flow, $current, 'yes');
        }

        while ($current !== null) {
            if (isset($visited[$current]) || ($nodes[$current]['type'] ?? null) !== FlowGraph::ACTION) {
                return $result;
            }

            $visited[$current] = true;
            $actions[] = (array) $nodes[$current]['config'];
            $current = FlowGraph::next($flow, $current, $branch);
        }

        if ($actions === [] || count($actions) > 10 || count($visited) !== count($nodes) || count($flow['edges'] ?? []) !== count($visited) - 1) {
            return $result;
        }

        return ['conditions' => $conditions, 'actions' => $actions] + $result;
    }
}
