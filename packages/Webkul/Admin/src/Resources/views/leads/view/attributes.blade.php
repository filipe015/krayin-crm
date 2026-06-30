{!! view_render_event('admin.leads.view.attributes.before', ['lead' => $lead]) !!}

@php
    $leadAttributes = app('Webkul\Attribute\Repositories\AttributeRepository')->findWhere([
        'entity_type' => 'leads',
        ['code', 'NOTIN', ['title', 'description', 'lead_pipeline_id', 'lead_pipeline_stage_id']]
    ]);

    $leadAttributePreference = app('Webkul\Core\Repositories\KanbanCardPreferenceRepository')->findOneWhere([
        'user_id' => auth()->guard()->user()->id,
        'src'     => 'lead-attributes',
    ]);

    $hiddenLeadAttributeCodes = $leadAttributePreference
        ? ($leadAttributePreference->preferences['hidden_codes'] ?? [])
        : [];

    /**
     * Required attributes are never hidden, even if their code somehow ended up in
     * a saved preference (e.g. became required after the preference was saved) —
     * this check is the backend safety net, not just the frontend checkbox state.
     */
    $visibleLeadAttributes = $leadAttributes->filter(
        fn ($attribute) => $attribute->is_required || ! in_array($attribute->code, $hiddenLeadAttributeCodes)
    );
@endphp

<div class="flex w-full flex-col gap-4 border-b border-gray-300 p-4 dark:border-gray-800">
    <x-admin::accordion class="select-none !border-none">
        <x-slot:header class="!p-0">
            <div class="flex w-full items-center justify-between gap-4 font-semibold dark:text-white">
                <h4>@lang('admin::app.leads.view.attributes.title')</h4>

                <div class="flex items-center gap-1">
                    <!-- Attribute Visibility Preferences -->
                    <v-lead-attribute-preferences></v-lead-attribute-preferences>

                    @if (bouncer()->hasPermission('leads.edit'))
                        <a
                            href="{{ route('admin.leads.edit', $lead->id) }}"
                            class="icon-edit rounded-md p-1.5 text-2xl transition-all hover:bg-gray-100 dark:hover:bg-gray-950"
                            target="_blank"
                        ></a>
                    @endif
                </div>
            </div>
        </x-slot>

        <x-slot:content class="mt-4 !px-0 !pb-0">
            {!! view_render_event('admin.leads.view.attributes.form_controls.before', ['lead' => $lead]) !!}

            <x-admin::form
                v-slot="{ meta, errors, handleSubmit }"
                as="div"
                ref="modalForm"
            >
                <form @submit="handleSubmit($event, () => {})">
                    {!! view_render_event('admin.leads.view.attributes.form_controls.attributes.view.before', ['lead' => $lead]) !!}

                    <x-admin::attributes.view
                        :custom-attributes="$visibleLeadAttributes"
                        :entity="$lead"
                        :url="route('admin.leads.attributes.update', $lead->id)"
                        :allow-edit="true"
                    />

                    {!! view_render_event('admin.leads.view.attributes.form_controls.attributes.view.after', ['lead' => $lead]) !!}
                </form>
            </x-admin::form>

            {!! view_render_event('admin.leads.view.attributes.form_controls.after', ['lead' => $lead]) !!}
        </x-slot>
    </x-admin::accordion>
</div>

{!! view_render_event('admin.leads.view.attributes.before', ['lead' => $lead]) !!}

@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-lead-attribute-preferences-template"
    >
        <x-admin::dropdown position="bottom-right" :close-on-click="false">
            <x-slot:toggle>
                <span class="icon-filter cursor-pointer rounded-md p-1.5 text-2xl transition-all hover:bg-gray-100 dark:hover:bg-gray-950"></span>
            </x-slot>

            <x-slot:content class="!p-0">
                <div class="flex items-center justify-between px-3 py-2.5">
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-300">
                        @lang('admin::app.leads.view.attributes.preferences.title')
                    </span>
                </div>

                <div class="flex max-h-72 flex-col overflow-y-auto border-t border-gray-300 py-1 dark:border-gray-800">
                    <label
                        class="flex items-center gap-2 px-4 py-2"
                        :class="attribute.is_required ? 'cursor-not-allowed opacity-60' : 'cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-950'"
                        v-for="attribute in attributes"
                        :key="attribute.code"
                    >
                        <input
                            type="checkbox"
                            class="peer hidden"
                            :checked="isVisible(attribute)"
                            :disabled="attribute.is_required"
                            @change="toggle(attribute)"
                        />

                        <span class="icon-checkbox-outline peer-checked:icon-checkbox-select text-xl text-gray-500 peer-checked:text-brandColor"></span>

                        <span class="text-sm text-gray-700 dark:text-gray-300">
                            @{{ attribute.name }}
                        </span>
                    </label>
                </div>
            </x-slot>
        </x-admin::dropdown>
    </script>

    <script type="module">
        app.component('v-lead-attribute-preferences', {
            template: '#v-lead-attribute-preferences-template',

            data() {
                return {
                    attributes: [],

                    hiddenCodes: [],
                };
            },

            mounted() {
                this.boot();
            },

            methods: {
                /**
                 * Loads the full list of lead attributes and the current user's saved
                 * visibility preference for this screen.
                 *
                 * @returns {void}
                 */
                boot() {
                    Promise.all([
                        this.$axios.get("{{ route('admin.leads.attributes.list') }}"),
                        this.$axios.get("{{ route('admin.kanban.card_preferences.index') }}", {
                            params: { src: 'lead-attributes' }
                        }),
                    ])
                        .then(([attributesResponse, preferenceResponse]) => {
                            this.attributes = attributesResponse.data.data ?? [];

                            this.hiddenCodes = preferenceResponse.data.data?.preferences?.hidden_codes ?? [];
                        })
                        .catch(error => {});
                },

                /**
                 * Required attributes are always visible. Others are visible unless
                 * their code is in the saved `hiddenCodes` list.
                 *
                 * @param {object} attribute
                 * @returns {boolean}
                 */
                isVisible(attribute) {
                    return attribute.is_required || ! this.hiddenCodes.includes(attribute.code);
                },

                /**
                 * Toggles an attribute's visibility and persists the change, then
                 * reloads the page so the "About Lead" section reflects it.
                 *
                 * @param {object} attribute
                 * @returns {void}
                 */
                toggle(attribute) {
                    if (attribute.is_required) {
                        return;
                    }

                    this.hiddenCodes = this.hiddenCodes.includes(attribute.code)
                        ? this.hiddenCodes.filter(code => code !== attribute.code)
                        : [...this.hiddenCodes, attribute.code];

                    this.$axios
                        .post("{{ route('admin.kanban.card_preferences.store') }}", {
                            src: 'lead-attributes',
                            preferences: { hidden_codes: this.hiddenCodes },
                        })
                        .then(() => {
                            window.location.reload();
                        })
                        .catch(error => {});
                },
            },
        });
    </script>
@endPushOnce
