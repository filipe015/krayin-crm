<x-admin::layouts>
    <x-slot:title>
        @lang('admin::app.leads.view.title', ['title' => strip_tags($lead->title)])
    </x-slot>

    <!-- Content -->
    <div class="relative flex gap-4 max-lg:flex-wrap">

        <!-- Left Panel -->
        {!! view_render_event('admin.leads.view.left.before', ['lead' => $lead]) !!}

        <div class="max-lg:min-w-full max-lg:max-w-full [&>div:last-child]:border-b-0 lg:sticky lg:top-[73px] flex min-w-[394px] max-w-[394px] flex-col self-start rounded-lg border border-gray-300 bg-white dark:border-gray-800 dark:bg-gray-900">
            <!-- Lead Information -->
            <div class="flex w-full flex-col gap-2 border-b border-gray-300 p-4 dark:border-gray-800">
                <!-- Breadcrumb's -->
                <div class="flex items-center justify-between">
                    <x-admin::breadcrumbs
                        name="leads.view"
                        :entity="$lead"
                    />
                </div>

                <div class="mb-2">
                    @if (($days = $lead->rotten_days) > 0)
                        @php
                            $lead->tags->prepend([
                                'name' => '<span class="icon-rotten text-base"></span>' . trans('admin::app.leads.view.rotten-days', ['days' => $days]),
                                'color' => '#FEE2E2'
                            ]);
                        @endphp
                    @endif

                    {!! view_render_event('admin.leads.view.tags.before', ['lead' => $lead]) !!}

                    <!-- Tags -->
                    <x-admin::tags
                        :attach-endpoint="route('admin.leads.tags.attach', $lead->id)"
                        :detach-endpoint="route('admin.leads.tags.detach', $lead->id)"
                        :added-tags="$lead->tags"
                    />

                    {!! view_render_event('admin.leads.view.tags.after', ['lead' => $lead]) !!}
                </div>


                {!! view_render_event('admin.leads.view.title.before', ['lead' => $lead]) !!}

                <!-- Title -->
                <h1 class="text-lg font-bold dark:text-white">
                    {{ $lead->title }}
                </h1>

                {!! view_render_event('admin.leads.view.title.after', ['lead' => $lead]) !!}

                <!-- Activity Actions -->
                <div class="flex flex-wrap gap-2">
                    {!! view_render_event('admin.leads.view.actions.before', ['lead' => $lead]) !!}

                    @if (! empty($lead->person?->contact_numbers))
                        @php
                            $whatsappDigits = preg_replace('/\D/', '', $lead->person->contact_numbers[0]['value']);
                            $whatsappNumber = $whatsappDigits && ! str_starts_with($whatsappDigits, '55')
                                ? '55'.$whatsappDigits
                                : $whatsappDigits;
                        @endphp

                        @if ($whatsappNumber)
                            <!-- WhatsApp Quick Action -->
                            <a
                                href="https://wa.me/{{ $whatsappNumber }}"
                                target="_blank"
                                class="flex h-[74px] w-[84px] flex-col items-center justify-center gap-1 rounded-lg border border-transparent bg-green-200 font-medium text-green-900 transition-all hover:border-green-400"
                            >
                                <svg viewBox="0 0 32 32" class="h-6 w-6" fill="#25D366">
                                    <path d="M16.004 3C9.374 3 4 8.373 4 15.002c0 2.474.733 4.77 1.998 6.689L4 29l7.52-1.973a11.94 11.94 0 0 0 4.484.873h.004c6.63 0 12.003-5.373 12.003-12.001C28.011 8.373 22.638 3 16.004 3zm6.997 17.06c-.297.836-1.74 1.6-2.405 1.703-.616.094-1.396.134-2.255-.14-.52-.166-1.187-.387-2.04-.756-3.59-1.55-5.934-5.146-6.115-5.388-.18-.242-1.466-1.95-1.466-3.72 0-1.77.93-2.64 1.26-3.002.33-.362.72-.453.96-.453.24 0 .48.002.69.013.221.012.518-.084.81.618.297.717 1.01 2.476 1.1 2.656.09.18.15.39.03.63-.12.24-.18.39-.36.6-.18.21-.378.469-.54.63-.18.18-.367.375-.157.735.21.36.93 1.535 1.997 2.486 1.374 1.222 2.534 1.6 2.9 1.78.366.18.58.15.793-.09.21-.24.93-1.08 1.18-1.45.24-.36.48-.3.81-.18.33.12 2.085.984 2.445 1.164.36.18.6.27.69.42.09.15.09.87-.21 1.704z"/>
                                </svg>

                                @lang('admin::app.components.activities.actions.whatsapp.btn')
                            </a>
                        @endif
                    @endif

                    @if (bouncer()->hasPermission('activities.create'))
                        <!-- Note Activity Action -->
                        <x-admin::activities.actions.note
                            :entity="$lead"
                            entity-control-name="lead_id"
                        />

                        <!-- Activity Action -->
                        <x-admin::activities.actions.activity
                            :entity="$lead"
                            entity-control-name="lead_id"
                        />
                    @endif

                    {!! view_render_event('admin.leads.view.actions.after', ['lead' => $lead]) !!}
                </div>
            </div>

            <!-- Lead Attributes -->
            @include ('admin::leads.view.attributes')

            <!-- Contact Person -->
            @include ('admin::leads.view.person')
        </div>

        {!! view_render_event('admin.leads.view.left.after', ['lead' => $lead]) !!}

        {!! view_render_event('admin.leads.view.right.before', ['lead' => $lead]) !!}

        <!-- Right Panel -->
        <div class="flex w-full flex-col gap-4 rounded-lg">
            <!-- Stages Navigation -->
            @include ('admin::leads.view.stages')

            <!-- Activities -->
            {!! view_render_event('admin.leads.view.activities.before', ['lead' => $lead]) !!}

            <x-admin::activities
                :endpoint="route('admin.leads.activities.index', $lead->id)"
                :email-detach-endpoint="route('admin.leads.emails.detach', $lead->id)"
                :activeType="in_array(request()->query('tab'), ['all', 'planned', 'note', 'meeting', 'system']) ? request()->query('tab') : 'all'"
                :types="[
                    ['name' => 'all', 'label' => trans('admin::app.components.activities.index.all')],
                    ['name' => 'planned', 'label' => trans('admin::app.components.activities.index.planned')],
                    ['name' => 'note', 'label' => trans('admin::app.components.activities.index.notes')],
                    ['name' => 'meeting', 'label' => trans('admin::app.components.activities.index.meetings')],
                ]"
            >
            </x-admin::activities>

            {!! view_render_event('admin.leads.view.activities.after', ['lead' => $lead]) !!}
        </div>

        {!! view_render_event('admin.leads.view.right.after', ['lead' => $lead]) !!}
    </div>
</x-admin::layouts>
