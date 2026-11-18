<?php

namespace Webkul\Automations\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Webkul\Automations\Models\AutomationRule;

/**
 * Checks a rule sent by the screen and returns only what is stored: every pipeline, stage, user,
 * tag, source and reason must exist (and stages must belong to the chosen pipeline), webhook URLs
 * must be http(s) and not point at this machine or a private network, and unknown keys are dropped.
 */
final class RuleValidator
{
    /**
     * @param  array<string, mixed>  $input
     * @param  AutomationRule|null  $existing  the rule being edited (keeps a webhook secret left unchanged)
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function validate(array $input, ?AutomationRule $existing = null): array
    {
        $base = Validator::make($input, [
            'name' => ['required', 'string', 'max:120'],
            'status' => ['required', Rule::in(AutomationRule::STATUSES)],
            'trigger_type' => ['required', Rule::in(AutomationRule::TRIGGERS)],
            'trigger_config' => ['nullable', 'array'],
            'conditions' => ['nullable', 'array', 'max:10'],
            'conditions.*.type' => ['required', Rule::in(AutomationRule::CONDITIONS)],
            'actions' => ['required', 'array', 'min:1', 'max:10'],
            'actions.*.type' => ['required', Rule::in(AutomationRule::ACTIONS)],
            'run_once_per_lead' => ['required', 'boolean'],
        ], [], self::names())->validate();

        $errors = [];

        $trigger = self::trigger($base['trigger_type'], (array) ($input['trigger_config'] ?? []), $errors);

        $conditions = [];
        foreach (array_values((array) ($input['conditions'] ?? [])) as $index => $condition) {
            $conditions[] = self::condition((array) $condition, "conditions.$index", $errors);
        }

        $previous = $existing ? array_values((array) $existing->actions) : [];
        $actions = [];
        foreach (array_values((array) $input['actions']) as $index => $action) {
            $actions[] = self::action((array) $action, "actions.$index", $errors, $previous[$index] ?? null);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'name' => trim($base['name']),
            'status' => $base['status'],
            'trigger_type' => $base['trigger_type'],
            'trigger_config' => $trigger,
            'conditions' => $conditions,
            'actions' => $actions,
            'run_once_per_lead' => (bool) $base['run_once_per_lead'],
        ];
    }

    /**
     * The config of a trigger (also the "Gatilho" block of the visual builder).
     *
     * @param  array<string, string>  $errors  filled with field => message
     */
    public static function trigger(string $type, array $config, array &$errors): array
    {
        switch ($type) {
            case AutomationRule::TRIGGER_STAGE_ENTERED:
                $pipeline = self::pipeline($config['pipeline_id'] ?? null);
                $stage = self::stage($config['stage_id'] ?? null);

                if (! $pipeline) {
                    $errors['trigger_config.pipeline_id'] = trans('automations::app.validation.pipeline');
                } elseif (! $stage || (int) $stage->lead_pipeline_id !== (int) $pipeline) {
                    $errors['trigger_config.stage_id'] = trans('automations::app.validation.stage');
                }

                $from = self::stage($config['from_stage_id'] ?? null);

                if (filled($config['from_stage_id'] ?? null) && ! $from) {
                    $errors['trigger_config.from_stage_id'] = trans('automations::app.validation.stage');
                }

                return ['pipeline_id' => $pipeline, 'stage_id' => $stage ? (int) $stage->id : null, 'from_stage_id' => $from ? (int) $from->id : null];

            case AutomationRule::TRIGGER_LEAD_CREATED:
                $source = self::exists('lead_sources', $config['source_id'] ?? null);
                $leadType = self::exists('lead_types', $config['type_id'] ?? null);

                if (filled($config['source_id'] ?? null) && ! $source) {
                    $errors['trigger_config.source_id'] = trans('automations::app.validation.source');
                }

                if (filled($config['type_id'] ?? null) && ! $leadType) {
                    $errors['trigger_config.type_id'] = trans('automations::app.validation.type');
                }

                return ['source_id' => $source, 'type_id' => $leadType];

            case AutomationRule::TRIGGER_TAG_ADDED:
                $tag = self::exists('tags', $config['tag_id'] ?? null);

                if (! $tag) {
                    $errors['trigger_config.tag_id'] = trans('automations::app.validation.tag');
                }

                return ['tag_id' => $tag];

            case AutomationRule::TRIGGER_ACTIVITY_CREATED:
                $activityType = $config['activity_type'] ?? null;

                if (filled($activityType) && ! in_array($activityType, self::activityTypes(), true)) {
                    $errors['trigger_config.activity_type'] = trans('automations::app.validation.activity_type');
                }

                return ['activity_type' => filled($activityType) ? (string) $activityType : null];

            case AutomationRule::TRIGGER_STAGE_STALE:
            case AutomationRule::TRIGGER_NO_ACTIVITY:
                // Fase 2: "parado na etapa há X" / "sem atividade há X". Funil e etapa são filtros opcionais.
                $pipeline = filled($config['pipeline_id'] ?? null) ? self::pipeline($config['pipeline_id']) : null;
                $stage = filled($config['stage_id'] ?? null) ? self::stage($config['stage_id']) : null;

                if (filled($config['pipeline_id'] ?? null) && ! $pipeline) {
                    $errors['trigger_config.pipeline_id'] = trans('automations::app.validation.pipeline');
                }

                if (filled($config['stage_id'] ?? null) && (! $stage || ($pipeline && (int) $stage->lead_pipeline_id !== (int) $pipeline) || in_array($stage->code, ['won', 'lost'], true))) {
                    $errors['trigger_config.stage_id'] = trans('automations::app.validation.time_stage');
                }

                $duration = self::duration($config, 365, 'trigger_config', $errors);

                return [
                    'pipeline_id' => $pipeline ?? ($stage ? (int) $stage->lead_pipeline_id : null),
                    'stage_id' => $stage ? (int) $stage->id : null,
                ] + $duration;
        }

        return [];
    }

    /**
     * "amount + unit" of a wait block or a time trigger: a whole number > 0 of minutes/hours/days,
     * at most $maxDays days in all.
     *
     * @param  array<string, string>  $errors
     * @return array{amount: int|null, unit: string}
     */
    public static function duration(array $config, int $maxDays, string $path, array &$errors): array
    {
        $unit = array_key_exists((string) ($config['unit'] ?? ''), FlowGraph::UNITS) ? (string) $config['unit'] : 'hours';
        $amount = $config['amount'] ?? null;
        $seconds = FlowGraph::seconds(['amount' => $amount, 'unit' => $unit]);

        if ($seconds === 0) {
            $errors["$path.amount"] = trans('automations::app.validation.amount');
        } elseif ($seconds > $maxDays * 86400) {
            $errors["$path.amount"] = trans('automations::app.validation.amount_max', ['days' => $maxDays]);
        }

        return ['amount' => is_numeric($amount) ? (int) $amount : null, 'unit' => $unit];
    }

    /**
     * One condition (also a rule of a "Condição" block). $condition['type'] must be known.
     *
     * @param  array<string, string>  $errors
     */
    public static function condition(array $condition, string $path, array &$errors): array
    {
        $type = (string) $condition['type'];

        switch ($type) {
            case 'pipeline':
                $id = self::pipeline($condition['pipeline_id'] ?? null);
                $id ?: $errors["$path.pipeline_id"] = trans('automations::app.validation.pipeline');

                return ['type' => $type, 'pipeline_id' => $id];

            case 'stage':
                $stage = self::stage($condition['stage_id'] ?? null);
                $stage ?: $errors["$path.stage_id"] = trans('automations::app.validation.stage');

                return ['type' => $type, 'stage_id' => $stage ? (int) $stage->id : null];

            case 'source':
                $id = self::exists('lead_sources', $condition['source_id'] ?? null);
                $id ?: $errors["$path.source_id"] = trans('automations::app.validation.source');

                return ['type' => $type, 'source_id' => $id];

            case 'tag':
                $id = self::exists('tags', $condition['tag_id'] ?? null);
                $id ?: $errors["$path.tag_id"] = trans('automations::app.validation.tag');

                return ['type' => $type, 'tag_id' => $id];

            case 'owner':
                $id = self::exists('users', $condition['user_id'] ?? null);
                $id ?: $errors["$path.user_id"] = trans('automations::app.validation.user');

                return ['type' => $type, 'user_id' => $id];

            case 'field':
                $code = (string) ($condition['attribute_code'] ?? '');
                $known = in_array($code, ConditionEvaluator::LEAD_COLUMNS, true)
                    || DB::table('attributes')->where('entity_type', 'leads')->where('code', $code)->exists();

                $known ?: $errors["$path.attribute_code"] = trans('automations::app.validation.field');

                $field = ['type' => $type, 'attribute_code' => $code, 'value' => mb_substr((string) ($condition['value'] ?? ''), 0, 255)];

                // "contém" (visual builder): kept only when asked, so old rules keep their exact shape.
                if (($condition['operator'] ?? null) === 'contains') {
                    $field['operator'] = 'contains';
                    trim($field['value']) !== '' ?: $errors["$path.value"] = trans('automations::app.validation.value');
                }

                return $field;

            case 'lead_value':
                $operator = in_array($condition['operator'] ?? null, ConditionEvaluator::VALUE_OPERATORS, true) ? $condition['operator'] : null;
                $value = $condition['value'] ?? null;

                $operator ?: $errors["$path.operator"] = trans('automations::app.validation.operator');
                (is_numeric($value) && abs((float) $value) < 1e13) ?: $errors["$path.value"] = trans('automations::app.validation.value');

                return ['type' => $type, 'operator' => $operator ?? 'gte', 'value' => is_numeric($value) ? (float) $value : null];
        }

        return ['type' => $type];
    }

    /**
     * One action (also an "Ação" block). $action['type'] must be known.
     *
     * @param  array<string, string>  $errors
     */
    public static function action(array $action, string $path, array &$errors, ?array $previous): array
    {
        $type = (string) $action['type'];

        switch ($type) {
            case 'move_stage':
                $stage = self::stage($action['stage_id'] ?? null);

                if (! $stage || $stage->code === 'lost') {
                    $errors["$path.stage_id"] = trans('automations::app.validation.move_stage');
                }

                return ['type' => $type, 'stage_id' => $stage ? (int) $stage->id : null];

            case 'transfer_pipeline':
                $pipeline = self::pipeline($action['pipeline_id'] ?? null);
                $stage = self::stage($action['stage_id'] ?? null);

                if (! $pipeline) {
                    $errors["$path.pipeline_id"] = trans('automations::app.validation.pipeline');
                } elseif (! $stage || (int) $stage->lead_pipeline_id !== (int) $pipeline) {
                    $errors["$path.stage_id"] = trans('automations::app.validation.stage');
                }

                return ['type' => $type, 'pipeline_id' => $pipeline, 'stage_id' => $stage ? (int) $stage->id : null];

            case 'assign_owner':
                $user = DB::table('users')->where('id', (int) ($action['user_id'] ?? 0))->where('status', 1)->value('id');
                $user ?: $errors["$path.user_id"] = trans('automations::app.validation.user');

                return ['type' => $type, 'user_id' => $user ? (int) $user : null];

            case 'add_tag':
                $id = self::exists('tags', $action['tag_id'] ?? null);
                $id ?: $errors["$path.tag_id"] = trans('automations::app.validation.tag');

                return ['type' => $type, 'tag_id' => $id];

            case 'create_task':
                $title = trim((string) ($action['title'] ?? ''));
                $days = $action['due_in_days'] ?? null;
                $assignee = ($action['assignee'] ?? 'owner') === 'fixed' ? 'fixed' : 'owner';
                $user = $assignee === 'fixed' ? DB::table('users')->where('id', (int) ($action['user_id'] ?? 0))->where('status', 1)->value('id') : null;

                ($title !== '' && mb_strlen($title) <= 200) ?: $errors["$path.title"] = trans('automations::app.validation.task_title');
                (is_numeric($days) && (int) $days >= 0 && (int) $days <= 365) ?: $errors["$path.due_in_days"] = trans('automations::app.validation.days');
                ($assignee === 'owner' || $user) ?: $errors["$path.user_id"] = trans('automations::app.validation.user');

                return ['type' => $type, 'title' => $title, 'due_in_days' => (int) $days, 'assignee' => $assignee, 'user_id' => $user ? (int) $user : null];

            case 'notify_whatsapp':
                $to = trim((string) ($action['to'] ?? ''));
                $message = trim((string) ($action['message'] ?? ''));

                (ActionRunner::chatIds($to) !== [] && mb_strlen($to) <= 300) ?: $errors["$path.to"] = trans('automations::app.validation.whatsapp_to');
                ($message !== '' && mb_strlen($message) <= 1000) ?: $errors["$path.message"] = trans('automations::app.validation.message');

                return ['type' => $type, 'to' => $to, 'message' => $message];

            case 'webhook':
                $url = trim((string) ($action['url'] ?? ''));
                $secret = (string) ($action['secret'] ?? '');

                (mb_strlen($url) <= 500 && self::safeUrl($url)) ?: $errors["$path.url"] = trans('automations::app.validation.url');

                // The screen never receives the secret back: "__keep__" means "unchanged".
                if ($secret === RulePresenter::KEEP_SECRET) {
                    $secret = ($previous['type'] ?? null) === 'webhook' ? (string) ($previous['secret'] ?? '') : '';
                }

                mb_strlen($secret) <= 200 ?: $errors["$path.secret"] = trans('automations::app.validation.secret');

                return ['type' => $type, 'url' => $url, 'secret' => $secret];

            case 'mark_lost':
                $reason = filled($action['reason_id'] ?? null) ? self::exists('lead_lost_reasons', $action['reason_id']) : null;

                if (filled($action['reason_id'] ?? null) && ! $reason) {
                    $errors["$path.reason_id"] = trans('automations::app.validation.reason');
                }

                return ['type' => $type, 'reason_id' => $reason];
        }

        return ['type' => $type];
    }

    /**
     * http(s) only, no credentials in the URL, and never localhost or a private / reserved IP
     * written in the URL (a webhook is not a way to reach the server's own network).
     */
    public static function safeUrl(string $url): bool
    {
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! preg_match('#^https?://#i', $url)) {
            return false;
        }

        $parts = parse_url($url);
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if ($host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.internal')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        return true;
    }

    /** @return list<string> */
    public static function activityTypes(): array
    {
        return ['call', 'meeting', 'lunch', 'task', 'note', 'email', 'file'];
    }

    private static function pipeline(mixed $id): ?int
    {
        return self::exists('lead_pipelines', $id);
    }

    private static function stage(mixed $id): ?object
    {
        return filled($id) && is_numeric($id) ? DB::table('lead_pipeline_stages')->where('id', (int) $id)->first(['id', 'code', 'lead_pipeline_id']) : null;
    }

    private static function exists(string $table, mixed $id): ?int
    {
        if (! filled($id) || ! is_numeric($id)) {
            return null;
        }

        $found = DB::table($table)->where('id', (int) $id)->value('id');

        return $found ? (int) $found : null;
    }

    /** @return array<string, string> */
    private static function names(): array
    {
        return [
            'name' => trans('automations::app.fields.name'),
            'status' => trans('automations::app.fields.status'),
            'trigger_type' => trans('automations::app.fields.trigger'),
            'actions' => trans('automations::app.fields.actions'),
        ];
    }
}
