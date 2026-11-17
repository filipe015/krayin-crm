<?php

namespace Webkul\Automations\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Webkul\Automations\Models\AutomationRule;

/**
 * Checks and cleans a flow sent by the visual builder. The browser is never trusted: only known
 * keys survive, every id (pipeline, stage, user, tag…) is checked by RuleValidator, webhook URLs
 * follow the same rules as before, and the graph's shape is checked here.
 *
 * Two levels of problems:
 *  - hard (always 422, even for a draft): not a graph, too many blocks/links, unknown block or
 *    action/condition/trigger type, bad or repeated ids, links to blocks that do not exist;
 *  - soft (a draft may be saved with them, but the flow cannot be turned on or put in test):
 *    not exactly 1 trigger, loose/unreachable blocks, cycles, a condition branch with no link
 *    and not marked as "end", no action, required config missing or pointing at nothing.
 */
final class FlowValidator
{
    private const ID_PATTERN = '/^[A-Za-z0-9_-]{1,40}$/';

    public static function maxNodes(): int
    {
        return max(2, min(200, (int) config('automations.max_nodes', 30)));
    }

    /**
     * A rule saved from the builder: name, status, "once per lead" and the flow. Returns the
     * attributes to store (the old columns are filled from the flow, see FlowConverter::toLegacy).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function validateRule(array $input, ?AutomationRule $existing = null): array
    {
        $base = Validator::make($input, [
            'name' => ['required', 'string', 'max:120'],
            'status' => ['required', Rule::in(AutomationRule::STATUSES)],
            'run_once_per_lead' => ['required', 'boolean'],
            'flow' => ['required', 'array'],
        ], [], [
            'name' => trans('automations::app.fields.name'),
            'status' => trans('automations::app.fields.status'),
            'flow' => trans('automations::app.flow.title'),
        ])->validate();

        // An old rule opened in the builder arrives as its straight-line flow (same block ids).
        $previous = $existing ? ($existing->isFlow() ? (array) $existing->flow : FlowConverter::fromRule($existing)) : null;

        $result = self::sanitize((array) $base['flow'], $previous);

        if ($base['status'] !== AutomationRule::STATUS_OFF) {
            self::assertValid($result);
        }

        $flow = $result['flow'];
        $flow['meta'] = ['source' => 'builder'];

        return [
            'name' => trim($base['name']),
            'status' => $base['status'],
            'run_once_per_lead' => (bool) $base['run_once_per_lead'],
            'flow' => $flow,
        ] + FlowConverter::toLegacy($flow);
    }

    /**
     * @param  array{flow: array<string, mixed>, errors: array<string, list<string>>, general: list<string>}  $result
     *
     * @throws ValidationException
     */
    public static function assertValid(array $result): void
    {
        if ($result['errors'] === [] && $result['general'] === []) {
            return;
        }

        $messages = [];

        if ($result['general'] !== []) {
            $messages['flow'] = $result['general'];
        }

        foreach ($result['errors'] as $nodeId => $list) {
            $messages['flow.nodes.'.$nodeId] = $list;
        }

        throw ValidationException::withMessages($messages);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>|null  $previous  the flow being edited (keeps webhook secrets left unchanged)
     * @return array{flow: array<string, mixed>, errors: array<string, list<string>>, general: list<string>}
     *
     * @throws ValidationException
     */
    public static function sanitize(array $input, ?array $previous = null): array
    {
        $max = self::maxNodes();
        $rawNodes = $input['nodes'] ?? null;
        $rawEdges = $input['edges'] ?? [];

        if (! is_array($rawNodes) || ! array_is_list($rawNodes) || ! is_array($rawEdges) || ! array_is_list($rawEdges)) {
            self::hard(trans('automations::app.flow.errors.shape'));
        }

        if (count($rawNodes) > $max) {
            self::hard(trans('automations::app.flow.errors.too_many_nodes', ['max' => $max]));
        }

        if (count($rawEdges) > $max * 2) {
            self::hard(trans('automations::app.flow.errors.too_many_edges'));
        }

        $previousNodes = $previous ? FlowGraph::nodes($previous) : [];
        $nodes = [];
        $errors = [];

        foreach ($rawNodes as $raw) {
            $raw = is_array($raw) ? $raw : [];
            $id = (string) ($raw['id'] ?? '');
            $type = (string) ($raw['type'] ?? '');

            if (! preg_match(self::ID_PATTERN, $id) || isset($nodes[$id])) {
                self::hard(trans('automations::app.flow.errors.node_id'));
            }

            if (! in_array($type, FlowGraph::NODE_TYPES, true)) {
                self::hard(trans('automations::app.flow.errors.node_type'));
            }

            $nodeErrors = [];
            $config = self::config($type, is_array($raw['config'] ?? null) ? $raw['config'] : [], $nodeErrors, $previousNodes[$id] ?? null);

            $node = [
                'id' => $id,
                'type' => $type,
                'config' => $config,
                'position' => self::position($raw['position'] ?? null),
            ];

            $label = self::label($raw['label'] ?? null);

            if ($label !== null) {
                $node['label'] = $label;
            }

            if ($type === FlowGraph::CONDITION) {
                $node['end'] = array_values(array_intersect(FlowGraph::BRANCHES, array_map('strval', (array) ($raw['end'] ?? []))));
            }

            $nodes[$id] = $node;

            if ($nodeErrors !== []) {
                $errors[$id] = array_values(array_unique(array_values($nodeErrors)));
            }
        }

        $edges = [];
        $seen = [];

        foreach ($rawEdges as $index => $raw) {
            $raw = is_array($raw) ? $raw : [];
            $source = (string) ($raw['source'] ?? '');
            $target = (string) ($raw['target'] ?? '');
            $branch = $raw['branch'] ?? null;
            $branch = $branch === null || $branch === '' ? null : (string) $branch;

            if (! isset($nodes[$source], $nodes[$target]) || $source === $target) {
                self::hard(trans('automations::app.flow.errors.edge'));
            }

            $isCondition = $nodes[$source]['type'] === FlowGraph::CONDITION;

            if ($isCondition ? ! in_array($branch, FlowGraph::BRANCHES, true) : $branch !== null) {
                self::hard(trans('automations::app.flow.errors.edge'));
            }

            // A link back into the trigger, or a second link out of the same exit: soft (shown on the block).
            if ($nodes[$target]['type'] === FlowGraph::TRIGGER) {
                $errors[$target][] = trans('automations::app.flow.errors.trigger_incoming');
            }

            $exit = $source.'|'.($branch ?? '');

            if (isset($seen[$exit])) {
                $errors[$source][] = trans('automations::app.flow.errors.one_exit');
            }

            $seen[$exit] = true;

            $id = (string) ($raw['id'] ?? '');

            $edges[] = [
                'id' => preg_match(self::ID_PATTERN, $id) ? $id : 'e'.($index + 1),
                'source' => $source,
                'target' => $target,
                'branch' => $branch,
            ];
        }

        $flow = ['version' => FlowGraph::VERSION, 'nodes' => array_values($nodes), 'edges' => $edges];

        $general = self::shape($flow, $errors);

        foreach ($errors as $id => $list) {
            $errors[$id] = array_values(array_unique($list));
        }

        return ['flow' => $flow, 'errors' => $errors, 'general' => $general];
    }

    /**
     * Graph checks: exactly one trigger, everything reachable from it, no cycles, every condition
     * branch linked or explicitly ended, at least one action.
     *
     * @param  array<string, mixed>  $flow
     * @param  array<string, list<string>>  $errors
     * @return list<string> problems of the flow as a whole
     */
    private static function shape(array $flow, array &$errors): array
    {
        $general = [];
        $nodes = FlowGraph::nodes($flow);
        $triggers = array_values(array_filter($nodes, fn ($node) => $node['type'] === FlowGraph::TRIGGER));

        if (count($triggers) !== 1) {
            $general[] = trans('automations::app.flow.errors.one_trigger');
        }

        if (! collect($nodes)->contains(fn ($node) => $node['type'] === FlowGraph::ACTION)) {
            $general[] = trans('automations::app.flow.errors.no_action');
        }

        $out = [];

        foreach ($flow['edges'] as $edge) {
            $out[$edge['source']][] = $edge['target'];
        }

        foreach ($nodes as $id => $node) {
            if ($node['type'] !== FlowGraph::CONDITION) {
                continue;
            }

            foreach (FlowGraph::BRANCHES as $branch) {
                if (FlowGraph::next($flow, $id, $branch) === null && ! in_array($branch, $node['end'], true)) {
                    $errors[$id][] = trans('automations::app.flow.errors.branch_'.$branch);
                }
            }
        }

        if ($triggers !== []) {
            $reached = [];
            $stack = [$triggers[0]['id']];

            while ($stack !== []) {
                $id = array_pop($stack);

                if (isset($reached[$id])) {
                    continue;
                }

                $reached[$id] = true;

                foreach ($out[$id] ?? [] as $target) {
                    $stack[] = $target;
                }
            }

            foreach ($nodes as $id => $node) {
                if (! isset($reached[$id])) {
                    $errors[$id][] = trans('automations::app.flow.errors.unreachable');
                }
            }
        }

        // Cycles: depth-first search with colors (0 new, 1 on the path, 2 done).
        $color = [];
        $cycle = false;

        $visit = function (string $id) use (&$visit, &$color, &$cycle, $out) {
            $color[$id] = 1;

            foreach ($out[$id] ?? [] as $target) {
                if (($color[$target] ?? 0) === 1) {
                    $cycle = true;
                } elseif (($color[$target] ?? 0) === 0) {
                    $visit($target);
                }
            }

            $color[$id] = 2;
        };

        foreach (array_keys($nodes) as $id) {
            if (($color[$id] ?? 0) === 0) {
                $visit((string) $id);
            }
        }

        if ($cycle) {
            $general[] = trans('automations::app.flow.errors.cycle');
        }

        return $general;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, string>  $errors
     * @param  array<string, mixed>|null  $previous  the same block before this edit
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private static function config(string $type, array $config, array &$errors, ?array $previous): array
    {
        if ($type === FlowGraph::TRIGGER) {
            $trigger = (string) ($config['trigger_type'] ?? '');

            if (! in_array($trigger, AutomationRule::TRIGGERS, true)) {
                self::hard(trans('automations::app.flow.errors.node_type'));
            }

            return ['trigger_type' => $trigger] + RuleValidator::trigger($trigger, $config, $errors);
        }

        if ($type === FlowGraph::CONDITION) {
            $rules = $config['rules'] ?? [];

            if (! is_array($rules) || ! array_is_list($rules) || count($rules) > 10) {
                self::hard(trans('automations::app.flow.errors.shape'));
            }

            $clean = [];

            foreach ($rules as $index => $rule) {
                $rule = is_array($rule) ? $rule : [];

                if (! in_array($rule['type'] ?? null, AutomationRule::FLOW_CONDITIONS, true)) {
                    self::hard(trans('automations::app.flow.errors.node_type'));
                }

                $clean[] = RuleValidator::condition($rule, "rules.$index", $errors);
            }

            if ($clean === []) {
                $errors['rules'] = trans('automations::app.flow.errors.no_rules');
            }

            return ['match' => ($config['match'] ?? 'all') === 'any' ? 'any' : 'all', 'rules' => $clean];
        }

        if (! in_array($config['type'] ?? null, AutomationRule::ACTIONS, true)) {
            self::hard(trans('automations::app.flow.errors.node_type'));
        }

        $before = ($previous['type'] ?? null) === FlowGraph::ACTION ? (array) ($previous['config'] ?? []) : null;

        return RuleValidator::action($config, 'config', $errors, $before);
    }

    private static function position(mixed $position): array
    {
        $position = is_array($position) ? $position : [];
        $clamp = fn ($value) => is_numeric($value) ? round(max(-100000, min(100000, (float) $value)), 1) : 0.0;

        return ['x' => $clamp($position['x'] ?? 0), 'y' => $clamp($position['y'] ?? 0)];
    }

    private static function label(mixed $label): ?string
    {
        if (! is_string($label)) {
            return null;
        }

        $label = trim(preg_replace('/[\x00-\x1F\x7F<>]/u', '', $label) ?? '');

        return $label === '' ? null : mb_substr($label, 0, 60);
    }

    /**
     * @throws ValidationException
     */
    private static function hard(string $message): never
    {
        throw ValidationException::withMessages(['flow' => [$message]]);
    }
}
