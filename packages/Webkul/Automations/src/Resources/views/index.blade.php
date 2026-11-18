<x-admin::layouts>
    <x-slot:title>
        @lang('automations::app.ui.title')
    </x-slot>

    <v-automations
        :urls="{{ Illuminate\Support\Js::from($urls) }}"
        :labels="{{ Illuminate\Support\Js::from(trans('automations::app')) }}"
        :initial="{{ Illuminate\Support\Js::from($rules) }}"
        :recipes="{{ Illuminate\Support\Js::from($recipes) }}"
        :options="{{ Illuminate\Support\Js::from($options) }}"
        :can="{{ Illuminate\Support\Js::from($can) }}"
        :whatsapp="{{ Illuminate\Support\Js::from($whatsapp) }}"
        :max-rules="{{ (int) $maxRules }}"
    >
        <div class="p-6 text-sm text-gray-500">...</div>
    </v-automations>

    @pushOnce('scripts')
        <style>
            .au.is-wide{max-width:none}
            .au-pill.flow{background:#e0e7ff;color:#3730a3}
            .au{--au-bg:#fff;--au-fg:#111827;--au-muted:#6b7280;--au-border:#e5e7eb;--au-soft:#f3f4f6;--au-accent:#0e90d9;display:flex;flex-direction:column;gap:16px;max-width:1100px;color:var(--au-fg);font-size:14px}
            .dark .au{--au-bg:#111827;--au-fg:#e5e7eb;--au-muted:#9ca3af;--au-border:#1f2937;--au-soft:#1f2937}
            .au *{box-sizing:border-box}
            .au h1{margin:0;font-size:18px;font-weight:600}
            .au h2{margin:0;font-size:15px;font-weight:600}
            .au-card{display:flex;flex-direction:column;gap:12px;padding:16px;border:1px solid var(--au-border);border-radius:8px;background:var(--au-bg)}
            .au-muted{color:var(--au-muted);font-size:12px;margin:0}
            .au-row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}
            .au-row>.au-field{flex:1;min-width:170px}
            .au-field{display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:600;color:var(--au-muted)}
            .au-field input,.au-field select,.au-field textarea{width:100%;padding:7px 9px;border:1px solid var(--au-border);border-radius:6px;background:var(--au-bg);color:var(--au-fg);font:inherit;font-weight:400}
            .au-field textarea{min-height:70px}
            .au-check{display:flex;align-items:center;gap:8px;font-weight:400;color:var(--au-fg)}
            .au-btn{padding:6px 12px;border:1px solid var(--au-border);border-radius:6px;background:var(--au-bg);font:inherit;font-size:13px;font-weight:500;cursor:pointer;color:inherit;white-space:nowrap}
            .au-btn:hover{background:var(--au-soft)}
            .au-btn.is-primary{background:var(--au-accent);border-color:var(--au-accent);color:#fff}
            .au-btn.is-active{border-color:var(--au-accent);color:var(--au-accent)}
            .au-btn.is-small{padding:3px 8px;font-size:12px}
            .au-btn:disabled{opacity:.5;cursor:default}
            .au-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
            .au-tabs{display:flex;gap:4px;border-bottom:1px solid var(--au-border)}
            .au-tab{padding:8px 14px;border:0;border-bottom:2px solid transparent;background:none;font:inherit;cursor:pointer;color:var(--au-muted)}
            .au-tab.is-active{border-color:var(--au-accent);color:var(--au-fg);font-weight:600}
            .au-table{width:100%;border-collapse:collapse;font-size:13px}
            .au-table th,.au-table td{padding:8px;border-bottom:1px solid var(--au-border);text-align:left;vertical-align:top}
            .au-table th{font-size:12px;color:var(--au-muted);font-weight:600}
            .au-pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;background:var(--au-soft)}
            .au-pill.on,.au-pill.ok{background:#dcfce7;color:#14532d}
            .au-pill.test,.au-pill.dry_run{background:#fef9c3;color:#713f12}
            .au-pill.failed{background:#fee2e2;color:#991b1b}
            .au-pill.waiting{background:#cffafe;color:#155e75}
            .au-block{display:flex;flex-direction:column;gap:8px;padding:10px;border:1px dashed var(--au-border);border-radius:6px}
            .au-alert{padding:10px 12px;border-radius:6px;background:#fee2e2;color:#991b1b;font-size:13px}
            .au-warn{padding:10px 12px;border-radius:6px;background:#fef9c3;color:#713f12;font-size:13px}
            .au-ok{padding:10px 12px;border-radius:6px;background:#dcfce7;color:#14532d;font-size:13px}
            .au-steps{margin:0;padding-left:16px;font-size:12px}
            .au-recipes{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:10px}
            .au-recipe{display:flex;flex-direction:column;gap:6px;padding:12px;border:1px solid var(--au-border);border-radius:8px;cursor:pointer;background:var(--au-bg);text-align:left;font:inherit;color:inherit}
            .au-recipe:hover{border-color:var(--au-accent)}
            .au-scroll{overflow-x:auto}
        </style>

        <script type="text/x-template" id="v-automations-template">
            <div class="au" :class="{ 'is-wide': editing }">
                <div>
                    <h1>@{{ ui.title }}</h1>
                    <p class="au-muted">@{{ ui.intro }}</p>
                    <p class="au-muted" v-if="! can.create && ! can.edit">@{{ ui.read_only }}</p>
                </div>

                <div class="au-tabs">
                    <button type="button" class="au-tab" :class="{ 'is-active': tab === 'rules' }" @click="tab = 'rules'">@{{ ui.tab_rules }}</button>
                    <button type="button" class="au-tab" :class="{ 'is-active': tab === 'history' }" @click="openHistory()">@{{ ui.tab_history }}</button>
                </div>

                <!-- Rules -->
                <template v-if="tab === 'rules' && ! editing">
                    <div class="au-actions" v-if="can.create">
                        <button type="button" class="au-btn is-primary" :disabled="rules.length >= maxRules" @click="startNew()">@{{ ui.new }}</button>
                        <button type="button" class="au-btn" :disabled="rules.length >= maxRules" @click="showRecipes = ! showRecipes">@{{ ui.use_recipe }}</button>
                        <span class="au-muted">@{{ ui.limit.replace(':max', maxRules) }}</span>
                    </div>

                    <div class="au-card" v-if="showRecipes">
                        <h2>@{{ ui.recipes_title }}</h2>
                        <p class="au-muted">@{{ ui.recipes_hint }}</p>
                        <div class="au-recipes">
                            <button type="button" class="au-recipe" v-for="recipe in recipes" :key="recipe.key" @click="useRecipe(recipe)">
                                <strong>@{{ labels.recipes[recipe.key].name }}</strong>
                                <span class="au-muted">@{{ labels.recipes[recipe.key].description }}</span>
                            </button>
                        </div>
                    </div>

                    <div class="au-card au-scroll">
                        <p class="au-muted" v-if="! rules.length">@{{ ui.none }}</p>
                        <table class="au-table" v-else>
                            <thead>
                                <tr>
                                    <th>@{{ ui.name }}</th>
                                    <th>@{{ ui.trigger }}</th>
                                    <th>@{{ ui.status }}</th>
                                    <th>@{{ ui.runs }}</th>
                                    <th>@{{ ui.last_run }}</th>
                                    <th>@{{ labels.flow.ui.pending_waits }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="rule in rules" :key="rule.id">
                                    <td><strong>@{{ rule.name }}</strong> <span class="au-pill" :class="rule.is_flow ? 'flow' : ''">@{{ rule.is_flow ? labels.flow.ui.kind_flow : labels.flow.ui.kind_legacy }}</span></td>
                                    <td>@{{ labels.triggers[rule.trigger_type] }}</td>
                                    <td>
                                        <div class="au-actions">
                                            <button type="button" class="au-btn is-small" v-for="status in ['on', 'test', 'off']" :key="status"
                                                :class="{ 'is-active': rule.status === status }" :disabled="! can.edit || rule.busy" @click="setStatus(rule, status)">
                                                @{{ labels.statuses[status] }}
                                            </button>
                                        </div>
                                    </td>
                                    <td>@{{ rule.runs_count }}</td>
                                    <td>
                                        <span v-if="rule.last_run_at">@{{ formatDate(rule.last_run_at) }} <span class="au-pill" :class="rule.last_status">@{{ labels.run_statuses[rule.last_status] || rule.last_status }}</span></span>
                                        <span class="au-muted" v-else>@{{ ui.never }}</span>
                                    </td>
                                    <td>
                                        <div class="au-actions" v-if="rule.pending_waits">
                                            <span class="au-pill waiting">@{{ labels.flow.ui.pending_waits_count.replace(':count', rule.pending_waits) }}</span>
                                            <button type="button" class="au-btn is-small" v-if="can.edit" :disabled="rule.busy" @click="cancelWaits(rule)">@{{ labels.flow.ui.cancel_waits }}</button>
                                        </div>
                                        <span class="au-muted" v-else>—</span>
                                    </td>
                                    <td>
                                        <div class="au-actions">
                                            <button type="button" class="au-btn is-small" @click="edit(rule)">@{{ ui.edit }}</button>
                                            <button type="button" class="au-btn is-small" @click="historyOf(rule)">@{{ ui.tab_history }}</button>
                                            <button type="button" class="au-btn is-small" v-if="can.delete" :disabled="rule.busy" @click="remove(rule)">@{{ ui.delete }}</button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <div class="au-alert" v-if="listProblem">@{{ listProblem }}</div>
                        <div class="au-ok" v-if="listMessage">@{{ listMessage }}</div>
                    </div>
                </template>

                <!-- Construtor visual (app Vue isolado com Vue Flow, montado neste elemento) -->
                <div class="au-flow" v-if="tab === 'rules' && editing">
                    <div class="au-alert" v-if="flowProblem">@{{ flowProblem }}</div>
                    <p class="au-muted" v-else-if="! flowReady">@{{ labels.flow.ui.loading }}</p>
                    <div ref="flowHost" v-once></div>
                </div>

                <!-- History -->
                <template v-if="tab === 'history'">
                    <div class="au-card">
                        <div class="au-row">
                            <label class="au-field">@{{ ui.filter_rule }}
                                <select v-model="filters.rule_id">
                                    <option value="">@{{ ui.all }}</option>
                                    <option v-for="rule in rules" :key="rule.id" :value="rule.id">@{{ rule.name }}</option>
                                </select>
                            </label>
                            <label class="au-field">@{{ ui.filter_status }}
                                <select v-model="filters.status">
                                    <option value="">@{{ ui.all }}</option>
                                    <option v-for="(label, key) in labels.run_statuses" :key="key" :value="key">@{{ label }}</option>
                                </select>
                            </label>
                            <label class="au-field">@{{ ui.filter_lead }}<input type="number" min="1" v-model="filters.lead_id"></label>
                            <button type="button" class="au-btn is-primary" @click="loadRuns(1)">@{{ ui.filter }}</button>
                        </div>
                    </div>

                    <div class="au-card au-scroll">
                        <p class="au-muted" v-if="! runs.length">@{{ ui.empty_history }}</p>
                        <table class="au-table" v-else>
                            <thead>
                                <tr>
                                    <th>@{{ ui.when }}</th>
                                    <th>@{{ ui.filter_rule }}</th>
                                    <th>@{{ ui.lead }}</th>
                                    <th>@{{ ui.trigger }}</th>
                                    <th>@{{ ui.status }}</th>
                                    <th>@{{ ui.steps }}</th>
                                    <th>@{{ ui.duration }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="run in runs" :key="run.id">
                                    <td>@{{ run.created_at }}</td>
                                    <td>@{{ run.rule_name }}</td>
                                    <td><a v-if="run.lead_id" :href="urls.lead.replace('__ID__', run.lead_id)" target="_blank" class="text-brandColor">#@{{ run.lead_id }} @{{ run.lead_title }}</a></td>
                                    <td>@{{ labels.triggers[run.trigger] || run.trigger }}</td>
                                    <td><span class="au-pill" :class="run.status">@{{ labels.run_statuses[run.status] || run.status }}</span></td>
                                    <td>
                                        <ul class="au-steps">
                                            <li v-for="(step, i) in run.steps" :key="i">@{{ stepLabel(step) }}: @{{ step.detail }}</li>
                                        </ul>
                                        <div class="au-muted" v-if="run.error">@{{ run.error }}</div>
                                        <button type="button" class="au-btn is-small" v-if="run.steps.some(step => step.node) && rules.find(rule => rule.id === run.rule_id)" @click="showPath(run)">@{{ labels.flow.ui.view_in_flow }}</button>
                                    </td>
                                    <td>@{{ run.duration_ms }} ms</td>
                                </tr>
                            </tbody>
                        </table>
                        <div class="au-actions" v-if="meta.last_page > 1">
                            <button type="button" class="au-btn is-small" :disabled="meta.page <= 1" @click="loadRuns(meta.page - 1)">@{{ ui.previous }}</button>
                            <span class="au-muted">@{{ meta.page }} / @{{ meta.last_page }}</span>
                            <button type="button" class="au-btn is-small" :disabled="meta.page >= meta.last_page" @click="loadRuns(meta.page + 1)">@{{ ui.next }}</button>
                        </div>
                    </div>
                </template>
            </div>
        </script>

        {{-- Construtor visual: build próprio do módulo (Vue Flow), carregado só nesta tela. --}}
        {{ vite()->set(['src/Resources/assets/js/flow.js'], 'automations') }}

        <script type="module">
            app.component('v-automations', {
                template: '#v-automations-template',

                props: {
                    urls: { type: Object, required: true },
                    labels: { type: Object, required: true },
                    initial: { type: Array, default: () => [] },
                    recipes: { type: Array, default: () => [] },
                    options: { type: Object, required: true },
                    can: { type: Object, required: true },
                    whatsapp: { type: Boolean, default: false },
                    maxRules: { type: Number, default: 100 },
                },

                data() {
                    return {
                        tab: 'rules',
                        rules: this.initial.map(rule => ({ ...rule, busy: false })),
                        editing: null,
                        showRecipes: false,
                        listProblem: '',
                        listMessage: '',
                        flowReady: false,
                        flowProblem: '',
                        filters: { rule_id: '', status: '', lead_id: '' },
                        runs: [],
                        meta: { page: 1, last_page: 1, total: 0 },
                    };
                },

                computed: {
                    ui() {
                        return this.labels.ui;
                    },
                },

                beforeUnmount() {
                    this.unmountFlow();
                },

                methods: {
                    clone(value) {
                        return JSON.parse(JSON.stringify(value));
                    },

                    stepLabel(step) {
                        const action = String(step.action || '');

                        if (action.startsWith('condition:')) {
                            return this.labels.conditions[action.slice(10)] || action;
                        }

                        if (action === 'trigger') {
                            return this.labels.flow.blocks.trigger;
                        }

                        if (action === 'branch' || action === 'end') {
                            return this.labels.flow.blocks.condition;
                        }

                        if (action === 'time_check') {
                            return this.labels.flow.blocks.trigger;
                        }

                        if (action.startsWith('wait')) {
                            return this.labels.flow.blocks.wait;
                        }

                        return this.labels.actions[action] || action;
                    },

                    formatDate(value) {
                        const date = new Date(String(value).replace(' ', 'T'));

                        return isNaN(date) ? value : date.toLocaleString();
                    },

                    startNew() {
                        this.open({
                            id: null, name: '', status: 'off', run_once_per_lead: true, is_flow: true,
                            flow: { version: 1, nodes: [{ id: 'trigger', type: 'trigger', config: { trigger_type: 'stage_entered', pipeline_id: null, stage_id: null, from_stage_id: null }, position: { x: 0, y: 0 } }], edges: [] },
                        });
                    },

                    useRecipe(recipe) {
                        this.showRecipes = false;
                        this.open({ ...this.clone(recipe.rule), id: null, is_flow: true });
                    },

                    edit(rule, highlight = []) {
                        const { busy, runs_count, last_run_at, last_status, ...data } = this.clone(rule);

                        this.open(data, highlight);
                    },

                    showPath(run) {
                        const rule = this.rules.find(item => item.id === run.rule_id);

                        if (rule) {
                            this.tab = 'rules';
                            this.edit(rule, [...new Set(run.steps.map(step => step.node).filter(Boolean))]);
                        }
                    },

                    /**
                     * Opens the visual builder: an isolated Vue app (its own Vite build with Vue Flow)
                     * mounted in the empty element below. The bundle is a module script of this page,
                     * so it may still be loading on a slow connection: wait for its "ready" event.
                     */
                    open(data, highlight = []) {
                        this.unmountFlow();
                        this.editing = data;
                        this.flowProblem = '';
                        this.flowReady = false;

                        this.$nextTick(() => {
                            if (window.KrayinAutomationsFlow) {
                                return this.mountFlow(data, highlight);
                            }

                            const ready = () => this.editing === data && this.mountFlow(data, highlight);

                            window.addEventListener('krayin-automations-flow:ready', ready, { once: true });

                            setTimeout(() => {
                                if (! window.KrayinAutomationsFlow && this.editing === data) {
                                    this.flowProblem = this.labels.flow.ui.load_error;
                                }
                            }, 15000);
                        });
                    },

                    mountFlow(data, highlight) {
                        if (! this.$refs.flowHost) {
                            return;
                        }

                        this.flowEditor = window.KrayinAutomationsFlow.mount(this.$refs.flowHost, {
                            rule: data,
                            options: this.options,
                            labels: this.labels,
                            urls: this.urls,
                            can: this.can,
                            whatsapp: this.whatsapp,
                            maxNodes: this.options.max_nodes || 30,
                            maxWaitDays: this.options.max_wait_days || 30,
                            http: this.$axios,
                            highlight,
                            onSaved: (rule) => this.saved(rule),
                            onClose: () => this.closeEditor(),
                        });

                        this.flowReady = true;
                    },

                    unmountFlow() {
                        if (this.flowEditor) {
                            this.flowEditor.unmount();
                            this.flowEditor = null;
                        }
                    },

                    saved(rule) {
                        const item = { ...rule, busy: false };
                        const index = this.rules.findIndex(existing => existing.id === item.id);

                        index >= 0 ? this.rules.splice(index, 1, item) : this.rules.push(item);
                    },

                    closeEditor() {
                        this.unmountFlow();
                        this.editing = null;
                    },

                    errorOf(error) {
                        const body = error.response && error.response.data;

                        return (body && (Object.values(body.errors || {}).flat()[0] || body.message)) || this.ui.error;
                    },

                    async setStatus(rule, status) {
                        if (rule.status === status) {
                            return;
                        }

                        rule.busy = true;
                        this.listProblem = '';

                        try {
                            const response = await this.$axios.patch(this.urls.status.replace('__ID__', rule.id), { status });

                            rule.status = response.data.data.status;
                            rule.pending_waits = response.data.data.pending_waits || 0;
                        } catch (error) {
                            this.listProblem = rule.name + ': ' + this.errorOf(error);
                        } finally {
                            rule.busy = false;
                        }
                    },

                    /**
                     * Fase 2: "Cancelar esperas pendentes" of one flow (with confirmation).
                     */
                    async cancelWaits(rule) {
                        if (! window.confirm(this.labels.flow.ui.confirm_cancel_waits.replace(':count', rule.pending_waits))) {
                            return;
                        }

                        rule.busy = true;
                        this.listProblem = '';
                        this.listMessage = '';

                        try {
                            const response = await this.$axios.post(this.urls.cancel_waits.replace('__ID__', rule.id));

                            rule.pending_waits = response.data.data.pending_waits || 0;
                            this.listMessage = rule.name + ': ' + this.labels.flow.ui.waits_cancelled.replace(':count', response.data.cancelled_waits);
                        } catch (error) {
                            this.listProblem = rule.name + ': ' + this.errorOf(error);
                        } finally {
                            rule.busy = false;
                        }
                    },

                    async remove(rule) {
                        if (! window.confirm(this.ui.confirm_delete)) {
                            return;
                        }

                        rule.busy = true;

                        try {
                            await this.$axios.delete(this.urls.delete.replace('__ID__', rule.id));
                            this.rules = this.rules.filter(item => item.id !== rule.id);
                        } catch (error) {
                            rule.busy = false;
                            this.listProblem = this.errorOf(error);
                        }
                    },

                    openHistory() {
                        this.closeEditor();
                        this.tab = 'history';
                        this.loadRuns(1);
                    },

                    historyOf(rule) {
                        this.filters = { rule_id: rule.id, status: '', lead_id: '' };
                        this.openHistory();
                    },

                    async loadRuns(page) {
                        const params = { page };

                        Object.entries(this.filters).forEach(([key, value]) => { if (value !== '' && value !== null) params[key] = value; });

                        try {
                            const response = await this.$axios.get(this.urls.runs, { params });

                            this.runs = response.data.data;
                            this.meta = response.data.meta;
                        } catch (error) {
                            this.runs = [];
                        }
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
