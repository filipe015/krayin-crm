{{--
    "Adicionar Negócio" as a card over the pipeline, instead of a separate page. It is the same
    contact block and the same quick-add fields the header "+" uses; which fields appear is
    controlled by "Adicionar Rápido" in Configurações → Campos Personalizados.
--}}
@php
    $quickAttributes = app('Webkul\Attribute\Repositories\AttributeRepository')
        ->findWhere(['entity_type' => 'leads', 'quick_add' => 1])
        ->reject(fn ($attribute) => in_array($attribute->code, ['lead_pipeline_id', 'lead_pipeline_stage_id']));
@endphp

<v-lead-quick-create
    :pipeline-id="{{ (int) $pipeline->id }}"
    :stages="{{ Illuminate\Support\Js::from($pipeline->stages->map(fn ($stage) => ['id' => $stage->id, 'name' => $stage->name, 'code' => $stage->code])->values()) }}"
    view-type="{{ request()->view_type ?? 'kanban' }}"
></v-lead-quick-create>

{{-- Registers the contact component (person lookup, e-mail, phone, organization). --}}
@include('admin::leads.common.contact-component')

@pushOnce('scripts')
    <style>
        /* Compact two-column card: fields side by side, tighter spacing. */
        .lqc-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); column-gap: 12px; grid-auto-flow: row dense; }
        .lqc-grid > * { margin-bottom: 6px; min-width: 0; }
        .lqc-grid > :has([name="title"]),
        .lqc-grid > :last-child:nth-child(odd),
        .lqc-grid > :has(textarea) { grid-column: span 2; }
        .lqc-grid textarea { min-height: 56px; height: 56px; }
        .lqc-grid label { font-size: 12px; margin-bottom: 2px; }
        /* Tighter fields so the card is shorter: every control a little lower than the default. */
        .lqc-grid input:not([type="checkbox"]):not([type="radio"]), .lqc-grid select, .lqc-grid .custom-select { padding-top: 6px; padding-bottom: 6px; font-size: 13px; }
        @media (max-width: 640px) {
            .lqc-grid { grid-template-columns: minmax(0, 1fr); }
            .lqc-grid > * { grid-column: auto !important; }
        }
    </style>

    <script
        type="text/x-template"
        id="v-lead-quick-create-template"
    >
        <button
            type="button"
            class="primary-button"
            @click="open(null)"
        >
            @lang('admin::app.leads.index.create-btn')
        </button>

        <Teleport to="body">
            <x-admin::modal
                ref="modal"
                size="compact"
            >
                <x-slot:header>
                    <p class="text-xl font-semibold text-gray-800 dark:text-white">
                        @lang('admin::app.lead-quick-create.title')
                    </p>
                </x-slot>

                <x-slot:content>
                    <x-admin::form
                        v-slot="{ meta, errors, handleSubmit }"
                        as="div"
                        ref="formWrapper"
                    >
                        <form
                            @submit="handleSubmit($event, create)"
                            ref="form"
                        >
                            <input type="hidden" name="quick_add" value="lead" />
                            <input type="hidden" name="lead_pipeline_id" :value="pipelineId" />

                            <div class="lqc-grid">
                                <!-- Stage -->
                                <x-admin::form.control-group>
                                    <x-admin::form.control-group.label>
                                        @lang('admin::app.lead-quick-create.stage')
                                    </x-admin::form.control-group.label>

                                    <select
                                        name="lead_pipeline_stage_id"
                                        v-model="stageId"
                                        class="custom-select w-full rounded-md border bg-white px-3 py-2.5 text-sm text-gray-600 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                                    >
                                        <option
                                            v-for="stage in stages"
                                            :key="stage.id"
                                            :value="stage.id"
                                        >
                                            @{{ stage.name }}
                                        </option>
                                    </select>
                                </x-admin::form.control-group>

                                <!-- Contact (name, e-mail, phone, organization) -->
                                <v-contact-component :data="person"></v-contact-component>
                            </div>

                            <!-- Deal -->
                            <p class="mb-2 mt-2 font-semibold text-gray-800 dark:text-white">
                                @lang('admin::app.leads.create.details')
                            </p>

                            <div class="lqc-grid lqc-details">
                                <x-admin::attributes :custom-attributes="$quickAttributes" />
                            </div>
                        </form>
                    </x-admin::form>
                </x-slot>

                <x-slot:footer>
                    <x-admin::button
                        class="primary-button"
                        :title="trans('admin::app.leads.create.save-btn')"
                        ::loading="isStoring"
                        ::disabled="isStoring"
                        @click="submit"
                    />
                </x-slot>
            </x-admin::modal>
        </Teleport>
    </script>

    <script type="module">
        app.component('v-lead-quick-create', {
            template: '#v-lead-quick-create-template',

            props: {
                pipelineId: { type: Number, required: true },
                stages: { type: Array, default: () => [] },
                viewType: { type: String, default: 'kanban' },
            },

            data() {
                return {
                    isStoring: false,
                    person: { name: '' },
                    stageId: this.firstStageId(),
                };
            },

            mounted() {
                // The "+" of a stage column opens this same card with that stage chosen.
                this.$emitter.on('open-lead-quick-create', stageId => this.open(stageId));
            },

            methods: {
                firstStageId() {
                    const open = this.stages.find(stage => ! ['won', 'lost'].includes(stage.code));

                    return (open || this.stages[0] || {}).id;
                },

                open(stageId) {
                    this.stageId = stageId || this.firstStageId();
                    this.person = { name: '' };
                    this.$refs.modal.open();
                },

                submit() {
                    const form = this.$refs.form;

                    if (! form) {
                        return;
                    }

                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit();
                    } else {
                        form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
                    }
                },

                create(params, { setErrors }) {
                    const form = this.$refs.form;

                    if (! form) {
                        return;
                    }

                    this.isStoring = true;

                    this.$axios.post("{{ route('admin.leads.store') }}", new FormData(form))
                        .then(response => {
                            this.$emitter.emit('add-flash', { type: 'success', message: response.data.message });

                            this.$refs.modal.close();

                            form.reset();

                            // The kanban reloads itself; the table is simply reloaded.
                            if (this.viewType === 'table') {
                                window.location.reload();

                                return;
                            }

                            this.$emitter.emit('lead-created');
                        })
                        .catch(error => {
                            if (error.response?.status == 422) {
                                setErrors(error.response.data.errors);
                            } else {
                                this.$emitter.emit('add-flash', {
                                    type: 'error',
                                    message: error.response?.data?.message || error.message,
                                });
                            }
                        })
                        .finally(() => {
                            this.isStoring = false;
                        });
                },
            },
        });
    </script>
@endPushOnce
