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
 * Gatilho, ação e espera têm no máximo 1 saída (branch null); a condição tem uma saída "yes" e uma
 * "no" — um ramo sem ligação precisa estar em "end" (fim explícito do fluxo).
 *
 * Fase 2: bloco "Esperar" ({"type": "wait", "config": {"amount": 2, "unit": "hours",
 * "cancel_on_stage_change": true}}) pausa o fluxo (automation_waits) e o agendador o retoma pela saída
 * do bloco. Novos tipos de nó (bot de conversa, Fase 3) entram em NODE_TYPES sem mudar o formato.
 */
final class FlowGraph
{
    public const VERSION = 1;

    public const TRIGGER = 'trigger';

    public const CONDITION = 'condition';

    public const ACTION = 'action';

    public const WAIT = 'wait';

    public const NODE_TYPES = [self::TRIGGER, self::CONDITION, self::ACTION, self::WAIT];

    /** Units of a wait block and of the time triggers, in seconds. */
    public const UNITS = ['minutes' => 60, 'hours' => 3600, 'days' => 86400];

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

    /**
     * Seconds of an "amount + unit" config (wait block, time triggers). 0 when it is not valid.
     *
     * @param  array<string, mixed>  $config
     */
    public static function seconds(array $config): int
    {
        $amount = $config['amount'] ?? null;
        $unit = self::UNITS[(string) ($config['unit'] ?? '')] ?? null;

        if (! $unit || ! is_numeric($amount) || (int) $amount != $amount || (int) $amount <= 0) {
            return 0;
        }

        return (int) $amount * $unit;
    }

    /**
     * What the flow DOES (blocks, their config and the links), without positions and labels: a wait
     * that paused on one hash is cancelled if the flow was changed meanwhile (see Engine::resume).
     *
     * @param  array<string, mixed>  $flow
     */
    public static function hash(array $flow): string
    {
        $nodes = collect((array) ($flow['nodes'] ?? []))
            ->map(fn ($node) => [(string) ($node['id'] ?? ''), (string) ($node['type'] ?? ''), self::sortKeys((array) ($node['config'] ?? [])), array_values((array) ($node['end'] ?? []))])
            ->sortBy(fn ($node) => $node[0])->values()->all();

        $edges = collect((array) ($flow['edges'] ?? []))
            ->map(fn ($edge) => [(string) ($edge['source'] ?? ''), (string) ($edge['target'] ?? ''), $edge['branch'] ?? null])
            ->sortBy(fn ($edge) => $edge[0].'|'.$edge[2].'|'.$edge[1])->values()->all();

        return sha1(json_encode([$nodes, $edges]));
    }

    private static function sortKeys(array $value): array
    {
        ksort($value);

        return array_map(fn ($item) => is_array($item) ? self::sortKeys($item) : $item, $value);
    }
}
