<?php

namespace Webkul\Automations\Support;

/**
 * Read helpers over a flow graph (already sanitized by FlowValidator).
 *
 * Formato (version 1):
 *
 *   {
 *     "version": 1,
 *     "nodes": [
 *       {"id": "n1", "type": "trigger",   "config": {"trigger_type": "stage_entered", "pipeline_id": 1, "stage_id": 2, "from_stage_id": null}, "position": {"x": 0, "y": 0}},
 *       {"id": "n2", "type": "condition", "config": {"match": "all", "rules": [{"type": "tag", "tag_id": 3}]}, "end": ["no"], "position": {…}},
 *       {"id": "n3", "type": "action",    "config": {"type": "add_tag", "tag_id": 4}, "label": "opcional", "position": {…}}
 *     ],
 *     "edges": [
 *       {"id": "e1", "source": "n1", "target": "n2", "branch": null},
 *       {"id": "e2", "source": "n2", "target": "n3", "branch": "yes"}
 *     ],
 *     "meta": {"source": "builder" | "legacy" | "recipe"}
 *   }
 *
 * Gatilho e ação têm no máximo 1 saída (branch null); a condição tem uma saída "yes" e uma "no" — um
 * ramo sem ligação precisa estar em "end" (fim explícito do fluxo). Novos tipos de nó (espera,
 * bot) entram em NODE_TYPES numa fase futura sem mudar o formato.
 */
final class FlowGraph
{
    public const VERSION = 1;

    public const TRIGGER = 'trigger';

    public const CONDITION = 'condition';

    public const ACTION = 'action';

    public const NODE_TYPES = [self::TRIGGER, self::CONDITION, self::ACTION];

    public const BRANCHES = ['yes', 'no'];

    /**
     * @param  array<string, mixed>  $flow
     * @return array<string, mixed>|null
     */
    public static function trigger(array $flow): ?array
    {
        foreach ((array) ($flow['nodes'] ?? []) as $node) {
            if (($node['type'] ?? null) === self::TRIGGER) {
                return $node;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $flow
     * @return array<string, array<string, mixed>> nodes by id
     */
    public static function nodes(array $flow): array
    {
        $nodes = [];

        foreach ((array) ($flow['nodes'] ?? []) as $node) {
            $nodes[(string) $node['id']] = $node;
        }

        return $nodes;
    }

    /**
     * Where the flow goes after a node (for a condition, after the given branch). Null = the end.
     *
     * @param  array<string, mixed>  $flow
     */
    public static function next(array $flow, string $nodeId, ?string $branch = null): ?string
    {
        foreach ((array) ($flow['edges'] ?? []) as $edge) {
            if ((string) $edge['source'] === $nodeId && ($edge['branch'] ?? null) === $branch) {
                return (string) $edge['target'];
            }
        }

        return null;
    }
}
