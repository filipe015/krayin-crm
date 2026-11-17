<?php

namespace Webkul\Automations\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Webkul\Automations\Models\AutomationRule;
use Webkul\Automations\Models\AutomationRun;
use Webkul\Automations\Support\ConditionEvaluator;
use Webkul\Automations\Support\Engine;
use Webkul\Automations\Support\FlowValidator;
use Webkul\Automations\Support\Recipes;
use Webkul\Automations\Support\RulePresenter;
use Webkul\Automations\Support\RuleValidator;
use Webkul\Lead\Models\Lead;
use Webkul\WhatsApp\Support\AgentNotifier;

/**
 * Configurações → Automação → Automações: the rules, the builder, the history and "Simular em um lead".
 * Permissions come from the ACL (settings.automation.automations[.create|.edit|.delete]) through the
 * routes; what a user may SEE of leads (history, simulation) follows bouncer()'s visibility.
 */
class AutomationRuleController extends Controller
{
    public function index(): View
    {
        $stages = DB::table('lead_pipeline_stages')->orderBy('sort_order')->get(['id', 'name', 'code', 'lead_pipeline_id'])->groupBy('lead_pipeline_id');

        $pipelines = DB::table('lead_pipelines')->orderBy('name')->get(['id', 'name'])->map(fn ($pipeline) => [
            'id' => (int) $pipeline->id,
            'name' => $pipeline->name,
            'stages' => ($stages[$pipeline->id] ?? collect())->map(fn ($stage) => ['id' => (int) $stage->id, 'name' => $stage->name, 'code' => $stage->code])->values()->all(),
        ])->values();

        $rules = AutomationRule::query()->orderBy('id')->get();
        $stats = RulePresenter::stats($rules->pluck('id')->all());

        $activityTypes = collect(RuleValidator::activityTypes())->map(fn ($type) => ['value' => $type, 'label' => trans('automations::app.activity_types.'.$type)])->values();

        $fields = collect(ConditionEvaluator::LEAD_COLUMNS)
            ->map(fn ($code) => ['code' => $code, 'name' => trans('automations::app.lead_columns.'.$code)])
            ->merge(DB::table('attributes')->where('entity_type', 'leads')->where('is_user_defined', 1)->orderBy('name')->get(['code', 'name'])->map(fn ($attribute) => ['code' => $attribute->code, 'name' => $attribute->name]))
            ->values();

        return view('automations::index', [
            'rules' => $rules->map(fn (AutomationRule $rule) => RulePresenter::present($rule, $stats[$rule->id] ?? []))->values(),
            'recipes' => Recipes::all($pipelines),
            'options' => [
                'pipelines' => $pipelines,
                'users' => DB::table('users')->where('status', 1)->orderBy('name')->get(['id', 'name']),
                'tags' => DB::table('tags')->orderBy('name')->get(['id', 'name']),
                'sources' => DB::table('lead_sources')->orderBy('name')->get(['id', 'name']),
                'types' => DB::table('lead_types')->orderBy('name')->get(['id', 'name']),
                'lost_reasons' => DB::table('lead_lost_reasons')->where('is_active', 1)->orderBy('sort_order')->get(['id', 'name', 'lead_pipeline_id']),
                'activity_types' => $activityTypes,
                'fields' => $fields,
                'max_nodes' => FlowValidator::maxNodes(),
            ],
            'whatsapp' => class_exists('Webkul\\WhatsApp\\Support\\AgentNotifier') && AgentNotifier::session() !== '',
            'can' => [
                'create' => bouncer()->hasPermission('settings.automation.automations.create'),
                'edit' => bouncer()->hasPermission('settings.automation.automations.edit'),
                'delete' => bouncer()->hasPermission('settings.automation.automations.delete'),
            ],
            'maxRules' => (int) config('automations.max_rules', 100),
            'urls' => [
                'store' => route('admin.automations.store'),
                'update' => route('admin.automations.update', ['id' => '__ID__']),
                'status' => route('admin.automations.status', ['id' => '__ID__']),
                'simulate' => route('admin.automations.simulate', ['id' => '__ID__']),
                'delete' => route('admin.automations.delete', ['id' => '__ID__']),
                'runs' => route('admin.automations.runs'),
                'lead' => route('admin.leads.view', ['id' => '__ID__']),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $max = (int) config('automations.max_rules', 100);

        abort_if(AutomationRule::query()->count() >= $max, 422, trans('automations::app.validation.max_rules', ['max' => $max]));

        $data = $this->validated($request);
        $rule = AutomationRule::query()->create($data + ['created_by' => auth()->guard('user')->id()]);

        return response()->json(['data' => RulePresenter::present($rule)]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $rule = AutomationRule::query()->findOrFail($id);
        $rule->update($this->validated($request, $rule));

        return response()->json(['data' => RulePresenter::present($rule->refresh())]);
    }

    public function status(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(AutomationRule::STATUSES)]]);

        $rule = AutomationRule::query()->findOrFail($id);

        // A flow saved as a draft cannot be turned on (or put in test) while it is invalid.
        if ($rule->isFlow() && $data['status'] !== AutomationRule::STATUS_OFF) {
            FlowValidator::assertValid(FlowValidator::sanitize((array) $rule->flow, (array) $rule->flow));
        }

        $rule->update(['status' => $data['status']]);

        return response()->json(['data' => RulePresenter::present($rule->refresh())]);
    }

    public function destroy(int $id): JsonResponse
    {
        AutomationRule::query()->findOrFail($id)->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * The visual builder sends "flow"; the old form (and old API clients) send trigger/conditions/actions.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?AutomationRule $existing = null): array
    {
        if ($request->has('flow')) {
            return FlowValidator::validateRule($request->all(), $existing);
        }

        $data = RuleValidator::validate($request->all(), $existing);

        // Saved in the old format (old API clients): the rule goes back to being an old rule, so a
        // stored flow is not left stale. Untouched when there is no flow (works before the migration).
        return $existing?->isFlow() ? $data + ['flow' => null] : $data;
    }

    /**
     * "Simular em um lead": what the rule would do on that lead now. Changes nothing.
     */
    public function simulate(Request $request, Engine $engine, int $id): JsonResponse
    {
        $data = $request->validate(['lead_id' => ['required', 'integer', 'min:1']]);

        $rule = AutomationRule::query()->findOrFail($id);
        $lead = $this->visibleLead((int) $data['lead_id']);

        abort_unless($lead, 404, trans('automations::app.simulate.not_found'));

        $run = $engine->simulate($rule, $lead);

        return response()->json(['data' => $this->presentRun($run, $rule->name, $lead->title)]);
    }

    /**
     * The history, newest first, filtered by rule, status and lead. Only runs on leads this user may see.
     */
    public function runs(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'rule_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(AutomationRun::STATUSES)],
            'lead_id' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = AutomationRun::query()
            ->leftJoin('automation_rules', 'automation_rules.id', '=', 'automation_runs.rule_id')
            ->leftJoin('leads', 'leads.id', '=', 'automation_runs.lead_id')
            ->when($filters['rule_id'] ?? null, fn ($q, $value) => $q->where('automation_runs.rule_id', $value))
            ->when($filters['status'] ?? null, fn ($q, $value) => $q->where('automation_runs.status', $value))
            ->when($filters['lead_id'] ?? null, fn ($q, $value) => $q->where('automation_runs.lead_id', $value))
            ->orderByDesc('automation_runs.id')
            ->select('automation_runs.*', 'automation_rules.name as rule_name', 'leads.title as lead_title');

        $this->restrictToVisibleLeads($query);

        $page = $query->paginate(50);

        return response()->json([
            'data' => collect($page->items())->map(fn (AutomationRun $run) => $this->presentRun($run, $run->rule_name, $run->lead_title))->values(),
            'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentRun(AutomationRun $run, ?string $ruleName, ?string $leadTitle): array
    {
        return [
            'id' => $run->id,
            'rule_id' => (int) $run->rule_id,
            'rule_name' => (string) $ruleName,
            'lead_id' => $run->lead_id ? (int) $run->lead_id : null,
            'lead_title' => (string) $leadTitle,
            'trigger' => $run->trigger,
            'status' => $run->status,
            'steps' => array_values((array) $run->steps),
            'error' => $run->error,
            'duration_ms' => (int) $run->duration_ms,
            'depth' => (int) $run->depth,
            'created_at' => optional($run->created_at)->format('d/m/Y H:i:s'),
        ];
    }

    private function visibleLead(int $id): ?Lead
    {
        $lead = Lead::query()->withoutGlobalScopes()->find($id);

        if (! $lead) {
            return null;
        }

        $userIds = bouncer()->getAuthorizedUserIds();
        $pipelineIds = bouncer()->getAllowedPipelineIds();

        if ($userIds !== null && ! in_array((int) $lead->user_id, array_map('intval', $userIds), true)) {
            return null;
        }

        if ($pipelineIds !== null && ! in_array((int) $lead->lead_pipeline_id, $pipelineIds, true)) {
            return null;
        }

        return $lead;
    }

    private function restrictToVisibleLeads($query): void
    {
        $userIds = bouncer()->getAuthorizedUserIds();
        $pipelineIds = bouncer()->getAllowedPipelineIds();

        if ($userIds !== null) {
            $query->whereIn('leads.user_id', $userIds);
        }

        if ($pipelineIds !== null) {
            $query->whereIn('leads.lead_pipeline_id', $pipelineIds);
        }
    }
}
