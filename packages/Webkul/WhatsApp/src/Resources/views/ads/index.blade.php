<x-admin::layouts>
    <x-slot:title>
        @lang('whatsapp::app.ads_page.title')
    </x-slot>

    @include('goals::components.strip')

    <v-ads
        :urls="{{ Illuminate\Support\Js::from($urls) }}"
        :labels="{{ Illuminate\Support\Js::from(trans('whatsapp::app.ads_page')) }}"
        month="{{ $month }}"
        :can-edit="{{ $canEdit ? 'true' : 'false' }}"
    >
        <div class="p-6 text-sm text-gray-500">@lang('whatsapp::app.agenda.loading')</div>
    </v-ads>

    @pushOnce('scripts')
        {{-- Chart.js, the same build the dashboard uses. --}}
        <script type="module" src="{{ vite()->asset('js/chart.js') }}"></script>

        <style>
            .ads{--d-bg:#fff;--d-fg:#111827;--d-muted:#6b7280;--d-border:#e5e7eb;--d-soft:#f3f4f6;--d-accent:#0e90d9;--d-warn:#f59e0b;--d-good:#10b981;display:flex;flex-direction:column;gap:16px;color:var(--d-fg);font-size:14px}
            .dark .ads{--d-bg:#111827;--d-fg:#e5e7eb;--d-muted:#9ca3af;--d-border:#1f2937;--d-soft:#1f2937}
            .ads *{box-sizing:border-box}
            .ads h1{margin:0;font-size:20px;font-weight:700}
            .ads h2{margin:0;font-size:15px;font-weight:600}
            .ads-bar{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;justify-content:space-between}
            .ads-sub{margin:2px 0 0;font-size:12px;color:var(--d-muted)}
            .ads-filters{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}
            .ads-field{display:flex;flex-direction:column;gap:3px;font-size:12px;font-weight:600;color:var(--d-muted)}
            .ads-field input{padding:7px 9px;border:1px solid var(--d-border);border-radius:6px;background:var(--d-bg);color:var(--d-fg);font:inherit;font-weight:400}
            .ads-field input.is-narrow{width:84px}
            .ads-btn{padding:7px 12px;border:1px solid var(--d-border);border-radius:6px;background:var(--d-bg);color:inherit;font:inherit;font-size:13px;cursor:pointer}
            .ads-btn:disabled{opacity:.5;cursor:default}
            .ads-box{padding:16px 18px;border:1px solid var(--d-border);border-radius:10px;background:var(--d-bg)}
            .ads-box > header{margin-bottom:12px}
            .ads-kpis{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:16px 20px}
            .ads-kpi span{display:block;font-size:11px;letter-spacing:.04em;text-transform:uppercase;color:var(--d-muted)}
            .ads-kpi strong{display:block;margin-top:3px;font-size:20px;font-weight:700}
            .ads-kpi strong.is-accent{color:var(--d-accent)}
            .ads-kpi small{display:block;font-size:11px;color:var(--d-muted)}
            .ads-kpi strong small{display:inline;font-size:12px;font-weight:400}
            .ads-kpi small b{font-weight:600}
            .ads-part{margin-top:18px;padding-top:14px;border-top:1px solid var(--d-border)}
            .ads-part h3{margin:0 0 10px;font-size:11px;letter-spacing:.04em;text-transform:uppercase;color:var(--d-muted);font-weight:600}
            .ads-chart{position:relative;height:300px}
            .ads-wrap{overflow:auto}
            .ads table{width:100%;border-collapse:collapse;font-size:13px;white-space:nowrap}
            .ads th{text-align:right;padding:8px 10px;font-size:11px;letter-spacing:.03em;text-transform:uppercase;color:var(--d-muted);font-weight:600;border-bottom:1px solid var(--d-border)}
            .ads td{padding:9px 10px;text-align:right;border-bottom:1px solid var(--d-border)}
            .ads th:first-child,.ads td:first-child{text-align:left}
            .ads td:first-child{white-space:normal;min-width:200px}
            .ads tr.is-total td,.ads tr.is-current td{font-weight:600}
            .ads tr.is-total td{background:var(--d-soft)}
            .ads td.is-accent{color:var(--d-accent);font-weight:600}
            .ads-two{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px}
            .ads-bar-row{display:grid;grid-template-columns:130px 1fr 40px;gap:10px;align-items:center;margin-bottom:10px;font-size:13px}
            .ads-bar-row > div{height:8px;border-radius:4px;background:var(--d-soft);overflow:hidden}
            .ads-bar-row > div > i{display:block;height:100%;background:var(--d-accent)}
            .ads-bar-row b{text-align:right;font-weight:600}
            .ads-dot{display:inline-block;width:8px;height:8px;margin-right:6px;border-radius:50%;background:var(--d-accent)}
            .ads-note{padding:10px 12px;border:1px solid var(--d-border);border-radius:8px;font-size:12px;color:var(--d-muted)}
            .ads-empty{padding:24px;text-align:center;color:var(--d-muted)}
        </style>

        <script type="text/x-template" id="v-ads-template">
            <div class="ads">
                <div class="ads-bar">
                    <div>
                        <h1>@{{ labels.title }}</h1>
                        <p class="ads-sub" v-if="report">@{{ report.label }} · @{{ labels.subtitle }}</p>
                    </div>

                    <div class="ads-filters">
                        <label class="ads-field" v-if="canEdit && report">
                            @{{ labels.tax_percent }}
                            <input class="is-narrow" type="number" min="0" max="100" step="0.1" v-model="tax" @change="saveTax">
                        </label>

                        <label class="ads-field">
                            @{{ labels.month }}
                            <input type="month" v-model="current" @change="load">
                        </label>

                        <button type="button" class="ads-btn" :disabled="loading" @click="load">@{{ labels.refresh }}</button>
                    </div>
                </div>

                <p class="ads-note" v-if="problem">@{{ problem }}</p>

                @if (bouncer()->hasPermission('goals'))
                    <v-goals-strip :month="current" style="margin-bottom:16px;display:block"></v-goals-strip>
                @endif

                <template v-if="report">
                    <!-- Consolidated view -->
                    <section class="ads-box">
                        <header>
                            <h2>@{{ labels.consolidated }}</h2>
                            <p class="ads-sub">@{{ labels.consolidated_hint }}</p>
                        </header>

                        <div class="ads-kpis">
                            <div class="ads-kpi"><span>@{{ labels.spend }}</span><strong>@{{ money(s.spend) }}</strong></div>
                            <div class="ads-kpi" v-if="report.tax_percent > 0"><span>@{{ labels.tax }}</span><strong>@{{ money(s.tax) }}</strong><small>@{{ report.tax_percent }}%</small></div>
                            <div class="ads-kpi" v-if="report.tax_percent > 0"><span>@{{ labels.total_cost }}</span><strong>@{{ money(s.total_cost) }}</strong></div>
                            <div class="ads-kpi"><span>@{{ labels.leads_meta }}</span><strong>@{{ s.leads_meta }}</strong></div>
                            <div class="ads-kpi"><span>@{{ labels.leads_crm }}</span><strong>@{{ s.leads_crm }}</strong></div>
                            <div class="ads-kpi"><span>MQL</span><strong>@{{ s.mql }}</strong></div>
                            <div class="ads-kpi"><span>@{{ labels.qualification_rate }}</span><strong>@{{ percent(s.qualification_rate) }}</strong></div>
                            <div class="ads-kpi"><span>@{{ labels.scheduled }}</span><strong>@{{ s.scheduled }}</strong></div>
                            <div class="ads-kpi"><span>@{{ labels.disqualified }}</span><strong>@{{ s.disqualified }}</strong></div>
                            <div class="ads-kpi"><span>@{{ labels.cpl_meta }}</span><strong class="is-accent">@{{ money(s.cpl_meta) }}</strong></div>
                            <div class="ads-kpi"><span>@{{ labels.cpl_crm }}</span><strong class="is-accent">@{{ money(s.cpl_crm) }}</strong></div>
                            <div class="ads-kpi"><span>@{{ labels.cost_scheduled }}</span><strong class="is-accent">@{{ money(s.cost_per_scheduled) }}</strong></div>
                            <div class="ads-kpi"><span>@{{ labels.lead_to_scheduled }}</span><strong>@{{ percent(s.lead_to_scheduled) }}</strong></div>
                            <div class="ads-kpi"><span>@{{ labels.sales }}</span><strong>@{{ s.won }}</strong></div>
                            <div class="ads-kpi"><span>@{{ labels.cac }}</span><strong class="is-accent">@{{ money(s.cac) }}</strong></div>
                            <div class="ads-kpi"><span>@{{ labels.revenue }}</span><strong>@{{ money(s.won_value) }}</strong></div>
                            <div class="ads-kpi"><span>@{{ labels.ticket }}</span><strong>@{{ money(s.ticket) }}</strong></div>
                            <div class="ads-kpi"><span>@{{ labels.roas }}</span><strong>@{{ ratio(s.roas) }}</strong></div>
                            <div class="ads-kpi"><span>@{{ labels.no_utm }}</span><strong>@{{ s.no_utm }}</strong></div>
                            <div class="ads-kpi" v-if="s.unmatched"><span>@{{ labels.unmatched }}</span><strong>@{{ s.unmatched }}</strong></div>
                        </div>

                        <!-- Leads and cost per lead of each funnel -->
                        <div class="ads-part" v-if="report.funnels.length">
                            <h3>@{{ labels.funnel_leads }}</h3>

                            <div class="ads-kpis">
                                <div class="ads-kpi" v-for="funnel in report.funnels" :key="'k' + funnel.name">
                                    <span>@{{ funnelName(funnel) }}</span>
                                    <strong>@{{ funnel.leads_meta }} <small>leads</small></strong>
                                    <small>CPL <b class="is-accent">@{{ money(funnel.cpl_meta) }}</b> · @{{ money(funnel.spend) }}</small>
                                </div>
                            </div>
                        </div>

                        <div class="ads-part" v-if="report.funnels.length">
                            <h3>@{{ labels.by_funnel }}</h3>

                            <div class="ads-wrap">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>@{{ labels.funnel }}</th>
                                            <th>@{{ labels.spend }}</th>
                                            <th>@{{ labels.leads_meta }}</th>
                                            <th>@{{ labels.cpl_meta }}</th>
                                            <th>@{{ labels.leads_crm }}</th>
                                            <th>MQL</th>
                                            <th>@{{ labels.scheduled }}</th>
                                            <th>@{{ labels.cost_scheduled }}</th>
                                            <th>@{{ labels.sales }}</th>
                                            <th>@{{ labels.cac }}</th>
                                            <th>@{{ labels.revenue }}</th>
                                            <th>@{{ labels.roas }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="funnel in report.funnels" :key="funnel.name">
                                            <td>@{{ funnelName(funnel) }}</td>
                                            <td>@{{ money(funnel.spend) }}</td>
                                            <td>@{{ funnel.leads_meta }}</td>
                                            <td class="is-accent">@{{ money(funnel.cpl_meta) }}</td>
                                            <td>@{{ funnel.leads_crm }}</td>
                                            <td>@{{ funnel.mql }}</td>
                                            <td>@{{ funnel.scheduled }}</td>
                                            <td class="is-accent">@{{ money(funnel.cost_per_scheduled) }}</td>
                                            <td>@{{ funnel.won }}</td>
                                            <td class="is-accent">@{{ money(funnel.cac) }}</td>
                                            <td>@{{ money(funnel.won_value) }}</td>
                                            <td>@{{ ratio(funnel.roas) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <p class="ads-sub" style="margin-top:14px">@{{ labels.definitions }}</p>
                    </section>

                    <!-- Month-by-month trend -->
                    <section class="ads-box">
                        <header>
                            <h2>@{{ labels.trend }}</h2>
                            <p class="ads-sub">@{{ labels.trend_hint }}</p>
                        </header>

                        <div class="ads-chart"><canvas ref="trendChart"></canvas></div>

                        <div class="ads-wrap" style="margin-top:14px">
                            <table>
                                <thead>
                                    <tr>
                                        <th>@{{ labels.month }}</th>
                                        <th>@{{ labels.spend }}</th>
                                        <th v-if="report.tax_percent > 0">@{{ labels.tax }}</th>
                                        <th v-if="report.tax_percent > 0">@{{ labels.total_cost }}</th>
                                        <th>@{{ labels.leads_meta }}</th>
                                        <th>@{{ labels.cpl_meta }}</th>
                                        <th>@{{ labels.leads_crm }}</th>
                                        <th>@{{ labels.cpl_crm }}</th>
                                        <th>MQL</th>
                                        <th>@{{ labels.scheduled }}</th>
                                        <th>@{{ labels.cost_scheduled }}</th>
                                        <th>@{{ labels.sales }}</th>
                                        <th>@{{ labels.cac }}</th>
                                        <th>@{{ labels.revenue }}</th>
                                        <th>@{{ labels.roas }}</th>
                                        <th>@{{ labels.disqualified }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in trendRows" :key="row.month" :class="{ 'is-current': row.month === report.month }">
                                        <td>@{{ row.label }}</td>
                                        <td>@{{ money(row.spend) }}</td>
                                        <td v-if="report.tax_percent > 0">@{{ money(row.tax) }}</td>
                                        <td v-if="report.tax_percent > 0">@{{ money(row.total_cost) }}</td>
                                        <td>@{{ row.leads_meta }}</td>
                                        <td class="is-accent">@{{ money(row.cpl_meta) }}</td>
                                        <td>@{{ row.leads_crm }}</td>
                                        <td class="is-accent">@{{ money(row.cpl_crm) }}</td>
                                        <td>@{{ row.mql }}</td>
                                        <td>@{{ row.scheduled }}</td>
                                        <td class="is-accent">@{{ money(row.cost_per_scheduled) }}</td>
                                        <td>@{{ row.won }}</td>
                                        <td class="is-accent">@{{ money(row.cac) }}</td>
                                        <td>@{{ money(row.won_value) }}</td>
                                        <td>@{{ ratio(row.roas) }}</td>
                                        <td>@{{ row.disqualified }}</td>
                                    </tr>
                                    <tr class="is-total">
                                        <td>@{{ labels.total_average }}</td>
                                        <td>@{{ money(trendTotal.spend) }}</td>
                                        <td v-if="report.tax_percent > 0">@{{ money(trendTotal.tax) }}</td>
                                        <td v-if="report.tax_percent > 0">@{{ money(trendTotal.total_cost) }}</td>
                                        <td>@{{ trendTotal.leads_meta }}</td>
                                        <td class="is-accent">@{{ money(trendTotal.cpl_meta) }}</td>
                                        <td>@{{ trendTotal.leads_crm }}</td>
                                        <td class="is-accent">@{{ money(trendTotal.cpl_crm) }}</td>
                                        <td>@{{ trendTotal.mql }}</td>
                                        <td>@{{ trendTotal.scheduled }}</td>
                                        <td class="is-accent">@{{ money(trendTotal.cost_per_scheduled) }}</td>
                                        <td>@{{ trendTotal.won }}</td>
                                        <td class="is-accent">@{{ money(trendTotal.cac) }}</td>
                                        <td>@{{ money(trendTotal.won_value) }}</td>
                                        <td>@{{ ratio(trendTotal.roas) }}</td>
                                        <td>@{{ trendTotal.disqualified }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <!-- Daily -->
                    <section class="ads-box">
                        <header>
                            <h2>@{{ labels.daily }}</h2>
                            <p class="ads-sub">@{{ labels.daily_hint }}</p>
                        </header>

                        <div class="ads-chart"><canvas ref="dailyChart"></canvas></div>
                    </section>

                    <div class="ads-two">
                        <!-- By platform -->
                        <section class="ads-box">
                            <header><h2>@{{ labels.by_platform }}</h2></header>

                            <div class="ads-wrap" v-if="report.platforms.length">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>@{{ labels.platform }}</th>
                                            <th>@{{ labels.spend }}</th>
                                            <th>@{{ labels.clicks }}</th>
                                            <th>@{{ labels.leads }}</th>
                                            <th>CPL</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="platform in report.platforms" :key="platform.platform">
                                            <td><span class="ads-dot"></span>@{{ platformName(platform.platform) }}</td>
                                            <td>@{{ money(platform.spend) }}</td>
                                            <td>@{{ number(platform.clicks) }}</td>
                                            <td>@{{ platform.leads_meta }}</td>
                                            <td class="is-accent">@{{ money(platform.cpl) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <p class="ads-empty" v-else>@{{ labels.no_data }}</p>
                        </section>

                        <!-- Lead origins -->
                        <section class="ads-box">
                            <header>
                                <h2>@{{ labels.origins }}</h2>
                                <p class="ads-sub">@{{ labels.origins_hint }}</p>
                            </header>

                            <div v-if="report.origins.length">
                                <div class="ads-bar-row" v-for="origin in report.origins" :key="origin.source">
                                    <span>@{{ origin.source }}</span>
                                    <div><i :style="{ width: (origin.count / maxOrigin * 100) + '%' }"></i></div>
                                    <b>@{{ origin.count }}</b>
                                </div>
                            </div>

                            <p class="ads-empty" v-else>@{{ labels.no_data }}</p>
                        </section>
                    </div>

                    <!-- Campaigns -->
                    <section class="ads-box">
                        <header><h2>@{{ labels.campaigns }}</h2></header>

                        <div class="ads-wrap" v-if="report.campaigns.length">
                            <table>
                                <thead>
                                    <tr>
                                        <th>@{{ labels.campaign }}</th>
                                        <th>@{{ labels.funnel }}</th>
                                        <th>@{{ labels.spend }}</th>
                                        <th>@{{ labels.clicks }}</th>
                                        <th>@{{ labels.leads_meta }}</th>
                                        <th>@{{ labels.cpl_meta }}</th>
                                        <th>@{{ labels.leads_crm }}</th>
                                        <th>@{{ labels.sales }}</th>
                                        <th>@{{ labels.revenue }}</th>
                                        <th>@{{ labels.roas }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="campaign in report.campaigns" :key="campaign.campaign">
                                        <td>@{{ campaign.campaign || '—' }}</td>
                                        <td>@{{ campaign.funnel || '—' }}</td>
                                        <td>@{{ money(campaign.spend) }}</td>
                                        <td>@{{ number(campaign.clicks) }}</td>
                                        <td>@{{ campaign.leads_meta }}</td>
                                        <td class="is-accent">@{{ money(campaign.cpl) }}</td>
                                        <td>@{{ campaign.leads_crm }}</td>
                                        <td>@{{ campaign.won }}</td>
                                        <td>@{{ money(campaign.won_value) }}</td>
                                        <td>@{{ ratio(campaign.roas) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <p class="ads-empty" v-else>@{{ labels.empty }}</p>
                    </section>

                    <p class="ads-note">@{{ labels.method }}<template v-if="report.last_sync"> @{{ labels.synced }} @{{ report.last_sync }}.</template></p>
                </template>

                <p class="ads-empty" v-else-if="! problem">@{{ labels.loading }}</p>
            </div>
        </script>

        <script type="module">
            const PALETTE = ['#0e90d9', '#f59e0b', '#10b981', '#8b5cf6', '#ec4899', '#14b8a6'];

            app.component('v-ads', {
                template: '#v-ads-template',

                props: {
                    urls: { type: Object, required: true },
                    labels: { type: Object, required: true },
                    month: { type: String, required: true },
                    canEdit: { type: Boolean, default: false },
                },

                data() {
                    return { report: null, current: this.month, tax: 0, problem: '', loading: false, charts: {} };
                },

                computed: {
                    s() {
                        return this.report.summary;
                    },

                    // Newest month first, like the table of the cockpit this screen follows.
                    trendRows() {
                        return [...this.report.trend].reverse();
                    },

                    trendTotal() {
                        const rows = this.report.trend;
                        const sum = key => rows.reduce((total, row) => total + (row[key] || 0), 0);
                        const spend = sum('spend');
                        const leadsMeta = sum('leads_meta');
                        const leadsCrm = sum('leads_crm');
                        const scheduled = sum('scheduled');
                        const won = sum('won');
                        const per = (amount, count) => count > 0 ? amount / count : null;

                        return {
                            spend,
                            tax: sum('tax'),
                            total_cost: sum('total_cost'),
                            leads_meta: leadsMeta,
                            leads_crm: leadsCrm,
                            mql: sum('mql'),
                            scheduled,
                            won,
                            disqualified: sum('disqualified'),
                            cac: per(spend, won),
                            won_value: sum('won_value'),
                            roas: spend > 0 && won > 0 ? sum('won_value') / spend : null,
                            cpl_meta: per(spend, leadsMeta),
                            cpl_crm: per(spend, leadsCrm),
                            cost_per_scheduled: per(spend, scheduled),
                        };
                    },

                    maxOrigin() {
                        return Math.max(1, ...this.report.origins.map(origin => origin.count));
                    },
                },

                mounted() {
                    this.load();
                },

                beforeUnmount() {
                    Object.values(this.charts).forEach(chart => chart.destroy());
                },

                methods: {
                    money(value) {
                        return value === null || value === undefined ? '—' : Number(value).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL', maximumFractionDigits: 2 });
                    },

                    number(value) {
                        return Number(value || 0).toLocaleString('pt-BR');
                    },

                    ratio(value) {
                        return value === null || value === undefined ? '—' : Number(value).toLocaleString('pt-BR', { maximumFractionDigits: 2 }) + 'x';
                    },

                    percent(value) {
                        return value === null || value === undefined ? '—' : Number(value).toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + '%';
                    },

                    funnelName(funnel) {
                        return funnel.name || this.labels.other_funnel;
                    },

                    platformName(platform) {
                        const names = { instagram: 'Instagram', facebook: 'Facebook', messenger: 'Messenger', audience_network: 'Audience Network', threads: 'Threads' };

                        return platform ? (names[platform] || platform) : this.labels.unknown_platform;
                    },

                    async load() {
                        this.problem = '';
                        this.loading = true;

                        try {
                            const { data } = await this.$axios.get(this.urls.overview, { params: { month: this.current } });

                            this.report = data;
                            this.tax = data.tax_percent;

                            this.$nextTick(() => this.draw());
                        } catch (error) {
                            this.problem = this.labels.error;
                        } finally {
                            this.loading = false;
                        }
                    },

                    async saveTax() {
                        try {
                            await this.$axios.put(this.urls.settings, { tax_percent: Number(this.tax) || 0 });
                            await this.load();
                        } catch (error) {
                            this.problem = this.labels.error;
                        }
                    },

                    chart(name, canvas, config) {
                        if (this.charts[name]) {
                            this.charts[name].destroy();
                        }

                        if (canvas && window.Chart) {
                            this.charts[name] = new window.Chart(canvas, config);
                        }
                    },

                    draw() {
                        if (! window.Chart) {
                            // The chart library loads as a module; try again until it is there.
                            setTimeout(() => this.report && this.draw(), 300);

                            return;
                        }

                        const text = getComputedStyle(this.$el).getPropertyValue('--d-muted').trim() || '#6b7280';
                        const grid = getComputedStyle(this.$el).getPropertyValue('--d-border').trim() || '#e5e7eb';
                        const money = value => Number(value).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL', maximumFractionDigits: 0 });
                        const common = { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, plugins: { legend: { labels: { color: text, boxWidth: 12 } } } };
                        const axis = (position, { noGrid = false, ticks = {} } = {}) => ({ position, beginAtZero: true, ticks: { color: text, ...ticks }, grid: { color: noGrid ? 'transparent' : grid } });

                        // Month by month: bars = investment, line = Meta cost per lead.
                        const months = this.report.trend;

                        this.chart('trend', this.$refs.trendChart, {
                            data: {
                                labels: months.map(row => row.label),
                                datasets: [
                                    { type: 'bar', label: this.labels.spend, data: months.map(row => row.spend), backgroundColor: PALETTE[0], yAxisID: 'y', order: 3 },
                                    { type: 'bar', label: this.labels.revenue, data: months.map(row => row.won_value), backgroundColor: PALETTE[2], yAxisID: 'y', order: 2 },
                                    { type: 'line', label: this.labels.cpl_meta, data: months.map(row => row.cpl_meta), borderColor: PALETTE[1], backgroundColor: PALETTE[1], yAxisID: 'y1', tension: 0.3, spanGaps: true, order: 1 },
                                ],
                            },
                            options: {
                                ...common,
                                scales: {
                                    x: { ticks: { color: text }, grid: { display: false } },
                                    y: axis('left', { ticks: { callback: money } }),
                                    y1: axis('right', { noGrid: true, ticks: { callback: money } }),
                                },
                            },
                        });

                        // Daily: bars = investment, lines = Meta leads (one per funnel, plus the total).
                        const daily = this.report.daily;
                        const datasets = [
                            { type: 'bar', label: this.labels.spend, data: daily.days.map(day => day.spend), backgroundColor: PALETTE[0], yAxisID: 'y', order: 5 },
                            ...daily.funnels.map((name, index) => ({
                                type: 'line', label: name, data: daily.days.map(day => day.by_funnel[name] || 0),
                                borderColor: PALETTE[(index + 1) % PALETTE.length], backgroundColor: PALETTE[(index + 1) % PALETTE.length],
                                yAxisID: 'y1', tension: 0.3, order: 2,
                            })),
                            { type: 'line', label: this.labels.total_leads, data: daily.days.map(day => day.total), borderColor: text, backgroundColor: text, borderDash: [5, 4], yAxisID: 'y1', tension: 0.3, pointRadius: 2, order: 1 },
                        ];

                        this.chart('daily', this.$refs.dailyChart, {
                            data: { labels: daily.days.map(day => day.date.slice(8, 10) + '/' + day.date.slice(5, 7)), datasets },
                            options: {
                                ...common,
                                scales: {
                                    x: { ticks: { color: text }, grid: { display: false } },
                                    y: axis('left', { ticks: { callback: money } }),
                                    y1: axis('right', { noGrid: true, ticks: { precision: 0 } }),
                                },
                            },
                        });
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
