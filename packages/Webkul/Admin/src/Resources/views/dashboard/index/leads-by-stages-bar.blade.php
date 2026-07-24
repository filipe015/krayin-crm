<v-dashboard-leads-by-stages-bar>
    <x-admin::shimmer.dashboard.index.open-leads-by-states />
</v-dashboard-leads-by-stages-bar>

@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-dashboard-leads-by-stages-bar-template"
    >
        <template v-if="isLoading">
            <x-admin::shimmer.dashboard.index.open-leads-by-states />
        </template>

        <template v-else>
            <div class="grid gap-4 rounded-lg border border-gray-300 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="text-base font-semibold dark:text-gray-300">
                    Leads por etapa
                </p>

                <div
                    class="relative w-full"
                    v-if="stages.length"
                    :style="{ height: Math.max(stages.length * 58, 220) + 'px' }"
                >
                    <canvas :id="$.uid + '_chart'"></canvas>
                </div>

                <div
                    class="grid justify-center justify-items-center gap-3.5 p-4"
                    v-else
                >
                    <img
                        src="{{ vite()->asset('images/empty-placeholders/default.svg') }}"
                        class="dark:mix-blend-exclusion dark:invert"
                    >

                    <p class="text-center text-gray-400">
                        Nenhum lead encontrado no período.
                    </p>
                </div>
            </div>
        </template>
    </script>

    <script type="module">
        app.component('v-dashboard-leads-by-stages-bar', {
            template: '#v-dashboard-leads-by-stages-bar-template',

            data() {
                return {
                    report: {
                        statistics: [],
                    },

                    isLoading: true,

                    chart: undefined,
                };
            },

            computed: {
                stages() {
                    return this.report.statistics
                        .filter(stat => Number(stat.total) >= 1)
                        .sort((firstStage, secondStage) => {
                            return Number(secondStage.total) - Number(firstStage.total);
                        });
                },

                totalLeads() {
                    return this.stages.reduce((total, stage) => {
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
                    if (! this.totalLeads) {
                        return 0;
                    }

                    return Math.round((Number(total) / this.totalLeads) * 100);
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

                    if (! this.stages.length) {
                        return;
                    }

                    const valueLabels = {
                        id: 'leadsByStagesValueLabels',

                        afterDatasetsDraw: chart => {
                            const { ctx, chartArea } = chart;
                            const bars = chart.getDatasetMeta(0).data;

                            ctx.save();
                            ctx.font = '600 12px sans-serif';
                            ctx.textBaseline = 'middle';

                            bars.forEach((bar, index) => {
                                const total = Number(this.stages[index].total);
                                const label = `${total} leads • ${this.getStagePercentage(total)}%`;
                                const labelWidth = ctx.measureText(label).width;
                                const hasOutsideSpace = bar.x + labelWidth + 8 <= chartArea.right;

                                ctx.fillStyle = hasOutsideSpace
                                    ? (document.documentElement.classList.contains('dark') ? '#f3f4f6' : '#374151')
                                    : '#ffffff';

                                ctx.fillText(
                                    label,
                                    hasOutsideSpace ? bar.x + 8 : Math.max(chartArea.left + 4, bar.x - labelWidth - 8),
                                    bar.y
                                );
                            });

                            ctx.restore();
                        },
                    };

                    this.chart = new Chart(document.getElementById(this.$.uid + '_chart'), {
                        type: 'bar',

                        data: {
                            labels: this.stages.map(stage => stage.name),

                            datasets: [{
                                data: this.stages.map(stage => Number(stage.total)),
                                backgroundColor: 'rgba(50, 204, 188, 0.85)',
                                borderColor: 'rgba(50, 204, 188, 1)',
                                borderWidth: 1,
                                borderRadius: 4,
                                barPercentage: 0.8,
                                categoryPercentage: 0.75,
                            }],
                        },

                        plugins: [
                            valueLabels,
                        ],

                        options: {
                            indexAxis: 'y',
                            maintainAspectRatio: false,
                            responsive: true,

                            layout: {
                                padding: {
                                    right: 12,
                                },
                            },

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

                            scales: {
                                x: {
                                    beginAtZero: true,

                                    ticks: {
                                        precision: 0,
                                    },
                                },

                                y: {
                                    grid: {
                                        display: false,
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
