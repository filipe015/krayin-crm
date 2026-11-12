<x-admin::layouts>
    <x-slot:title>
        @lang('admin::app.dashboard.leads.title-'.$metric)
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="rounded-lg border border-gray-300 bg-white px-4 py-3 text-sm shadow-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
            <div class="text-xl font-bold dark:text-white">
                @lang('admin::app.dashboard.leads.title-'.$metric)
            </div>

            <p class="mt-1 text-gray-600 dark:text-gray-300">
                @lang('admin::app.dashboard.leads.summary-'.$metric, [
                    'count' => $leads->total(),
                    'start' => $startDate->format('d/m/Y'),
                    'end' => $endDate->format('d/m/Y'),
                ])
            </p>

            <a href="{{ route('admin.dashboard.index', ['start' => $startDate->format('Y-m-d'), 'end' => $endDate->format('Y-m-d')]) }}" class="mt-2 inline-block text-brandColor hover:underline">
                @lang('admin::app.dashboard.leads.back')
            </a>
        </div>

        <div class="overflow-x-auto rounded-lg border border-gray-300 bg-white dark:border-gray-800 dark:bg-gray-900">
            <table class="w-full text-left text-sm dark:text-gray-300">
                <thead class="border-b border-gray-200 text-xs uppercase text-gray-600 dark:border-gray-800 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-3">@lang('admin::app.dashboard.leads.lead')</th>
                        <th class="px-4 py-3">@lang('admin::app.dashboard.leads.contact')</th>
                        <th class="px-4 py-3">@lang('admin::app.dashboard.leads.pipeline')</th>
                        <th class="px-4 py-3">@lang('admin::app.dashboard.leads.stage')</th>
                        <th class="px-4 py-3">@lang('admin::app.dashboard.leads.value')</th>
                        <th class="px-4 py-3">@lang('admin::app.dashboard.leads.owner')</th>
                        <th class="px-4 py-3">@lang($metric === 'won' ? 'admin::app.dashboard.leads.closed-at' : 'admin::app.dashboard.leads.created-at')</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($leads as $lead)
                        <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                            <td class="px-4 py-3">
                                <a href="{{ route('admin.leads.view', $lead->id) }}" class="font-medium text-brandColor hover:underline">
                                    {{ $lead->title }}
                                </a>
                            </td>
                            <td class="px-4 py-3">{{ $lead->person?->name }}</td>
                            <td class="px-4 py-3">{{ $lead->pipeline?->name }}</td>
                            <td class="px-4 py-3">{{ $lead->stage?->name }}</td>
                            <td class="px-4 py-3">{{ core()->formatBasePrice($lead->lead_value ?? 0) }}</td>
                            <td class="px-4 py-3">{{ $lead->user?->name }}</td>
                            <td class="px-4 py-3">{{ ($metric === 'won' ? $lead->closed_at : $lead->created_at)?->format('d/m/Y H:i') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-6 text-center text-gray-500">
                                @lang('admin::app.dashboard.leads.empty')
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $leads->links() }}
    </div>
</x-admin::layouts>
