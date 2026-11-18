{{--
    Troca rápida de funil e etapa de um lead (componente Vue <v-lead-pipeline-move>).

    - variant="icon": botão "⋯" no card do Kanban que abre um painel flutuante (no celular, uma folha
      no rodapé da tela). O painel é teleportado para o <body>: não fica preso na rolagem da coluna,
      não dispara o link do card e não interfere no arrastar.
    - variant="inline": seletores Funil e Etapa no topo da página do lead, junto da barra de etapas.

    Mesma regra nos dois: trocar só a etapa no mesmo funil é direto; trocar de funil mostra as etapas do
    novo funil (a primeira já escolhida) e pede para confirmar; Ganho/Perdido e risco de o lead sair da
    visão do usuário pedem confirmação explícita. Ao terminar emite "moved" com a resposta do servidor.
--}}
@pushOnce('scripts')
    <script type="text/x-template" id="v-lead-pipeline-move-template">
        <div
            class="lead-move"
            :class="variant === 'inline' ? 'w-full' : 'inline-flex'"
        >
            <!-- Botão do card (Kanban) -->
            <button
                v-if="variant === 'icon'"
                ref="trigger"
                type="button"
                class="lead-move-trigger icon-more flex cursor-pointer items-center justify-center rounded p-1 text-xl text-gray-600 transition-all hover:bg-gray-200 hover:text-gray-800 dark:text-gray-700 dark:hover:bg-gray-800 dark:hover:text-white"
                title="@lang('admin::app.lead-move.trigger')"
                aria-label="@lang('admin::app.lead-move.trigger')"
                :aria-expanded="isOpen ? 'true' : 'false'"
                @pointerdown.stop
                @mousedown.stop
                @touchstart.stop
                @click.stop.prevent="toggle"
            ></button>

            <!-- Painel flutuante (card) -->
            <Teleport to="body">
                <div
                    v-if="variant === 'icon' && isOpen"
                    ref="panel"
                    class="flex flex-col gap-3 border border-gray-300 bg-white p-4 shadow-2xl dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                    :class="isMobile ? 'rounded-t-xl' : 'rounded-lg'"
                    :style="panelStyle"
                    role="dialog"
                    @click.stop
                    @keydown.esc="close"
                >
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-sm font-semibold">@lang('admin::app.lead-move.title')</p>

                        <button
                            type="button"
                            class="icon-cross-large cursor-pointer rounded p-1 text-lg text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800"
                            aria-label="@lang('admin::app.lead-move.cancel-btn')"
                            @click="close"
                        ></button>
                    </div>

                    <template v-if="isLoading">
                        <p class="text-sm text-gray-500 dark:text-gray-300">@lang('admin::app.lead-move.loading')</p>
                    </template>

                    <template v-else-if="loadError">
                        <p class="text-sm text-red-600">@lang('admin::app.lead-move.load-error')</p>
                    </template>

                    <template v-else>
                        <div class="flex flex-col gap-3">
                            <label class="flex flex-col gap-1 text-xs font-medium text-gray-600 dark:text-gray-300">
                                @lang('admin::app.lead-move.pipeline')

                                <select
                                    class="w-full rounded border border-gray-300 bg-white px-2 py-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                                    :value="pipelineId"
                                    @change="choosePipeline($event.target.value)"
                                >
                                    <option v-for="pipeline in pipelines" :key="pipeline.id" :value="pipeline.id">
                                        @{{ pipeline.name }}@{{ pipeline.id === current.pipeline_id ? ' (' + texts.current + ')' : '' }}
                                    </option>
                                </select>
                            </label>

                            <label class="flex flex-col gap-1 text-xs font-medium text-gray-600 dark:text-gray-300">
                                <span class="flex items-center gap-1">
                                    @lang('admin::app.lead-move.stage')

                                    <span
                                        class="inline-block h-2 w-2 rounded-full"
                                        :style="{ background: stageColor(selectedStage) }"
                                    ></span>
                                </span>

                                <select
                                    class="w-full rounded border border-gray-300 bg-white px-2 py-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                                    :value="stageId"
                                    @change="chooseStage($event.target.value)"
                                >
                                    <option v-for="stage in stages" :key="stage.id" :value="stage.id">
                                        @{{ stageLabel(stage) }}
                                    </option>
                                </select>
                            </label>
                        </div>

                        <p v-if="pipelineChanged" class="text-xs text-gray-600 dark:text-gray-300">
                            @lang('admin::app.lead-move.pipeline-change-hint')
                        </p>

                        <div
                            v-if="involvesClosed"
                            class="flex flex-col gap-2 rounded border border-amber-300 bg-amber-50 p-2 text-xs text-amber-900 dark:border-amber-700 dark:bg-gray-800 dark:text-amber-200"
                        >
                            <p>@lang('admin::app.lead-move.closed-warning')</p>

                            <label class="flex cursor-pointer items-center gap-2 font-medium">
                                <input type="checkbox" v-model="confirmClosed" />

                                @lang('admin::app.lead-move.closed-confirm')
                            </label>
                        </div>

                        <p
                            v-if="visibilityRisk"
                            class="rounded border border-amber-300 bg-amber-50 p-2 text-xs text-amber-900 dark:border-amber-700 dark:bg-gray-800 dark:text-amber-200"
                        >
                            @lang('admin::app.lead-move.visibility-warning')
                        </p>

                        <label
                            v-if="targetIsLost"
                            class="flex flex-col gap-1 text-xs font-medium text-gray-600 dark:text-gray-300"
                        >
                            @lang('admin::app.lead-move.lost-reason')

                            <select
                                v-if="lostReasons.length"
                                v-model="lostReasonId"
                                class="w-full rounded border border-gray-300 bg-white px-2 py-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                            >
                                <option value="">@lang('admin::app.lead-move.lost-reason-choose')</option>

                                <option v-for="reason in lostReasons" :key="reason.id" :value="reason.id">
                                    @{{ reason.name }}
                                </option>
                            </select>

                            <textarea
                                v-else
                                v-model="lostReason"
                                rows="2"
                                class="w-full rounded border border-gray-300 bg-white px-2 py-1 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                            ></textarea>
                        </label>

                        <p v-if="errorMessage" class="text-xs text-red-600">@{{ errorMessage }}</p>

                        <div v-if="needsConfirmation" class="flex items-center justify-end gap-2">
                            <button type="button" class="secondary-button" @click="close">
                                @lang('admin::app.lead-move.cancel-btn')
                            </button>

                            <button
                                type="button"
                                class="primary-button"
                                :disabled="! canSubmit || isSaving"
                                :class="{ 'opacity-50 cursor-not-allowed': ! canSubmit || isSaving }"
                                @click="submit"
                            >
                                @lang('admin::app.lead-move.confirm-btn')
                            </button>
                        </div>
                    </template>
                </div>
            </Teleport>

            <!-- Seletores no topo da página do lead -->
            <div
                v-if="variant === 'inline'"
                class="flex w-full flex-col gap-2 rounded-lg border border-gray-200 bg-white p-2 dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                :class="{ 'pointer-events-none opacity-50': isSaving }"
            >
                <div class="flex flex-wrap items-end gap-2">
                    <label class="flex min-w-[160px] flex-1 flex-col gap-1 text-xs font-medium text-gray-600 dark:text-gray-300">
                        @lang('admin::app.lead-move.pipeline')

                        <select
                            class="w-full rounded border border-gray-300 bg-white px-2 py-1.5 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                            :value="pipelineId"
                            :disabled="isLoading || loadError"
                            @focus="ensureLoaded"
                            @change="choosePipeline($event.target.value)"
                        >
                            <option v-if="! pipelines.length" :value="pipelineId">@{{ initialPipelineName }}</option>

                            <option v-for="pipeline in pipelines" :key="pipeline.id" :value="pipeline.id">
                                @{{ pipeline.name }}@{{ pipeline.id === current.pipeline_id ? ' (' + texts.current + ')' : '' }}
                            </option>
                        </select>
                    </label>

                    <label class="flex min-w-[160px] flex-1 flex-col gap-1 text-xs font-medium text-gray-600 dark:text-gray-300">
                        <span class="flex items-center gap-1">
                            @lang('admin::app.lead-move.stage')

                            <span
                                class="inline-block h-2 w-2 rounded-full"
                                :style="{ background: stageColor(selectedStage) }"
                            ></span>
                        </span>

                        <select
                            class="w-full rounded border border-gray-300 bg-white px-2 py-1.5 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                            :value="stageId"
                            :disabled="isLoading || loadError"
                            @focus="ensureLoaded"
                            @change="chooseStage($event.target.value)"
                        >
                            <option v-if="! stages.length" :value="stageId">@{{ initialStageName }}</option>

                            <option v-for="stage in stages" :key="stage.id" :value="stage.id">
                                @{{ stageLabel(stage) }}
                            </option>
                        </select>
                    </label>

                    <template v-if="needsConfirmation">
                        <button type="button" class="secondary-button" @click="reset">
                            @lang('admin::app.lead-move.cancel-btn')
                        </button>

                        <button
                            type="button"
                            class="primary-button"
                            :disabled="! canSubmit || isSaving"
                            :class="{ 'opacity-50 cursor-not-allowed': ! canSubmit || isSaving }"
                            @click="submit"
                        >
                            @lang('admin::app.lead-move.confirm-btn')
                        </button>
                    </template>
                </div>

                <p v-if="loadError" class="text-xs text-red-600">@lang('admin::app.lead-move.load-error')</p>

                <p v-if="pipelineChanged" class="text-xs text-gray-600 dark:text-gray-300">
                    @lang('admin::app.lead-move.pipeline-change-hint')
                </p>

                <div
                    v-if="involvesClosed"
                    class="flex flex-col gap-2 rounded border border-amber-300 bg-amber-50 p-2 text-xs text-amber-900 dark:border-amber-700 dark:bg-gray-800 dark:text-amber-200"
                >
                    <p>@lang('admin::app.lead-move.closed-warning')</p>

                    <label class="flex cursor-pointer items-center gap-2 font-medium">
                        <input type="checkbox" v-model="confirmClosed" />

                        @lang('admin::app.lead-move.closed-confirm')
                    </label>
                </div>

                <p
                    v-if="visibilityRisk"
                    class="rounded border border-amber-300 bg-amber-50 p-2 text-xs text-amber-900 dark:border-amber-700 dark:bg-gray-800 dark:text-amber-200"
                >
                    @lang('admin::app.lead-move.visibility-warning')
                </p>

                <label
                    v-if="targetIsLost"
                    class="flex max-w-md flex-col gap-1 text-xs font-medium text-gray-600 dark:text-gray-300"
                >
                    @lang('admin::app.lead-move.lost-reason')

                    <select
                        v-if="lostReasons.length"
                        v-model="lostReasonId"
                        class="w-full rounded border border-gray-300 bg-white px-2 py-1.5 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                    >
                        <option value="">@lang('admin::app.lead-move.lost-reason-choose')</option>

                        <option v-for="reason in lostReasons" :key="reason.id" :value="reason.id">
                            @{{ reason.name }}
                        </option>
                    </select>

                    <textarea
                        v-else
                        v-model="lostReason"
                        rows="2"
                        class="w-full rounded border border-gray-300 bg-white px-2 py-1 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                    ></textarea>
                </label>

                <p v-if="errorMessage" class="text-xs text-red-600">@{{ errorMessage }}</p>
            </div>
        </div>
    </script>

    <script type="module">
        app.component('v-lead-pipeline-move', {
            template: '#v-lead-pipeline-move-template',

            props: {
                leadId: { type: [Number, String], required: true },

                variant: { type: String, default: 'icon' },

                // Só para a página do lead: mostra o funil/etapa atuais antes de carregar as opções.
                initialPipelineId: { type: [Number, String], default: null },
                initialStageId: { type: [Number, String], default: null },
                initialPipelineName: { type: String, default: '' },
                initialStageName: { type: String, default: '' },
            },

            emits: ['moved'],

            data() {
                return {
                    isOpen: false,
                    isLoading: false,
                    loadError: false,
                    isSaving: false,
                    errorMessage: '',

                    pipelines: [],
                    current: {
                        pipeline_id: this.initialPipelineId ? Number(this.initialPipelineId) : null,
                        stage_id: this.initialStageId ? Number(this.initialStageId) : null,
                        stage_code: null,
                    },
                    riskStageIds: [],

                    pipelineId: this.initialPipelineId ? Number(this.initialPipelineId) : null,
                    stageId: this.initialStageId ? Number(this.initialStageId) : null,
                    confirmClosed: false,
                    lostReasonId: '',
                    lostReason: '',

                    isMobile: false,
                    panelStyle: {},

                    texts: {
                        current: @json(trans('admin::app.lead-move.current')),
                        won: @json(trans('admin::app.lead-move.won')),
                        lost: @json(trans('admin::app.lead-move.lost')),
                        leftView: @json(trans('admin::app.lead-move.left-view')),
                    },

                    urls: {
                        options: @json(route('admin.leads.pipeline_move.options', '__ID__')),
                        update: @json(route('admin.leads.pipeline_move.update', '__ID__')),
                    },
                };
            },

            computed: {
                pipeline() {
                    return this.pipelines.find(pipeline => pipeline.id === this.pipelineId) ?? null;
                },

                stages() {
                    return this.pipeline?.stages ?? [];
                },

                selectedStage() {
                    return this.stages.find(stage => stage.id === this.stageId) ?? null;
                },

                lostReasons() {
                    return this.pipeline?.lost_reasons ?? [];
                },

                pipelineChanged() {
                    return this.pipelines.length > 0 && this.pipelineId !== this.current.pipeline_id;
                },

                changed() {
                    return this.pipelines.length > 0
                        && (this.pipelineId !== this.current.pipeline_id || this.stageId !== this.current.stage_id);
                },

                targetIsLost() {
                    return this.changed && this.selectedStage?.code === 'lost';
                },

                involvesClosed() {
                    return this.changed
                        && (['won', 'lost'].includes(this.current.stage_code) || ['won', 'lost'].includes(this.selectedStage?.code));
                },

                visibilityRisk() {
                    return this.changed && this.riskStageIds.includes(this.stageId);
                },

                // Trocar só a etapa no mesmo funil é direto; o resto passa pelo botão "Mover".
                needsConfirmation() {
                    return this.changed && (this.pipelineChanged || this.involvesClosed || this.visibilityRisk);
                },

                canSubmit() {
                    if (! this.changed || ! this.selectedStage) {
                        return false;
                    }

                    if (this.involvesClosed && ! this.confirmClosed) {
                        return false;
                    }

                    if (this.targetIsLost && this.lostReasons.length && ! this.lostReasonId) {
                        return false;
                    }

                    return true;
                },
            },

            mounted() {
                if (this.variant === 'inline') {
                    this.load();

                    // A etapa mudou pela barra de etapas da página: os seletores acompanham.
                    this.$emitter.on('agenda-stage-changed', ({ lead_id }) => {
                        if (Number(lead_id) === Number(this.leadId) && ! this.isSaving) {
                            this.load();
                        }
                    });
                }
            },

            beforeUnmount() {
                this.unbindOutside();
            },

            methods: {
                url(name) {
                    return this.urls[name].replace('__ID__', this.leadId);
                },

                load() {
                    this.isLoading = true;
                    this.loadError = false;

                    return this.$axios.get(this.url('options'))
                        .then(response => {
                            const data = response.data.data;

                            this.pipelines = data.pipelines;
                            this.current = data.lead;
                            this.riskStageIds = data.visibility_risk_stage_ids ?? [];

                            this.reset();
                        })
                        .catch(() => {
                            this.loadError = true;
                        })
                        .finally(() => {
                            this.isLoading = false;
                        });
                },

                ensureLoaded() {
                    if (! this.pipelines.length && ! this.isLoading) {
                        this.load();
                    }
                },

                reset() {
                    this.pipelineId = this.current.pipeline_id;
                    this.stageId = this.current.stage_id;
                    this.confirmClosed = false;
                    this.lostReasonId = '';
                    this.lostReason = '';
                    this.errorMessage = '';
                },

                stageLabel(stage) {
                    let label = stage.name;

                    // Marca Ganho/Perdido pelo código, sem repetir quando o nome já diz isso.
                    const closed = { won: this.texts.won, lost: this.texts.lost }[stage.code];

                    if (closed && ! label.toLowerCase().includes(closed.toLowerCase())) {
                        label += ' — ' + closed;
                    }

                    if (stage.id === this.current.stage_id) {
                        label += ' (' + this.texts.current + ')';
                    }

                    return label;
                },

                stageColor(stage) {
                    if (stage?.code === 'won') {
                        return '#22c55e';
                    }

                    if (stage?.code === 'lost') {
                        return '#ef4444';
                    }

                    return '#9ca3af';
                },

                // Novo funil: aparecem as etapas dele, com a primeira já escolhida.
                choosePipeline(value) {
                    this.pipelineId = Number(value);
                    this.stageId = this.pipelineId === this.current.pipeline_id
                        ? this.current.stage_id
                        : (this.stages[0]?.id ?? null);
                    this.confirmClosed = false;
                    this.lostReasonId = '';
                    this.errorMessage = '';
                },

                chooseStage(value) {
                    this.stageId = Number(value);
                    this.confirmClosed = false;
                    this.errorMessage = '';

                    if (this.changed && ! this.needsConfirmation) {
                        this.submit();
                    }
                },

                toggle() {
                    this.isOpen ? this.close() : this.open();
                },

                open() {
                    this.isOpen = true;
                    this.position();
                    this.load();

                    this.$nextTick(() => this.bindOutside());
                },

                close() {
                    this.isOpen = false;
                    this.unbindOutside();
                },

                position() {
                    this.isMobile = window.innerWidth < 640;

                    if (this.isMobile) {
                        this.panelStyle = { position: 'fixed', left: '0', right: '0', bottom: '0', zIndex: 10010, maxHeight: '85vh', overflowY: 'auto' };

                        return;
                    }

                    const rect = this.$refs.trigger.getBoundingClientRect();
                    const width = 300;
                    const left = Math.max(8, Math.min(rect.right - width, window.innerWidth - width - 8));
                    const below = rect.bottom + 6;
                    const style = { position: 'fixed', left: left + 'px', width: width + 'px', zIndex: 10010, overflowY: 'auto' };

                    // Abre para o lado com mais espaço (perto do rodapé, para cima) e rola por dentro se precisar.
                    const spaceBelow = window.innerHeight - below - 8;
                    const spaceAbove = rect.top - 14;

                    if (spaceBelow < 340 && spaceAbove > spaceBelow) {
                        style.bottom = (window.innerHeight - rect.top + 6) + 'px';
                        style.maxHeight = spaceAbove + 'px';
                    } else {
                        style.top = below + 'px';
                        style.maxHeight = spaceBelow + 'px';
                    }

                    this.panelStyle = style;
                },

                bindOutside() {
                    this.onOutside = (event) => {
                        if (this.$refs.panel?.contains(event.target) || this.$refs.trigger?.contains(event.target)) {
                            return;
                        }

                        this.close();
                    };

                    // Rolar a coluna ou a página deixaria o painel solto: fecha.
                    this.onScroll = (event) => {
                        if (this.isMobile || this.$refs.panel?.contains(event.target)) {
                            return;
                        }

                        this.close();
                    };

                    this.onKey = (event) => {
                        if (event.key === 'Escape') {
                            this.close();
                        }
                    };

                    document.addEventListener('pointerdown', this.onOutside, true);
                    window.addEventListener('scroll', this.onScroll, true);
                    window.addEventListener('resize', this.close);
                    document.addEventListener('keydown', this.onKey);
                },

                unbindOutside() {
                    if (! this.onOutside) {
                        return;
                    }

                    document.removeEventListener('pointerdown', this.onOutside, true);
                    window.removeEventListener('scroll', this.onScroll, true);
                    window.removeEventListener('resize', this.close);
                    document.removeEventListener('keydown', this.onKey);

                    this.onOutside = null;
                },

                submit() {
                    if (! this.canSubmit || this.isSaving) {
                        return;
                    }

                    this.isSaving = true;
                    this.errorMessage = '';

                    const payload = {
                        lead_pipeline_id: this.pipelineId,
                        lead_pipeline_stage_id: this.stageId,
                        confirm_closed: this.involvesClosed && this.confirmClosed ? 1 : 0,
                    };

                    if (this.targetIsLost) {
                        payload.lost_reason_id = this.lostReasonId || null;
                        payload.lost_reason = this.lostReason || null;
                    }

                    this.$axios.put(this.url('update'), payload)
                        .then(response => {
                            const data = response.data;

                            if (data.lead) {
                                this.current = data.lead;
                            }

                            this.reset();
                            this.close();

                            this.$emitter.emit('add-flash', {
                                type: data.still_visible === false ? 'warning' : 'success',
                                message: data.still_visible === false ? data.message + ' ' + this.texts.leftView : data.message,
                            });

                            if (data.changed) {
                                // O card "Agendar" abre se a instalação escolheu esta etapa (Configurações → Agenda).
                                this.$emitter.emit('agenda-stage-changed', { lead_id: Number(this.leadId) });
                            }

                            this.$emit('moved', data);

                            this.$emitter.emit('lead-pipeline-moved', { lead_id: Number(this.leadId), ...data });
                        })
                        .catch(error => {
                            const errors = error.response?.data?.errors;

                            this.errorMessage = errors
                                ? Object.values(errors).flat()[0]
                                : (error.response?.data?.message ?? '');

                            // Etapa trocada direto (sem botão): volta o seletor para o lugar atual.
                            if (! this.needsConfirmation) {
                                this.stageId = this.current.stage_id;
                            }

                            this.$emitter.emit('add-flash', { type: 'error', message: this.errorMessage });
                        })
                        .finally(() => {
                            this.isSaving = false;
                        });
                },
            },
        });
    </script>
@endPushOnce
