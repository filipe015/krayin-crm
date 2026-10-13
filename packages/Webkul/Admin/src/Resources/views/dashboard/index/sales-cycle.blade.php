{!! view_render_event('admin.dashboard.index.sales_cycle.before') !!}

<!-- Sales cycle: days from the lead's creation to closing or to the first appointment -->
<v-dashboard-sales-cycle>
    <x-admin::shimmer.dashboard.index.total-leads />
</v-dashboard-sales-cycle>

{!! view_render_event('admin.dashboard.index.sales_cycle.after') !!}

@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-dashboard-sales-cycle-template"
    >
        <div class="grid gap-4 rounded-lg border border-gray-300 bg-white px-4 py-2 dark:border-gray-800 dark:bg-gray-900">
            <div class="flex items-center justify-between gap-2">
                <p class="text-base font-semibold dark:text-gray-300">
                    @lang('admin::app.dashboard.index.sales-cycle.title')
                </p>

                <!-- Mode switch -->
                <div class="flex overflow-hidden rounded-md border border-gray-300 text-xs dark:border-gray-700">
                    <button
                        v-for="item in modes"
                        :key="item.key"
                        type="button"
                        class="px-2.5 py-1 transition-all"
                        :style="mode === item.key ? 'background:#0E90D9;color:#fff' : ''"
                        :class="mode === item.key ? '' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800'"
                        @click="setMode(item.key)"
                    >
                        @{{ item.label }}
                    </button>
                </div>
            </div>

            <template v-if="isLoading">
                <x-admin::shimmer.dashboard.index.total-leads />
            </template>

            <template v-else>
                <!-- Summary -->
                <div class="flex flex-wrap gap-6">
                    <div>
                        <p class="text-2xl font-semibold dark:text-white">@{{ days(report.average) }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">@lang('admin::app.dashboard.index.sales-cycle.average')</p>
                    </div>

                    <div>
                        <p class="text-2xl font-semibold dark:text-white">@{{ days(report.median) }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">@lang('admin::app.dashboard.index.sales-cycle.median')</p>
                    </div>

                    <div>
                        <p class="text-2xl font-semibold dark:text-white">@{{ report.total }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">@{{ mode === 'closing' ? "@lang('admin::app.dashboard.index.sales-cycle.total-closing')" : "@lang('admin::app.dashboard.index.sales-cycle.total-scheduling')" }}</p>
                    </div>
                </div>

                <p v-if="! report.total" class="pb-4 text-sm text-gray-500 dark:text-gray-400">
                    @{{ mode === 'closing' ? "@lang('admin::app.dashboard.index.sales-cycle.empty-closing')" : "@lang('admin::app.dashboard.index.sales-cycle.empty-scheduling')" }}
                </p>

                <!-- Bar Chart -->
                <div v-else class="flex w-full max-w-full flex-col gap-4">
                    <x-admin::charts.bar
                        ::labels="chartLabels"
                        ::datasets="chartDatasets"
                    />

                    <div class="flex justify-center gap-5">
                        <div class="flex items-center gap-2">
                            <span class="h-3.5 w-3.5 rounded-sm bg-[#8979FF]"></span>

                            <p class="text-xs dark:text-gray-300">@lang('admin::app.dashboard.index.sales-cycle.average')</p>
                        </div>

                        <div class="flex items-center gap-2">
                            <span class="h-3.5 w-3.5 rounded-sm bg-[#63CFE5]"></span>

                            <p class="text-xs dark:text-gray-300">@lang('admin::app.dashboard.index.sales-cycle.median')</p>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </script>

    <script type="module">
        app.component('v-dashboard-sales-cycle', {
            template: '#v-dashboard-sales-cycle-template',

            data() {
                return {
                    report: { average: 0, median: 0, total: 0, over_time: [] },

                    filters: {},

                    mode: 'closing',

                    modes: [
                        { key: 'closing', label: "@lang('admin::app.dashboard.index.sales-cycle.mode-closing')" },
                        { key: 'scheduling', label: "@lang('admin::app.dashboard.index.sales-cycle.mode-scheduling')" },
                    ],

                    isLoading: true,
                }
            },

            computed: {
                chartLabels() {
                    return this.report.over_time.map(({ label }) => label);
                },

                chartDatasets() {
                    return [{
                        data: this.report.over_time.map(({ average }) => average),
                        barThickness: 24,
                        backgroundColor: '#8979FF',
                    }, {
                        data: this.report.over_time.map(({ median }) => median),
                        barThickness: 24,
                        backgroundColor: '#63CFE5',
                    }];
                },
            },

            mounted() {
                try {
                    const saved = window.localStorage.getItem('dashboard-sales-cycle-mode');

                    if (['closing', 'scheduling'].includes(saved)) {
                        this.mode = saved;
                    }
                } catch (error) {}

                this.getStats({});

                this.$emitter.on('reporting-filter-updated', this.getStats);
            },

            methods: {
                days(value) {
                    return Number(value).toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + ' ' + "@lang('admin::app.dashboard.index.sales-cycle.days')";
                },

                setMode(mode) {
                    if (this.mode === mode) {
                        return;
                    }

                    this.mode = mode;

                    try {
                        window.localStorage.setItem('dashboard-sales-cycle-mode', mode);
                    } catch (error) {}

                    this.getStats(this.filters);
                },

                getStats(filters) {
                    this.isLoading = true;

                    this.filters = Object.assign({}, filters);

                    const params = Object.assign({}, this.filters, { type: 'sales-cycle', mode: this.mode });

                    this.$axios.get("{{ route('admin.dashboard.stats') }}", { params })
                        .then(response => {
                            this.report = response.data.statistics;

                            this.isLoading = false;
                        })
                        .catch(error => {
                            this.isLoading = false;
                        });
                },
            },
        });
    </script>
@endPushOnce
