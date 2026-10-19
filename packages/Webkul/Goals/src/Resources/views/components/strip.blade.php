{{--
    Faixa de metas: the month's pace in one line, shown on the Anúncios and Início screens. Hides itself
    when there is no plan for the month or the person may not see the goals.
    Use <v-goals-strip :month="'2026-10'"></v-goals-strip> after including this file once per page.
--}}
@if (bouncer()->hasPermission('goals'))
    @pushOnce('scripts')
        <style>
            .gs{--g-bg:#fff;--g-fg:#111827;--g-muted:#6b7280;--g-border:#e5e7eb;--g-soft:#f3f4f6;--g-accent:#0e90d9;--g-good:#10b981;--g-warn:#f59e0b;--g-bad:#e11d48;padding:14px 16px;border:1px solid var(--g-border);border-radius:10px;background:var(--g-bg);color:var(--g-fg);font-size:13px}
            .dark .gs{--g-bg:#111827;--g-fg:#e5e7eb;--g-muted:#9ca3af;--g-border:#1f2937;--g-soft:#1f2937}
            .gs *{box-sizing:border-box}
            .gs-head{display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;align-items:baseline;margin-bottom:10px}
            .gs-head strong{font-size:14px}
            .gs-head span{color:var(--g-muted);font-size:12px}
            .gs-head a{color:var(--g-accent);font-size:12px;text-decoration:none}
            .gs-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px}
            .gs-item > div{display:flex;justify-content:space-between;gap:6px;align-items:baseline}
            .gs-item small{color:var(--g-muted);font-size:11px;text-transform:uppercase;letter-spacing:.03em}
            .gs-dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:5px;background:var(--g-border)}
            .gs-dot.is-good{background:var(--g-good)}.gs-dot.is-warn{background:var(--g-warn)}.gs-dot.is-bad{background:var(--g-bad)}
            .gs-track{height:5px;border-radius:99px;background:var(--g-soft);overflow:hidden;margin-top:4px}
            .gs-track i{display:block;height:100%;background:var(--g-accent);border-radius:99px}
        </style>

        <script type="text/x-template" id="v-goals-strip-template">
            <div class="gs" v-if="data && data.has_plan">
                <div class="gs-head">
                    <div><strong>@{{ title }}</strong> <span>· @{{ daysLine }}</span></div>
                    <a :href="page">@{{ labels.open }}</a>
                </div>

                <div class="gs-grid">
                    <div class="gs-item" v-for="item in items" :key="item.step">
                        <small><span class="gs-dot" :class="'is-' + item.light"></span>@{{ item.label }}</small>
                        <div>
                            <b>@{{ item.actual }}</b>
                            <span style="color:var(--g-muted)">/ @{{ item.target }}</span>
                        </div>
                        <div class="gs-track"><i :style="{ width: Math.min(100, item.percent || 0) + '%' }"></i></div>
                    </div>
                </div>
            </div>
        </script>

        <script type="module">
            app.component('v-goals-strip', {
                template: '#v-goals-strip-template',

                props: { month: { type: String, default: '' } },

                data() {
                    return {
                        data: null,
                        labels: @json(trans('goals::app.strip')),
                        steps: @json(trans('goals::app.tracking.steps')),
                        revenueLabel: @json(trans('goals::app.tracking.revenue')),
                        url: @json(route('admin.goals.data')),
                        page: @json(route('admin.goals.index')),
                    };
                },

                computed: {
                    title() {
                        return this.labels.title.replace(':month', new Intl.DateTimeFormat('pt-BR', { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(this.data.month + '-01T00:00:00Z')));
                    },

                    daysLine() {
                        const days = this.data.working_days;

                        return this.labels.days.replace(':elapsed', days.elapsed).replace(':total', days.total);
                    },

                    items() {
                        const money = value => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL', maximumFractionDigits: 0 }).format(value ?? 0);
                        const number = value => new Intl.NumberFormat('pt-BR').format(value ?? 0);

                        const rows = this.data.steps.map(row => ({ ...row, label: this.steps[row.step], actual: number(row.actual), target: number(row.target) }));

                        rows.push({ ...this.data.revenue, step: 'revenue', label: this.revenueLabel, actual: money(this.data.revenue.actual), target: money(this.data.revenue.target) });

                        return rows;
                    },
                },

                watch: {
                    month() {
                        this.load();
                    },
                },

                mounted() {
                    this.load();
                },

                methods: {
                    load() {
                        this.$axios.get(this.url, { params: { month: this.month || undefined } })
                            .then(response => {
                                this.data = response.data;
                            })
                            .catch(() => {
                                this.data = null;
                            });
                    },
                },
            });
        </script>
    @endPushOnce
@endif
