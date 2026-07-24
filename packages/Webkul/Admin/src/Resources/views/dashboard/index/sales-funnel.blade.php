<v-dashboard-sales-funnel>
    <x-admin::shimmer.dashboard.index.open-leads-by-states />
</v-dashboard-sales-funnel>

@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-dashboard-sales-funnel-template"
    >
        <template v-if="isLoading">
            <x-admin::shimmer.dashboard.index.open-leads-by-states />
        </template>

        <template v-else>
            <div class="grid gap-4 rounded-lg border border-gray-300 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="text-base font-semibold dark:text-gray-300">
                    Funil de vendas
                </p>

                <div class="relative flex h-[220px] w-full max-w-full flex-col">
                    <canvas
                        :id="$.uid + '_chart'"
                        class="w-full max-w-full items-end px-12"
                    ></canvas>

                    <ul class="absolute flex w-full flex-col">
                        <li
                            class="flex h-[50px] w-full flex-col justify-center border-b border-gray-300 last:border-none dark:border-gray-800"
                            v-for="stage in funnelStages"
                        >
                            <span class="text-sm font-semibold dark:text-gray-100">
                                @{{ stage.total }} leads • @{{ getStagePercentage(stage.total) }}%
                            </span>

                            <span class="text-sm font-semibold dark:text-gray-100">
                                @{{ stage.name }}
                            </span>
                        </li>
                    </ul>
                </div>
            </div>
        </template>
    </script>

    <script type="module">
        app.component('v-dashboard-sales-funnel', {
            template: '#v-dashboard-sales-funnel-template',

            data() {
                return {
                    report: {
                        statistics: [],
                    },

                    funnelStageNames: [
                        'Novo Lead',
                        'Respondeu 1º Contato',
                        'Consulta Agendada',
                        'Consulta Realizada',
                    ],

                    isLoading: true,

                    chart: undefined,
                };
            },

            computed: {
                funnelStages() {
                    return this.funnelStageNames.map(stageName => {
                        return this.report.statistics.find(stage => stage.name === stageName) ?? {
                            name: stageName,
                            total: 0,
                        };
                    });
                },

                totalFunnelLeads() {
                    return this.funnelStages.reduce((total, stage) => {
                        return total + Number(stage.total);
                    }, 0);
                },
            },

            mounted() {
                this.getStats({});

                this.$emitter.on('reporting-filter-updated', this.getStats);
            },

            methods: {
                getStagePercentage(total) {
                    if (! this.totalFunnelLeads) {
                        return 0;
                    }

                    return Math.round((Number(total) / this.totalFunnelLeads) * 100);
                },

                getStats(filters) {
                    this.isLoading = true;

                    filters = Object.assign({}, filters, {
                        type: 'open-leads-by-states',
                    });

                    this.$axios.get("{{ route('admin.dashboard.stats') }}", {
                            params: filters,
                        })
                        .then(response => {
                            this.report = response.data;

                            this.isLoading = false;

                            setTimeout(() => {
                                this.prepare();
                            }, 0);
                        })
                        .catch(() => {});
                },

                prepare() {
                    if (this.chart) {
                        this.chart.destroy();
                    }

                    const ctx = document.getElementById(this.$.uid + '_chart')?.getContext('2d');
                    const gradient = ctx.createLinearGradient(0, 0, 0, 220);

                    gradient.addColorStop(0, 'rgba(144, 247, 236, 0.8)');
                    gradient.addColorStop(1, 'rgba(50, 204, 188, 1)');

                    this.chart = new Chart(ctx, {
                        type: 'funnel',

                        data: {
                            labels: this.funnelStages.map(stage => stage.name),

                            datasets: [{
                                data: this.funnelStages.map(stage => Number(stage.total)),
                                backgroundColor: gradient,
                                borderColor: 'rgba(0, 0, 0, 0)',
                                borderWidth: 0,
                            }],
                        },

                        options: {
                            indexAxis: 'y',
                            maintainAspectRatio: false,

                            plugins: {
                                legend: {
                                    display: false,
                                },

                                tooltip: {
                                    callbacks: {
                                        label: context => {
                                            const total = Number(context.raw);

                                            return `${total} leads • ${this.getStagePercentage(total)}%`;
                                        },
                                    },
                                },
                            },
                        },
                    });
                },
            },
        });
    </script>
@endPushOnce
