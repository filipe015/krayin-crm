<x-admin::layouts>
    <x-slot:title>
        @lang('goals::app.tracking.title')
    </x-slot>

    <v-goals-tracking
        :urls="{{ Illuminate\Support\Js::from($urls) }}"
        :labels="{{ Illuminate\Support\Js::from(trans('goals::app.tracking')) }}"
        initial-month="{{ $month }}"
        :can-edit="{{ $canEdit ? 'true' : 'false' }}"
    >
        <div class="p-6 text-sm text-gray-500">@lang('goals::app.tracking.loading')</div>
    </v-goals-tracking>

    @pushOnce('scripts')
        <style>
            .gt{--g-bg:#fff;--g-fg:#111827;--g-muted:#6b7280;--g-border:#e5e7eb;--g-soft:#f3f4f6;--g-accent:#0e90d9;--g-good:#10b981;--g-warn:#f59e0b;--g-bad:#e11d48;display:flex;flex-direction:column;gap:16px;color:var(--g-fg);font-size:14px}
            .dark .gt{--g-bg:#111827;--g-fg:#e5e7eb;--g-muted:#9ca3af;--g-border:#1f2937;--g-soft:#1f2937}
            .gt *{box-sizing:border-box}
            .gt h1{margin:0;font-size:20px;font-weight:700}
            .gt h2{margin:0 0 4px;font-size:15px;font-weight:600}
            .gt-sub{margin:2px 0 0;font-size:12px;color:var(--g-muted)}
            .gt-bar{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;justify-content:space-between}
            .gt-controls{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}
            .gt-field{display:flex;flex-direction:column;gap:3px;font-size:12px;font-weight:600;color:var(--g-muted)}
            .gt-field input,.gt-field select{padding:8px 10px;border:1px solid var(--g-border);border-radius:6px;background:var(--g-bg);color:var(--g-fg);font:inherit;font-weight:400}
            .gt-box{padding:16px 18px;border:1px solid var(--g-border);border-radius:10px;background:var(--g-bg)}
            .gt-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr));gap:12px}
            .gt-card h3{margin:0 0 10px;font-size:12px;letter-spacing:.04em;text-transform:uppercase;color:var(--g-muted);font-weight:600}
            .gt-item{margin-bottom:10px}
            .gt-item > div{display:flex;justify-content:space-between;gap:8px;font-size:13px}
            .gt-item b{font-weight:600}
            .gt-track{height:6px;border-radius:99px;background:var(--g-soft);overflow:hidden;margin-top:4px}
            .gt-track i{display:block;height:100%;background:var(--g-accent);border-radius:99px}
            .gt-table{width:100%;border-collapse:collapse}
            .gt-table th{padding:6px 10px;text-align:right;font-size:11px;letter-spacing:.03em;text-transform:uppercase;color:var(--g-muted);font-weight:600;border-bottom:1px solid var(--g-border)}
            .gt-table td{padding:9px 10px;text-align:right;border-bottom:1px solid var(--g-soft);white-space:nowrap}
            .gt-table th:first-child,.gt-table td:first-child{text-align:left}
            .gt-wrap{overflow-x:auto}
            .gt-dot{display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:6px;background:var(--g-border)}
            .gt-dot.is-good{background:var(--g-good)}.gt-dot.is-warn{background:var(--g-warn)}.gt-dot.is-bad{background:var(--g-bad)}
            .gt-text-good{color:var(--g-good)}.gt-text-warn{color:var(--g-warn)}.gt-text-bad{color:var(--g-bad)}
            .gt-empty{padding:28px;text-align:center;color:var(--g-muted)}
            .gt-empty a{color:var(--g-accent)}
            .gt-days{font-size:12px;color:var(--g-muted)}
            .gt-cost{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px}
            .gt-cost small{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:var(--g-muted)}
            .gt-cost b{font-size:18px}
        </style>

        <script
            type="text/x-template"
            id="v-goals-tracking-template"
        >
            <div class="gt">
                <div class="gt-bar">
                    <div>
                        <h1>@{{ labels.title }}</h1>
                        <p class="gt-sub">@{{ labels.subtitle }}</p>
                    </div>

                    <div class="gt-controls">
                        <label class="gt-field">
                            @{{ labels.funnel }}
                            <select v-model="funnel" @change="load">
                                <option value="">@{{ labels.general }}</option>
                                <option v-for="name in funnels" :key="name" :value="name">@{{ name }}</option>
                            </select>
                        </label>

                        <label class="gt-field">
                            @{{ labels.month }}
                            <input type="month" v-model="month" @change="load">
                        </label>
                    </div>
                </div>

                <div v-if="failed" class="gt-box gt-empty">@{{ labels.error }}</div>

                <div v-else-if="! data" class="gt-box gt-empty">@{{ labels.loading }}</div>

                <div v-else-if="! data.has_plan" class="gt-box gt-empty">
                    <p>@{{ labels.no_plan }}</p>
                    <a v-if="canEdit" :href="urls.settings">@{{ labels.set_plan }}</a>
                </div>

                <template v-else>
                    <div class="gt-cards">
                        <div v-for="name in ['day', 'week', 'month']" :key="name" class="gt-box gt-card">
                            <h3>@{{ labels.periods[name] }}</h3>

                            <div v-for="row in periodRows(name)" :key="row.step" class="gt-item">
                                <div>
                                    <span>@{{ row.label }}</span>
                                    <span><b>@{{ row.actual }}</b> / @{{ row.target }}</span>
                                </div>
                                <div class="gt-track"><i :style="{ width: Math.min(100, row.percent || 0) + '%' }"></i></div>
                            </div>
                        </div>
                    </div>

                    <div class="gt-box">
                        <h2>@{{ labels.pace }}</h2>
                        <p class="gt-sub">@{{ labels.pace_hint }}</p>
                        <p class="gt-days">@{{ daysLine }}</p>

                        <div class="gt-wrap">
                            <table class="gt-table">
                                <thead>
                                    <tr>
                                        <th>@{{ labels.col_step }}</th>
                                        <th>@{{ labels.col_goal }}</th>
                                        <th>@{{ labels.col_expected }}</th>
                                        <th>@{{ labels.col_actual }}</th>
                                        <th>@{{ labels.col_percent }}</th>
                                        <th>@{{ labels.col_projection }}</th>
                                        <th>@{{ labels.col_missing }}</th>
                                        <th>@{{ labels.col_per_day }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in paceRows" :key="row.step">
                                        <td><span class="gt-dot" :class="'is-' + row.light" :title="labels.light[row.light]"></span>@{{ row.label }}</td>
                                        <td>@{{ row.target }}</td>
                                        <td>@{{ row.expected }}</td>
                                        <td><b>@{{ row.actual }}</b></td>
                                        <td>@{{ row.percent }}</td>
                                        <td :class="'gt-text-' + row.projection_light">@{{ row.projection }}</td>
                                        <td>@{{ row.missing }}</td>
                                        <td>@{{ row.per_day }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="gt-cards">
                        <div class="gt-box">
                            <h2>@{{ labels.rates }}</h2>
                            <p class="gt-sub">@{{ labels.rates_hint }}</p>

                            <div class="gt-wrap"><table class="gt-table">
                                <thead>
                                    <tr><th></th><th>@{{ labels.col_real }}</th><th>@{{ labels.col_planned }}</th></tr>
                                </thead>
                                <tbody>
                                    <tr v-for="rate in data.rates" :key="rate.to">
                                        <td><span class="gt-dot" :class="'is-' + rate.light" :title="labels.light[rate.light]"></span>@{{ labels.steps[rate.from] }} → @{{ labels.steps[rate.to] }}</td>
                                        <td><b>@{{ percent(rate.actual) }}</b></td>
                                        <td>@{{ percent(rate.planned) }}</td>
                                    </tr>
                                </tbody>
                            </table></div>
                        </div>

                        <div class="gt-box">
                            <h2>@{{ labels.cost }}</h2>

                            <div class="gt-cost">
                                <div><small>@{{ labels.spend }}</small><b>@{{ money(data.cost.spend) }}</b></div>
                                <div><small>@{{ labels.budget }}</small><b>@{{ money(data.cost.budget) }}</b></div>
                                <div><small>@{{ labels.cpl }}</small><b :class="'gt-text-' + data.cost.cpl_light">@{{ money(data.cost.cpl) }}</b></div>
                                <div><small>@{{ labels.cpl_target }}</small><b>@{{ money(data.cost.cpl_target) }}</b></div>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </script>

        <script type="module">
            app.component('v-goals-tracking', {
                template: '#v-goals-tracking-template',

                props: ['urls', 'labels', 'initialMonth', 'canEdit'],

                data() {
                    return { month: this.initialMonth, funnel: '', data: null, failed: false };
                },

                computed: {
                    funnels() {
                        return this.data?.funnels ?? [];
                    },

                    daysLine() {
                        const days = this.data.working_days;

                        return this.labels.days_line.replace(':elapsed', days.elapsed).replace(':total', days.total).replace(':left', days.left);
                    },

                    // the funnel steps plus the revenue as one more row
                    paceRows() {
                        const rows = this.data.steps.map(row => ({ ...row, label: this.labels.steps[row.step] }));

                        rows.push({ ...this.data.revenue, step: 'revenue', label: this.labels.revenue });

                        return rows.map(row => {
                            const money = row.step === 'revenue';
                            const show = value => value === null || value === undefined ? '—' : (money ? this.money(value) : this.number(value));

                            return {
                                ...row,
                                target: show(row.target),
                                expected: show(row.expected),
                                actual: show(row.actual),
                                projection: show(row.projection),
                                missing: show(row.missing),
                                per_day: show(row.per_day),
                                percent: this.percent(row.percent),
                            };
                        });
                    },
                },

                mounted() {
                    this.load();
                },

                methods: {
                    load() {
                        this.failed = false;

                        this.$axios.get(this.urls.data, { params: { month: this.month, funnel: this.funnel } })
                            .then(response => {
                                this.data = response.data;
                            })
                            .catch(() => {
                                this.failed = true;
                            });
                    },

                    periodRows(name) {
                        const period = this.data.periods[name];

                        const rows = period.steps.map(row => ({ ...row, label: this.labels.steps[row.step], actual: this.number(row.actual), target: row.target === null ? '—' : this.number(row.target) }));

                        rows.push({ step: 'revenue', label: this.labels.revenue, percent: period.revenue.percent, actual: this.money(period.revenue.actual), target: period.revenue.target === null ? '—' : this.money(period.revenue.target) });

                        return rows;
                    },

                    number(value) {
                        return new Intl.NumberFormat('pt-BR').format(value ?? 0);
                    },

                    money(value) {
                        return value === null || value === undefined ? '—' : new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL', maximumFractionDigits: 0 }).format(value);
                    },

                    percent(value) {
                        return value === null || value === undefined ? '—' : value + '%';
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
