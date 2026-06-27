{!! view_render_event('admin.leads.index.kanban.toolbar.before') !!}

<div class="flex justify-between gap-2 max-md:flex-wrap">
    <div class="flex w-full items-center gap-x-1.5 max-md:justify-between">
        {!! view_render_event('admin.leads.index.kanban.toolbar.search.before') !!}

        <!-- Search Panel -->
        @include('admin::leads.index.kanban.search')

        {!! view_render_event('admin.leads.index.kanban.toolbar.search.after') !!}

        {!! view_render_event('admin.leads.index.kanban.toolbar.filter.before') !!}

        <!-- Filter -->
        @include('admin::leads.index.kanban.filter')

        {!! view_render_event('admin.leads.index.kanban.toolbar.filter.after') !!}

        <div class="z-10 hidden w-full divide-y divide-gray-100 rounded bg-white shadow dark:bg-gray-900"></div>
    </div>

    {!! view_render_event('admin.leads.index.kanban.toolbar.switcher.before') !!}

    <!-- View Switcher -->
    @include('admin::leads.index.view-switcher')

    {!! view_render_event('admin.leads.index.kanban.toolbar.switcher.after') !!}

    {!! view_render_event('admin.leads.index.kanban.toolbar.card_preferences.before') !!}

    <!-- Card Preferences -->
    <x-admin::dropdown position="bottom-right" :close-on-click="false">
        <x-slot:toggle>
            <span class="icon-eye cursor-pointer rounded-md p-2 text-2xl text-gray-600 transition-all hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-950"></span>
        </x-slot>

        <x-slot:content class="!p-0">
            <div class="flex items-center justify-between px-3 py-2.5">
                <span class="text-xs font-medium text-gray-500 dark:text-gray-300">
                    @lang('admin::app.leads.index.kanban.card-preferences.title')
                </span>
            </div>

            <div class="flex flex-col border-t border-gray-300 py-1 dark:border-gray-800">
                <label class="flex cursor-pointer items-center gap-2 px-4 py-2 hover:bg-gray-50 dark:hover:bg-gray-950">
                    <input
                        type="checkbox"
                        class="peer hidden"
                        v-model="cardPreferences.show_lead_value"
                        @change="saveCardPreferences"
                    />

                    <span class="icon-checkbox-outline peer-checked:icon-checkbox-select text-xl text-gray-500 peer-checked:text-brandColor"></span>

                    <span class="text-sm text-gray-700 dark:text-gray-300">
                        @lang('admin::app.leads.index.kanban.card-preferences.show-lead-value')
                    </span>
                </label>

                <label class="flex cursor-pointer items-center gap-2 px-4 py-2 hover:bg-gray-50 dark:hover:bg-gray-950">
                    <input
                        type="checkbox"
                        class="peer hidden"
                        v-model="cardPreferences.show_created_at"
                        @change="saveCardPreferences"
                    />

                    <span class="icon-checkbox-outline peer-checked:icon-checkbox-select text-xl text-gray-500 peer-checked:text-brandColor"></span>

                    <span class="text-sm text-gray-700 dark:text-gray-300">
                        @lang('admin::app.leads.index.kanban.card-preferences.show-created-at')
                    </span>
                </label>

                <label class="flex cursor-pointer items-center gap-2 px-4 py-2 hover:bg-gray-50 dark:hover:bg-gray-950">
                    <input
                        type="checkbox"
                        class="peer hidden"
                        v-model="cardPreferences.show_source"
                        @change="saveCardPreferences"
                    />

                    <span class="icon-checkbox-outline peer-checked:icon-checkbox-select text-xl text-gray-500 peer-checked:text-brandColor"></span>

                    <span class="text-sm text-gray-700 dark:text-gray-300">
                        @lang('admin::app.leads.index.kanban.card-preferences.show-source')
                    </span>
                </label>
            </div>
        </x-slot>
    </x-admin::dropdown>

    {!! view_render_event('admin.leads.index.kanban.toolbar.card_preferences.after') !!}
</div>

{!! view_render_event('admin.leads.index.kanban.toolbar.after') !!}
