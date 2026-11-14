{{-- Aba "Histórico" do card do lead: linha do tempo do que aconteceu com o lead, e quem fez. --}}
<v-lead-history
    endpoint="{{ route('admin.leads.activities.index', $lead->id) }}"
    created-at="{{ optional($lead->created_at)->toIso8601String() }}"
    :lead-id="{{ $lead->id }}"
></v-lead-history>

@pushOnce('scripts')
    <script type="text/x-template" id="v-lead-history-template">
        <div class="flex flex-col gap-4 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <!-- Cabeçalho -->
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p
                    class="text-sm text-gray-600 dark:text-gray-300"
                    v-if="currentStageSummary"
                >
                    @{{ currentStageSummary }}
                </p>

                <label class="flex cursor-pointer items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                    <input type="checkbox" v-model="showAll" />

                    Mostrar todas as alterações
                </label>
            </div>

            <div v-if="isLoading" class="p-4 text-sm text-gray-500 dark:text-gray-300">
                Carregando histórico...
            </div>

            <div v-else-if="! visibleEvents.length" class="p-4 text-sm text-gray-500 dark:text-gray-300">
                Nenhum registro no histórico deste lead ainda.
            </div>

            <!-- Linha do tempo (mais recente primeiro) -->
            <ol v-else class="flex flex-col">
                <li
                    v-for="(event, index) in visibleEvents"
                    :key="event.key"
                    class="relative flex gap-3 pb-5 last:pb-0"
                >
                    <!-- Linha vertical -->
                    <span
                        v-if="index < visibleEvents.length - 1"
                        class="absolute"
                        style="left: 7px; top: 16px; height: 100%; width: 1px; background: #d1d5db;"
                    ></span>

                    <!-- Bolinha -->
                    <span
                        class="relative mt-1 shrink-0 rounded-full"
                        :style="{ width: '16px', height: '16px', background: dotColor(event.kind), border: '2px solid #fff' }"
                    ></span>

                    <div class="flex min-w-0 flex-col gap-0.5">
                        <p class="break-words font-medium dark:text-white">
                            @{{ event.text }}
                        </p>

                        <p
                            v-if="event.detail"
                            class="break-words text-sm text-gray-600 dark:text-gray-300"
                        >
                            @{{ event.detail }}
                        </p>

                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            @{{ event.who }} · @{{ event.when }}
                        </p>
                    </div>
                </li>
            </ol>
        </div>
    </script>

    <script type="module">
        app.component('v-lead-history', {
            template: '#v-lead-history-template',

            props: ['endpoint', 'createdAt', 'leadId'],

            data() {
                return {
                    isLoading: true,

                    showAll: false,

                    events: [],

                    currentStageSummary: '',
                };
            },

            computed: {
                visibleEvents() {
                    return this.events.filter(event => this.showAll || event.important);
                },
            },

            mounted() {
                this.load();

                // Mudou de etapa, nota ou atividade: o histórico acompanha sem recarregar a página.
                this.$emitter.on('agenda-stage-changed', ({ lead_id }) => {
                    if (lead_id == this.leadId) {
                        this.load();
                    }
                });

                this.$emitter.on('on-activity-added', () => this.load());
            },

            methods: {
                load() {
                    this.$axios.get(this.endpoint)
                        .then(response => {
                            this.build(response.data.data ?? []);
                        })
                        .catch(error => {
                            console.error(error);
                        })
                        .finally(() => {
                            this.isLoading = false;
                        });
                },

                // Os nomes dos campos mudam com o idioma da instalação (Stage/Etapa, Pipeline/Funil...).
                kindOf(attribute) {
                    const name = String(attribute ?? '').toLowerCase();

                    if (['stage_entered_at', 'closed_at'].includes(name)) {
                        return 'hidden';
                    }

                    if (name.includes('stage') || name.includes('etapa')) {
                        return 'stage';
                    }

                    if (name.includes('pipeline') || name.includes('funil')) {
                        return 'pipeline';
                    }

                    if (name.includes('owner') || name.includes('respons') || name.includes('vendedor')) {
                        return 'owner';
                    }

                    if (name.includes('lost') || name.includes('motivo') || name.includes('perda')) {
                        return 'lost';
                    }

                    if (name.includes('valor') || name.includes('value')) {
                        return 'value';
                    }

                    return 'other';
                },

                label(part) {
                    const value = part?.label ?? part?.value;

                    if (value === null || value === undefined || value === '' || value === '[]') {
                        return null;
                    }

                    return String(value).replaceAll('<br>', ' ');
                },

                formatDate(date) {
                    return new Date(date).toLocaleString('pt-BR', {
                        day: '2-digit',
                        month: '2-digit',
                        year: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit',
                    });
                },

                formatDuration(ms) {
                    const minutes = Math.floor(ms / 60000);

                    if (minutes < 1) {
                        return 'menos de 1 minuto';
                    }

                    if (minutes < 60) {
                        return minutes + (minutes === 1 ? ' minuto' : ' minutos');
                    }

                    const hours = Math.floor(minutes / 60);

                    if (hours < 24) {
                        return hours + (hours === 1 ? ' hora' : ' horas');
                    }

                    const days = Math.floor(hours / 24);

                    return days + (days === 1 ? ' dia' : ' dias');
                },

                who(activity) {
                    return activity.user?.name ? 'por ' + activity.user.name : 'Automação / sistema';
                },

                dotColor(kind) {
                    return {
                        created: '#22c55e',
                        stage: '#3b82f6',
                        pipeline: '#a855f7',
                        owner: '#f97316',
                        value: '#eab308',
                        lost: '#ef4444',
                        other: '#9ca3af',
                    }[kind] ?? '#9ca3af';
                },

                build(activities) {
                    const system = activities
                        .filter(activity => activity.type === 'system')
                        .sort((a, b) => (new Date(a.created_at) - new Date(b.created_at)) || (a.id - b.id));

                    const events = [];

                    let previousStageName = null;
                    let previousStageAt = null;
                    let hasCreated = false;
                    let createdAt = null;

                    system.forEach(activity => {
                        const at = new Date(activity.created_at);
                        const when = this.formatDate(activity.created_at);
                        const who = this.who(activity);

                        // Registro de criação: não tem campo alterado.
                        if (! activity.additional) {
                            if (hasCreated) {
                                return;
                            }

                            hasCreated = true;
                            createdAt = at;
                            previousStageAt = at;

                            events.push({ key: 'c' + activity.id, kind: 'created', important: true, text: 'Lead criado', detail: '', who, when });

                            return;
                        }

                        const kind = this.kindOf(activity.additional.attribute);

                        if (kind === 'hidden') {
                            return;
                        }

                        const oldLabel = this.label(activity.additional.old);
                        const newLabel = this.label(activity.additional.new);

                        // Campo que só ganhou valor (ou ficou vazio) na criação: ruído, só aparece em "todas as alterações".
                        const isStage = kind === 'stage';

                        if (isStage) {
                            const from = oldLabel ?? previousStageName;
                            let detail = '';

                            if (previousStageAt && from) {
                                detail = 'Ficou ' + this.formatDuration(at - previousStageAt) + ' em "' + from + '"';
                            }

                            events.push({
                                key: 's' + activity.id,
                                kind,
                                important: true,
                                text: from ? 'Etapa: ' + from + ' → ' + (newLabel ?? '—') : 'Etapa inicial: ' + (newLabel ?? '—'),
                                detail,
                                who,
                                when,
                            });

                            previousStageName = newLabel;
                            previousStageAt = at;

                            return;
                        }

                        const names = {
                            pipeline: 'Funil',
                            owner: 'Responsável',
                            lost: 'Motivo da perda',
                            value: 'Valor',
                        };

                        const name = names[kind] ?? activity.additional.attribute;

                        events.push({
                            key: 'a' + activity.id,
                            kind,
                            // Valores preenchidos junto com a criação do lead são só configuração inicial.
                            important: kind !== 'other' && ! (createdAt && at - createdAt < 10000),
                            text: name + ': ' + (oldLabel ?? 'vazio') + ' → ' + (newLabel ?? 'vazio'),
                            detail: '',
                            who,
                            when,
                        });
                    });

                    // Leads antigos, anteriores ao registro de criação.
                    if (! hasCreated && this.createdAt) {
                        events.unshift({ key: 'created', kind: 'created', important: true, text: 'Lead criado', detail: '', who: 'Autor não registrado', when: this.formatDate(this.createdAt) });

                        previousStageAt = previousStageAt ?? new Date(this.createdAt);
                    }

                    this.currentStageSummary = previousStageName && previousStageAt
                        ? 'Na etapa "' + previousStageName + '" há ' + this.formatDuration(Date.now() - previousStageAt)
                        : '';

                    this.events = events.reverse();
                },
            },
        });
    </script>
@endPushOnce
