<x-admin::layouts>
    <x-slot:title>
        @lang('whatsapp::app.ads_page.title')
    </x-slot>

    <v-ads
        :urls="{{ Illuminate\Support\Js::from($urls) }}"
        :labels="{{ Illuminate\Support\Js::from(trans('whatsapp::app.ads_page')) }}"
        start="{{ $start }}"
        end="{{ $end }}"
    >
        <div class="p-6 text-sm text-gray-500">@lang('whatsapp::app.agenda.loading')</div>
    </v-ads>

    @pushOnce('scripts')
        <style>
            .ads{--d-bg:#fff;--d-fg:#111827;--d-muted:#6b7280;--d-border:#e5e7eb;--d-soft:#f3f4f6;--d-accent:#0e90d9;display:flex;flex-direction:column;gap:14px;color:var(--d-fg);font-size:14px}
            .dark .ads{--d-bg:#111827;--d-fg:#e5e7eb;--d-muted:#9ca3af;--d-border:#1f2937;--d-soft:#1f2937}
            .ads *{box-sizing:border-box}
            .ads h1{margin:0;font-size:18px;font-weight:600}
            .ads-bar{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;justify-content:space-between}
            .ads-filters{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}
            .ads-field{display:flex;flex-direction:column;gap:3px;font-size:12px;font-weight:600;color:var(--d-muted)}
            .ads-field input{padding:7px 9px;border:1px solid var(--d-border);border-radius:6px;background:var(--d-bg);color:var(--d-fg);font:inherit;font-weight:400}
            .ads-seg{display:flex;border:1px solid var(--d-border);border-radius:6px;overflow:hidden}
            .ads-seg button{padding:7px 12px;border:0;background:var(--d-bg);color:inherit;font:inherit;font-size:13px;cursor:pointer}
            .ads-seg button.is-on{background:var(--d-accent);color:#fff}
            .ads-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:10px}
            .ads-card{padding:12px 14px;border:1px solid var(--d-border);border-radius:8px;background:var(--d-bg)}
            .ads-card strong{display:block;font-size:20px;font-weight:600}
            .ads-card span{font-size:12px;color:var(--d-muted)}
            .ads-wrap{overflow:auto;border:1px solid var(--d-border);border-radius:8px;background:var(--d-bg)}
            .ads table{width:100%;border-collapse:collapse;font-size:13px;white-space:nowrap}
            .ads th{position:sticky;top:0;text-align:right;padding:8px 10px;background:var(--d-soft);font-size:12px;color:var(--d-muted);font-weight:600;border-bottom:1px solid var(--d-border)}
            .ads th:first-child,.ads td:first-child{text-align:left}
            .ads td{padding:8px 10px;text-align:right;border-bottom:1px solid var(--d-border)}
            .ads td:first-child{white-space:normal;min-width:240px;max-width:420px}
            .ads tr.is-total td{font-weight:600;background:var(--d-soft)}
            .ads-sub{display:block;font-size:11px;color:var(--d-muted)}
            .ads-note{padding:10px 12px;border:1px solid var(--d-border);border-radius:8px;font-size:12px;color:var(--d-muted)}
            .ads-empty{padding:28px;text-align:center;color:var(--d-muted)}
        </style>

        <script type="text/x-template" id="v-ads-template">
            <div class="ads">
                <div class="ads-bar">
                    <h1>@{{ labels.title }}</h1>

                    <div class="ads-filters">
                        <label class="ads-field">@{{ labels.from }}<input type="date" v-model="range.start" @change="load"></label>
                        <label class="ads-field">@{{ labels.to }}<input type="date" v-model="range.end" @change="load"></label>

                        <div class="ads-seg">
                            <button type="button" :class="{ 'is-on': level === 'campaign' }" @click="setLevel('campaign')">@{{ labels.campaigns }}</button>
                            <button type="button" :class="{ 'is-on': level === 'adset' }" @click="setLevel('adset')">@{{ labels.adsets }}</button>
                            <button type="button" :class="{ 'is-on': level === 'ad' }" @click="setLevel('ad')">@{{ labels.ads }}</button>
                        </div>
                    </div>
                </div>

                <p class="ads-note" v-if="problem">@{{ problem }}</p>

                <template v-if="report">
                    <div class="ads-cards">
                        <div class="ads-card"><strong>@{{ money(report.totals.spend) }}</strong><span>@{{ labels.spend }}</span></div>
                        <div class="ads-card"><strong>@{{ report.totals.leads }}</strong><span>@{{ labels.leads }}</span></div>
                        <div class="ads-card"><strong>@{{ money(report.totals.cpl) }}</strong><span>@{{ labels.cpl }}</span></div>
                        <div class="ads-card"><strong>@{{ report.totals.scheduled }}</strong><span>@{{ labels.scheduled }}</span></div>
                        <div class="ads-card"><strong>@{{ money(report.totals.cost_per_scheduled) }}</strong><span>@{{ labels.cost_scheduled }}</span></div>
                        <div class="ads-card"><strong>@{{ report.totals.won }}</strong><span>@{{ labels.won }}</span></div>
                        <div class="ads-card"><strong>@{{ money(report.totals.cost_per_won) }}</strong><span>@{{ labels.cost_won }}</span></div>
                        <div class="ads-card"><strong>@{{ ratio(report.totals.roas) }}</strong><span>@{{ labels.roas }}</span></div>
                    </div>

                    <div class="ads-wrap" v-if="report.rows.length">
                        <table>
                            <thead>
                                <tr>
                                    <th>@{{ levelLabel }}</th>
                                    <th>@{{ labels.spend }}</th>
                                    <th v-for="label in report.result_labels" :key="label">@{{ label }} <span class="ads-sub">Meta</span></th>
                                    <th>@{{ labels.leads }} <span class="ads-sub">CRM</span></th>
                                    <th>@{{ labels.cpl }}</th>
                                    <th>@{{ labels.scheduled }}</th>
                                    <th>@{{ labels.cost_scheduled }}</th>
                                    <th>@{{ labels.won }}</th>
                                    <th>@{{ labels.cost_won }}</th>
                                    <th>@{{ labels.revenue }}</th>
                                    <th>@{{ labels.roas }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(row, index) in report.rows" :key="index">
                                    <td>
                                        @{{ name(row) }}
                                        <span class="ads-sub" v-if="level !== 'campaign'">@{{ parents(row) }}</span>
                                        <span class="ads-sub">@{{ number(row.impressions) }} @{{ labels.impressions }} · @{{ number(row.clicks) }} @{{ labels.clicks }}<template v-if="row.ctr !== null"> · CTR @{{ row.ctr }}%</template></span>
                                    </td>
                                    <td>@{{ money(row.spend) }}</td>
                                    <td v-for="label in report.result_labels" :key="label">@{{ number(row.meta_results[label] || 0) }}</td>
                                    <td>@{{ row.leads }}</td>
                                    <td>@{{ money(row.cpl) }}</td>
                                    <td>@{{ row.scheduled }}<span class="ads-sub" v-if="row.lead_to_scheduled !== null">@{{ row.lead_to_scheduled }}%</span></td>
                                    <td>@{{ money(row.cost_per_scheduled) }}</td>
                                    <td>@{{ row.won }}<span class="ads-sub" v-if="row.scheduled_to_won !== null">@{{ row.scheduled_to_won }}% @{{ labels.of_scheduled }}</span></td>
                                    <td>@{{ money(row.cost_per_won) }}</td>
                                    <td>@{{ money(row.won_value) }}</td>
                                    <td>@{{ ratio(row.roas) }}</td>
                                </tr>
                                <tr class="is-total">
                                    <td>@{{ labels.total }}</td>
                                    <td>@{{ money(report.totals.spend) }}</td>
                                    <td v-for="label in report.result_labels" :key="label">@{{ number(report.totals.meta_results[label] || 0) }}</td>
                                    <td>@{{ report.totals.leads }}</td>
                                    <td>@{{ money(report.totals.cpl) }}</td>
                                    <td>@{{ report.totals.scheduled }}</td>
                                    <td>@{{ money(report.totals.cost_per_scheduled) }}</td>
                                    <td>@{{ report.totals.won }}</td>
                                    <td>@{{ money(report.totals.cost_per_won) }}</td>
                                    <td>@{{ money(report.totals.won_value) }}</td>
                                    <td>@{{ ratio(report.totals.roas) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p class="ads-empty" v-else>@{{ labels.empty }}</p>

                    <p class="ads-note" v-if="report.crm_without_match.leads || report.crm_without_utm.leads">
                        @{{ labels.outside }}
                        <strong v-if="report.crm_without_match.leads">@{{ report.crm_without_match.leads }} @{{ labels.unmatched }}</strong><template v-if="report.crm_without_match.leads && report.crm_without_utm.leads"> · </template>
                        <strong v-if="report.crm_without_utm.leads">@{{ report.crm_without_utm.leads }} @{{ labels.no_utm }}</strong>
                    </p>

                    <p class="ads-note">@{{ labels.method }}<template v-if="report.last_sync"> @{{ labels.synced }} @{{ report.last_sync }}.</template></p>
                </template>

                <p class="ads-empty" v-else-if="! problem">@{{ labels.loading }}</p>
            </div>
        </script>

        <script type="module">
            app.component('v-ads', {
                template: '#v-ads-template',

                props: {
                    urls: { type: Object, required: true },
                    labels: { type: Object, required: true },
                    start: { type: String, required: true },
                    end: { type: String, required: true },
                },

                data() {
                    return { report: null, level: 'campaign', problem: '', range: { start: this.start, end: this.end } };
                },

                computed: {
                    levelLabel() {
                        return { campaign: this.labels.campaign, adset: this.labels.adset, ad: this.labels.ad }[this.level];
                    },
                },

                mounted() {
                    this.load();
                },

                methods: {
                    money(value) {
                        return value === null || value === undefined ? '—' : Number(value).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
                    },

                    number(value) {
                        return Number(value || 0).toLocaleString('pt-BR');
                    },

                    ratio(value) {
                        return value === null || value === undefined ? '—' : Number(value).toLocaleString('pt-BR', { maximumFractionDigits: 2 }) + 'x';
                    },

                    name(row) {
                        return (this.level === 'ad' ? row.ad : this.level === 'adset' ? row.adset : row.campaign) || '—';
                    },

                    parents(row) {
                        return this.level === 'ad' ? [row.campaign, row.adset].filter(Boolean).join(' › ') : (row.campaign || '');
                    },

                    setLevel(level) {
                        this.level = level;
                        this.load();
                    },

                    async load() {
                        this.problem = '';

                        try {
                            const { data } = await this.$axios.get(this.urls.data, { params: { start: this.range.start, end: this.range.end, level: this.level } });

                            this.report = data;
                        } catch (error) {
                            this.problem = this.labels.error;
                        }
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
