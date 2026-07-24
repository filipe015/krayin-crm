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
                <div class="flex items-center justify-between gap-2">
                    <p class="text-base font-semibold dark:text-gray-300">
                        Funil de vendas
                    </p>

                    <x-admin::dropdown position="bottom-right" :close-on-click="false">
                        <x-slot:toggle>
                            <button
                                type="button"
                                class="icon-filter cursor-pointer rounded-md p-1.5 text-2xl text-gray-600 transition-all hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-950"
                                aria-label="Selecionar etapas do funil"
                                title="Selecionar etapas"
                            ></button>
                        </x-slot>

                        <x-slot:content class="!w-[280px] !p-0">
                            <div class="px-3 py-2.5">
                                <span class="text-xs font-medium text-gray-500 dark:text-gray-300">
                                    Etapas do funil
                                </span>
                            </div>

                            <div class="max-h-72 overflow-y-auto border-t border-gray-300 py-1 dark:border-gray-800">
                                <label
                                    class="flex items-center gap-2 px-4 py-2"
                                    :class="isStageAvailable(stage)
                                        ? 'cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-950'
                                        : 'cursor-not-allowed bg-gray-50 opacity-50 dark:bg-gray-950'"
                                    v-for="stage in availableStages"
                                    :key="stage.name"
                                >
                                    <input
                                        type="checkbox"
                                        class="hidden"
                                        :value="stage.name"
                                        v-model="selectedStageNames"
                                        :disabled="! isStageAvailable(stage)"
                                        @change="handleStageSelection"
                                    />

                                    <span
                                        class="text-xl"
                                        :class="[
                                            selectedStageNames.includes(stage.name)
                                                ? 'icon-checkbox-select text-brandColor'
                                                : 'icon-checkbox-outline text-gray-500',
                                            {
                                                '!text-gray-300 dark:!text-gray-700': ! isStageAvailable(stage),
                                            }
                                        ]"
                                    ></span>

                                    <span
                                        class="flex min-w-0 flex-1 items-center justify-between gap-2 text-sm"
                                        :class="isStageAvailable(stage)
                                            ? 'text-gray-700 dark:text-gray-300'
                                            : 'text-gray-400 dark:text-gray-600'"
                                    >
                                        <span class="truncate">
                                            @{{ stage.name }}
                                        </span>

                                        <span class="shrink-0">
                                            @{{ stage.total }}
                                        </span>
                                    </span>
                                </label>
                            </div>
                        </x-slot>
                    </x-admin::dropdown>
                </div>

                <div
                    class="flex w-full items-stretch gap-4"
                    :style="{ height: Math.max(visibleStages.length * 58, 190) + 'px' }"
                    v-if="visibleStages.length"
                >
                    <div class="relative h-full w-1/2 min-w-0">
                        <canvas
                            :id="$.uid + '_chart'"
                            class="!h-full !w-full"
                        ></canvas>
                    </div>

                    <ul class="flex w-1/2 min-w-0 flex-col justify-center gap-2">
                        <li
                            class="min-w-0 border-b border-gray-300 pb-2 last:border-none last:pb-0 dark:border-gray-800"
                            v-for="stage in visibleStages"
                            :key="stage.name"
                        >
                            <span class="block truncate text-sm font-semibold text-gray-800 dark:text-gray-100">
                                @{{ stage.name }}
                            </span>

                            <span class="block text-xs font-medium text-gray-500 dark:text-gray-400">
                                @{{ stage.total }} leads • @{{ getStagePercentage(stage.total) }}%
                            </span>
                        </li>
                    </ul>
                </div>

                <div
                    class="grid min-h-44 place-items-center rounded-md bg-gray-50 p-4 text-center dark:bg-gray-950"
                    v-else
                >
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Selecione ao menos uma etapa com leads para visualizar o funil.
                    </p>
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

                    selectedStageNames: [],

                    isLoading: true,

                    chart: undefined,
                };
            },

            computed: {
                availableStages() {
                    return this.report.statistics;
                },

                visibleStages() {
                    return this.availableStages.filter(stage => {
                        return this.isStageAvailable(stage)
                            && this.selectedStageNames.includes(stage.name);
                    });
                },

                totalFunnelLeads() {
                    return this.visibleStages.reduce((total, stage) => {
                        return total + Number(stage.total);
                    }, 0);
                },
            },

            mounted() {
                this.getStats({});

                this.$emitter.on('reporting-filter-updated', this.getStats);
            },

            methods: {
                isStageAvailable(stage) {
                    return Number(stage.total) >= 1;
                },

                getStagePercentage(total) {
                    if (! this.totalFunnelLeads) {
                        return 0;
                    }

                    return Math.round((Number(total) / this.totalFunnelLeads) * 100);
                },

                handleStageSelection() {
                    this.$nextTick(() => {
                        this.prepare();
                    });
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

                            this.selectedStageNames = this.availableStages
                                .filter(stage => this.isStageAvailable(stage))
                                .map(stage => stage.name);

                            this.isLoading = false;

                            this.$nextTick(() => {
                                this.prepare();
                            });
                        })
                        .catch(() => {});
                },

                prepare() {
                    if (! this.visibleStages.length) {
                        if (this.chart) {
                            this.chart.destroy();

                            this.chart = undefined;
                        }

                        return;
                    }

                    const stages = this.visibleStages.map(stage => ({
                        name: stage.name,
                        total: Number(stage.total),
                    }));

                    const ctx = document.getElementById(this.$.uid + '_chart')?.getContext('2d');

                    if (! ctx) {
                        return;
                    }

                    if (this.chart && this.chart.canvas === ctx.canvas) {
                        this.chart.data.labels = stages.map(stage => stage.name);
                        this.chart.data.datasets[0].data = stages.map(stage => stage.total);
                        this.chart.update();

                        return;
                    }

                    if (this.chart) {
                        this.chart.destroy();
                    }

                    const gradient = ctx.createLinearGradient(0, 0, 0, Math.max(this.visibleStages.length * 58, 190));

                    gradient.addColorStop(0, 'rgba(144, 247, 236, 0.8)');
                    gradient.addColorStop(1, 'rgba(50, 204, 188, 1)');

                    this.chart = new Chart(ctx, {
                        type: 'funnel',

                        data: {
                            labels: stages.map(stage => stage.name),

                            datasets: [{
                                data: stages.map(stage => stage.total),
                                backgroundColor: gradient,
                                borderColor: 'rgba(0, 0, 0, 0)',
                                borderWidth: 0,
                            }],
                        },

                        options: {
                            indexAxis: 'y',
                            maintainAspectRatio: false,
                            responsive: true,

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
                                    display: false,
                                },

                                y: {
                                    display: false,
                                },
                            },
                        },
                    });
                },
            },
        });
    </script>
@endPushOnce
