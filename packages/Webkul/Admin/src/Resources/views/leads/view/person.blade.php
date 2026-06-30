{!! view_render_event('admin.leads.view.person.before', ['lead' => $lead]) !!}

<div class="flex w-full flex-col gap-4 border-b border-gray-300 p-4 dark:border-gray-800">
    @if ($lead?->person)
        <x-admin::accordion class="select-none !border-none">
            <x-slot:header class="!p-0">
                <div class="flex w-full items-center justify-between gap-4 font-semibold dark:text-white">
                    <h4>@lang('admin::app.leads.view.persons.title')</h4>

                    <div class="flex items-center gap-1">
                        @if (bouncer()->hasPermission('leads.edit') && bouncer()->hasPermission('contacts.persons.edit'))
                            <v-lead-attach-person
                                url="{{ route('admin.leads.attributes.update', $lead->id) }}"
                                search-url="{{ route('admin.contacts.persons.search') }}"
                                mode="change"
                                :current-person='@json(['id' => $lead->person->id, 'name' => $lead->person->name])'
                            ></v-lead-attach-person>
                        @endif
                    </div>
                </div>
            </x-slot>

            <x-slot:content class="mt-4 !px-0 !pb-0">
                <div class="flex gap-2">
                    {!! view_render_event('admin.leads.view.person.avatar.before', ['lead' => $lead]) !!}

                    <!-- Person Initials -->
                    <x-admin::avatar :name="$lead->person->name" />

                    {!! view_render_event('admin.leads.view.person.avatar.after', ['lead' => $lead]) !!}

                    <!-- Person Details -->
                    <div class="flex flex-col gap-1">
                        {!! view_render_event('admin.leads.view.person.name.before', ['lead' => $lead]) !!}

                        <a
                            href="{{ route('admin.contacts.persons.view', $lead->person->id) }}"
                            class="font-semibold text-brandColor"
                            target="_blank"
                        >
                            {{ $lead->person->name }}
                        </a>

                        {!! view_render_event('admin.leads.view.person.name.after', ['lead' => $lead]) !!}

                        {!! view_render_event('admin.leads.view.person.job_title.before', ['lead' => $lead]) !!}

                        @if ($lead->person->job_title)
                            <span class="dark:text-white">
                                @if ($lead->person->organization)
                                    @lang('admin::app.leads.view.persons.job-title', [
                                        'job_title' => $lead->person->job_title,
                                        'organization' => $lead->person->organization->name
                                    ])
                                @else
                                    {{ $lead->person->job_title }}
                                @endif
                            </span>
                        @endif

                        {!! view_render_event('admin.leads.view.person.job_title.after', ['lead' => $lead]) !!}

                        {!! view_render_event('admin.leads.view.person.email.before', ['lead' => $lead]) !!}

                        @foreach ($lead->person->emails ?? [] as $email)
                            <div class="flex gap-1">
                                <a
                                    class="text-brandColor"
                                    href="mailto:{{ $email['value'] }}"
                                >
                                    {{ $email['value'] }}
                                </a>

                                <span class="text-gray-500 dark:text-gray-300">
                                    ({{ $email['label'] }})
                                </span>
                            </div>
                        @endforeach

                        {!! view_render_event('admin.leads.view.person.email.after', ['lead' => $lead]) !!}

                        {!! view_render_event('admin.leads.view.person.contact_numbers.before', ['lead' => $lead]) !!}

                        @foreach ($lead->person->contact_numbers ?? [] as $contactNumber)
                            <div class="flex items-center gap-1">
                                <a
                                    class="text-brandColor"
                                    href="callto:{{ $contactNumber['value'] }}"
                                >
                                    {{ $contactNumber['value'] }}
                                </a>

                                <span class="text-gray-500 dark:text-gray-300">
                                    ({{ $contactNumber['label'] }})
                                </span>

                                @php
                                    $whatsappDigits = preg_replace('/\D/', '', $contactNumber['value']);
                                    $whatsappNumber = $whatsappDigits && ! str_starts_with($whatsappDigits, '55')
                                        ? '55'.$whatsappDigits
                                        : $whatsappDigits;
                                @endphp

                                @if ($whatsappNumber)
                                    <a
                                        href="https://wa.me/{{ $whatsappNumber }}"
                                        target="_blank"
                                        class="flex items-center rounded p-1 transition-all hover:bg-gray-200 dark:hover:bg-gray-800"
                                        title="{{ trans('admin::app.leads.view.persons.whatsapp') }}"
                                    >
                                        <svg viewBox="0 0 32 32" class="h-3.5 w-3.5" fill="#25D366">
                                            <path d="M16.004 3C9.374 3 4 8.373 4 15.002c0 2.474.733 4.77 1.998 6.689L4 29l7.52-1.973a11.94 11.94 0 0 0 4.484.873h.004c6.63 0 12.003-5.373 12.003-12.001C28.011 8.373 22.638 3 16.004 3zm6.997 17.06c-.297.836-1.74 1.6-2.405 1.703-.616.094-1.396.134-2.255-.14-.52-.166-1.187-.387-2.04-.756-3.59-1.55-5.934-5.146-6.115-5.388-.18-.242-1.466-1.95-1.466-3.72 0-1.77.93-2.64 1.26-3.002.33-.362.72-.453.96-.453.24 0 .48.002.69.013.221.012.518-.084.81.618.297.717 1.01 2.476 1.1 2.656.09.18.15.39.03.63-.12.24-.18.39-.36.6-.18.21-.378.469-.54.63-.18.18-.367.375-.157.735.21.36.93 1.535 1.997 2.486 1.374 1.222 2.534 1.6 2.9 1.78.366.18.58.15.793-.09.21-.24.93-1.08 1.18-1.45.24-.36.48-.3.81-.18.33.12 2.085.984 2.445 1.164.36.18.6.27.69.42.09.15.09.87-.21 1.704z"/>
                                        </svg>
                                    </a>
                                @endif
                            </div>
                        @endforeach

                        {!! view_render_event('admin.leads.view.person.contact_numbers.after', ['lead' => $lead]) !!}
                    </div>
                </div>
            </x-slot>
        </x-admin::accordion>
    @else
        <div class="flex w-full items-center justify-between gap-4 font-semibold dark:text-white">
            <h4>@lang('admin::app.leads.view.persons.title')</h4>
        </div>

        @if (bouncer()->hasPermission('leads.edit') && bouncer()->hasPermission('contacts.persons.edit'))
            <v-lead-attach-person
                url="{{ route('admin.leads.attributes.update', $lead->id) }}"
                search-url="{{ route('admin.contacts.persons.search') }}"
            ></v-lead-attach-person>
        @else
            <p class="text-sm text-gray-600 dark:text-gray-300">
                @lang('admin::app.leads.view.persons.no-person')
            </p>
        @endif
    @endif
</div>

{!! view_render_event('admin.leads.view.person.after', ['lead' => $lead]) !!}

@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-lead-attach-person-template"
    >
        <div class="flex flex-col gap-2">
            <!-- Inline attach form (no person allocated) -->
            <template v-if="mode === 'attach'">
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    @lang('admin::app.leads.view.persons.no-person')
                </p>

                <x-admin::form
                    v-slot="{ meta, errors, handleSubmit }"
                    as="div"
                    ref="attachPersonFormWrapper"
                >
                    <form
                        @submit="handleSubmit($event, savePerson)"
                        ref="attachPersonForm"
                    >
                        <x-admin::form.control-group>
                            <x-admin::lookup
                                ::src="searchUrl"
                                name="person[id]"
                                ::value="selectedPerson"
                                rules="required"
                                :label="trans('admin::app.leads.common.contact.name')"
                                :placeholder="trans('admin::app.leads.common.contact.name')"
                                @on-selected="onPersonSelected"
                            />

                            <x-admin::form.control-group.error control-name="person[id]" />
                        </x-admin::form.control-group>

                        <div class="flex justify-end">
                            <x-admin::button
                                class="primary-button"
                                :title="trans('admin::app.leads.view.persons.attach-btn')"
                                ::loading="isStoring"
                                ::disabled="isStoring || ! selectedPerson.id"
                            />
                        </div>
                    </form>
                </x-admin::form>
            </template>

            <!-- Change person inline dropdown trigger -->
            <template v-else>
                <div
                    class="relative"
                    ref="changeWrapper"
                >
                    @if (bouncer()->hasPermission('leads.edit') && bouncer()->hasPermission('contacts.persons.edit'))
                        <a
                            type="button"
                            class="icon-edit rounded-md p-1.5 text-2xl transition-all hover:bg-gray-100 dark:hover:bg-gray-950"
                            @click="toggleChangeForm"
                        ></a>
                    @endif

                    <div
                        v-if="showChangeForm"
                        class="absolute right-0 top-full z-20 mt-2 flex w-72 flex-col gap-2 rounded-lg border border-gray-300 bg-white p-3 shadow-lg dark:border-gray-800 dark:bg-gray-900"
                        @click.stop
                    >
                        <x-admin::form
                            v-slot="{ meta, errors, handleSubmit }"
                            as="div"
                            ref="changePersonFormWrapper"
                        >
                            <form
                                @submit="handleSubmit($event, savePerson)"
                                ref="changePersonForm"
                            >
                                <x-admin::form.control-group>
                                    <x-admin::form.control-group.label class="required">
                                        @lang('admin::app.leads.common.contact.name')
                                    </x-admin::form.control-group.label>

                                    <x-admin::lookup
                                        ::src="searchUrl"
                                        name="person[id]"
                                        ::value="selectedPerson"
                                        rules="required"
                                        :label="trans('admin::app.leads.common.contact.name')"
                                        :placeholder="trans('admin::app.leads.common.contact.name')"
                                        @on-selected="onPersonSelected"
                                    />

                                    <x-admin::form.control-group.error control-name="person[id]" />
                                </x-admin::form.control-group>

                                <div class="flex justify-end gap-2">
                                    <button
                                        type="button"
                                        class="transparent-button"
                                        @click="toggleChangeForm"
                                    >
                                        @lang('admin::app.leads.view.persons.cancel-btn')
                                    </button>

                                    <x-admin::button
                                        class="primary-button"
                                        :title="trans('admin::app.leads.view.persons.save-btn')"
                                        ::loading="isStoring"
                                        ::disabled="isStoring || ! selectedPerson.id || selectedPerson.id == currentPerson.id"
                                    />
                                </div>
                            </form>
                        </x-admin::form>
                    </div>
                </div>
            </template>
        </div>
    </script>

    <script type="module">
        app.component('v-lead-attach-person', {
            template: '#v-lead-attach-person-template',

            props: {
                url: {
                    type: String,
                    required: true,
                },

                searchUrl: {
                    type: String,
                    required: true,
                },

                mode: {
                    type: String,
                    default: 'attach',
                },

                currentPerson: {
                    type: Object,
                    default: () => ({ id: '', name: '' }),
                },
            },

            data() {
                return {
                    isStoring: false,

                    showChangeForm: false,

                    selectedPerson: {
                        id: this.currentPerson?.id ?? '',
                        name: this.currentPerson?.name ?? '',
                    },
                };
            },

            mounted() {
                window.addEventListener('click', this.handleOutsideClick);
            },

            beforeDestroy() {
                window.removeEventListener('click', this.handleOutsideClick);
            },

            methods: {
                onPersonSelected(person) {
                    this.selectedPerson = {
                        id: person?.id ?? '',
                        name: person?.name ?? '',
                    };
                },

                toggleChangeForm(event) {
                    event?.preventDefault();
                    event?.stopPropagation();

                    this.showChangeForm = ! this.showChangeForm;

                    if (this.showChangeForm) {
                        this.selectedPerson = {
                            id: this.currentPerson?.id ?? '',
                            name: this.currentPerson?.name ?? '',
                        };
                    }
                },

                handleOutsideClick(event) {
                    const wrapper = this.$refs.changeWrapper;

                    if (
                        this.showChangeForm
                        && wrapper
                        && ! wrapper.contains(event.target)
                    ) {
                        this.showChangeForm = false;
                    }
                },

                savePerson() {
                    if (! this.selectedPerson.id) {
                        return;
                    }

                    this.isStoring = true;

                    const formData = new FormData();

                    formData.append('_method', 'PUT');
                    formData.append('person[id]', this.selectedPerson.id);
                    formData.append('person[name]', this.selectedPerson.name);

                    this.$axios.post(this.url, formData)
                        .then(response => {
                            this.$emitter.emit('add-flash', {
                                type: 'success',
                                message: response.data.message,
                            });

                            window.location.reload();
                        })
                        .catch(error => {
                            this.$emitter.emit('add-flash', {
                                type: 'error',
                                message: error.response?.data?.message || error.message,
                            });
                        })
                        .finally(() => {
                            this.isStoring = false;
                        });
                },
            },
        });
    </script>
@endPushOnce
