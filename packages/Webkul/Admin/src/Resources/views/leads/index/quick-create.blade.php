{{--
    "Adicionar Negócio" as a card over the pipeline, instead of a separate page. It is the same
    contact block and the same quick-add fields the header "+" uses; which fields appear is
    controlled by "Adicionar Rápido" in Configurações → Campos Personalizados.
--}}
@php
    $quickAttributes = app('Webkul\Attribute\Repositories\AttributeRepository')
        ->findWhere(['entity_type' => 'leads', 'quick_add' => 1])
        ->reject(fn ($attribute) => in_array($attribute->code, ['lead_pipeline_id', 'lead_pipeline_stage_id']));

    // A field of the deal that repeats the contact's name ("Nome do contato"): the card fills it from the contact.
    $contactNameField = $quickAttributes->first(fn ($attribute) => in_array(
        preg_replace('/[^a-z]+/', '_', mb_strtolower(\Illuminate\Support\Str::ascii($attribute->name))),
        ['nome_do_contato', 'nome_contato', 'contact_name'],
    ) || in_array($attribute->code, ['nome_do_contato', 'nome_contato', 'contact_name']));

    // Serviços (the products of the installation) the deal can be tied to; none for people without access to them.
    $quickServices = bouncer()->hasPermission('products')
        ? \Webkul\Product\Models\Product::query()->orderBy('name')->limit(300)->get(['id', 'name', 'price'])
            ->map(fn ($service) => ['id' => $service->id, 'name' => $service->name, 'price' => (float) $service->price])->values()
        : collect();
@endphp

<v-lead-quick-create
    :pipeline-id="{{ (int) $pipeline->id }}"
    :stages="{{ Illuminate\Support\Js::from($pipeline->stages->map(fn ($stage) => ['id' => $stage->id, 'name' => $stage->name, 'code' => $stage->code])->values()) }}"
    view-type="{{ request()->view_type ?? 'kanban' }}"
    contact-name-field="{{ $contactNameField?->code }}"
    :services="{{ Illuminate\Support\Js::from($quickServices) }}"
></v-lead-quick-create>

{{-- Registers the contact component (person lookup, e-mail, phone, organization). --}}
@include('admin::leads.common.contact-component')

{{-- The "contact name" field of the deal is not asked here: it is filled from the contact (see fillContactName). --}}
@if ($contactNameField)
    @push('scripts')
        <style>
            .lqc-grid > :has([name="{{ $contactNameField->code }}"]) { display: none; }
        </style>
    @endpush
@endif

@pushOnce('scripts')
    <style>
        /* Compact two-column card: fields side by side, tighter spacing. The details are laid out by position:
           title (full row), stage | service, then the rest in pairs, the description last. */
        .lqc-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); column-gap: 12px; grid-auto-flow: row dense; }
        .lqc-grid > * { margin-bottom: 4px; min-width: 0; }
        .lqc-grid > :has([name="title"]) { grid-column: span 2; order: -3; }
        .lqc-grid > .lqc-stage { order: -2; }
        .lqc-grid > .lqc-service { order: -1; }
        .lqc-grid > :has(textarea) { grid-column: span 2; order: 50; }
        .lqc-grid textarea { min-height: 44px; height: 44px; }
        .lqc-grid label { font-size: 12px; margin-bottom: 1px; }
        /* Tighter fields so the card is shorter: every control a little lower than the default. */
        .lqc-grid input:not([type="checkbox"]):not([type="radio"]), .lqc-grid select, .lqc-grid .custom-select { padding-top: 5px; padding-bottom: 5px; font-size: 13px; }
        .lqc-divider { margin: 4px 0 6px; border-top: 1px solid #e5e7eb; }
        .dark .lqc-divider { border-color: #1f2937; }
        @media (max-height: 820px) {
            .lqc-grid > * { margin-bottom: 2px; }
            .lqc-grid input:not([type="checkbox"]):not([type="radio"]), .lqc-grid select, .lqc-grid .custom-select { padding-top: 4px; padding-bottom: 4px; }
        }
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
                            autocomplete="off"
                        >
                            <input type="hidden" name="quick_add" value="lead" />
                            <input type="hidden" name="lead_pipeline_id" :value="pipelineId" />

                            <div class="lqc-grid">
                                <!-- Contact (name, e-mail, phone, organization) -->
                                <v-contact-component :data="person"></v-contact-component>
                            </div>

                            <div class="lqc-divider"></div>

                            <!-- Deal -->
                            <div class="lqc-grid lqc-details">
                                <!-- Stage -->
                                <x-admin::form.control-group class="lqc-stage">
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

                                <!-- Service (what the deal is about) -->
                                <x-admin::form.control-group class="lqc-service" v-if="services.length">
                                    <x-admin::form.control-group.label>
                                        @lang('admin::app.lead-quick-create.service')
                                    </x-admin::form.control-group.label>

                                    <select
                                        v-model="serviceId"
                                        @change="serviceChanged"
                                        class="custom-select w-full rounded-md border bg-white px-3 py-2.5 text-sm text-gray-600 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                                    >
                                        <option value="">@lang('admin::app.lead-quick-create.service-none')</option>

                                        <option
                                            v-for="service in services"
                                            :key="service.id"
                                            :value="service.id"
                                        >
                                            @{{ service.name }}
                                        </option>
                                    </select>

                                    <template v-if="chosenService">
                                        <input type="hidden" name="products[0][product_id]" :value="chosenService.id" />
                                        <input type="hidden" name="products[0][name]" :value="chosenService.name" />
                                        <input type="hidden" name="products[0][price]" :value="chosenService.price" />
                                        <input type="hidden" name="products[0][quantity]" value="1" />
                                    </template>
                                </x-admin::form.control-group>

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
                contactNameField: { type: String, default: '' },
                services: { type: Array, default: () => [] },
            },

            data() {
                return {
                    isStoring: false,
                    autoContactName: '',
                    person: { name: '' },
                    stageId: this.firstStageId(),
                    serviceId: '',
                    autoValue: '',
                };
            },

            computed: {
                chosenService() {
                    return this.services.find(service => String(service.id) === String(this.serviceId)) || null;
                },
            },

            mounted() {
                // The "+" of a stage column opens this same card with that stage chosen.
                this.$emitter.on('open-lead-quick-create', stageId => this.open(stageId));

                this.$emitter.on('lead-contact-picked', person => this.fillContactName(person));
            },

            methods: {
                /**
                 * The deal's "contact name" field follows the contact that is picked, unless someone wrote
                 * something else in it.
                 */
                fillContactName(person) {
                    if (! this.contactNameField || ! person?.name) {
                        return;
                    }

                    const current = this.$refs.form?.elements?.[this.contactNameField]?.value ?? '';

                    if (current === '' || current === this.autoContactName) {
                        this.$refs.formWrapper?.setFieldValue(this.contactNameField, person.name);

                        this.autoContactName = person.name;
                    }
                },

                /**
                 * The deal's value starts as the service's price (when nobody typed another value).
                 */
                serviceChanged() {
                    const price = this.chosenService ? String(this.chosenService.price) : '';
                    const current = String(this.$refs.form?.elements?.lead_value?.value ?? '');

                    if (price !== '' && (current === '' || current === '0' || current === this.autoValue)) {
                        this.$refs.formWrapper?.setFieldValue('lead_value', price);

                        this.autoValue = price;
                    }
                },

                firstStageId() {
                    const open = this.stages.find(stage => ! ['won', 'lost'].includes(stage.code));

                    return (open || this.stages[0] || {}).id;
                },

                open(stageId) {
                    this.stageId = stageId || this.firstStageId();
                    this.autoContactName = '';
                    this.serviceId = '';
                    this.autoValue = '';
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

                            const created = response.data?.data?.id;

                            // The kanban reloads itself; the table is simply reloaded.
                            if (this.viewType === 'table') {
                                try {
                                    // Asked again once the page is back (the booking card for a chosen column).
                                    created && sessionStorage.setItem('agendaPromptLead', created);
                                } catch (error) {}

                                window.location.reload();

                                return;
                            }

                            this.$emitter.emit('lead-created');

                            this.$emitter.emit('agenda-stage-changed', { lead_id: created });
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
