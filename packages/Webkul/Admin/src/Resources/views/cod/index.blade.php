<x-admin::layouts>
    <x-slot:title>
        @lang('admin::app.cod.index.title', ['name' => $systemName])
    </x-slot>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="grid gap-1.5">
            <p class="text-xl font-bold !leading-normal text-gray-800 dark:text-white">
                {{ $systemName }}
            </p>

            <p class="!leading-normal text-gray-600 dark:text-gray-300">
                @lang('admin::app.cod.index.subtitle')
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            <a href="{{ $myTasksUrl }}" class="secondary-button">
                @lang('admin::app.cod.index.view-my-tasks')
            </a>

            @if ($canManageAssignment)
                <a href="{{ $assignmentUrl }}" class="secondary-button">
                    @lang('admin::app.cod.index.manage-assignment')
                </a>
            @endif

            <a href="{{ $ordersUrl }}" class="secondary-button">
                @lang('admin::app.cod.index.view-orders')
            </a>

            @if ($canViewAnalytics)
                <a href="{{ $analyticsUrl }}" class="secondary-button">
                    @lang('admin::app.cod.index.view-analytics')
                </a>
            @endif

            @if ($canViewSouk)
                <a href="{{ $soukUrl }}" class="secondary-button">
                    @lang('admin::app.cod.index.view-souk')
                </a>
            @endif

            <a href="{{ $settingsUrl }}" class="primary-button whitespace-nowrap">
                @lang('admin::app.cod.index.settings')
            </a>
        </div>
    </div>

    @if ($webhookUnprotected)
        <div class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-800 dark:border-rose-900 dark:bg-rose-900/30 dark:text-rose-200">
            @lang('admin::app.cod.index.webhook-unprotected')
        </div>
    @endif

    @if ($whatsappNoProvider)
        <div class="mt-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm font-semibold text-amber-800 dark:border-amber-900 dark:bg-amber-900/30 dark:text-amber-200">
            @lang('admin::app.cod.index.whatsapp-no-provider')
        </div>
    @endif

    @if (! $staffOnly)
    <!-- Real figures from the database -->
    <div class="mt-8 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-admin::cod.stat-card :label="__('admin::app.cod.index.orders')" :value="$ordersCount" />

        <x-admin::cod.stat-card :label="__('admin::app.cod.index.pending-confirmation')" :value="$pendingCount" />

        <x-admin::cod.stat-card :label="__('admin::app.cod.index.today-orders')" :value="$todayOrdersCount" />

        <div class="min-w-0 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <p class="truncate text-sm text-gray-600 dark:text-gray-300">@lang('admin::app.cod.index.parcels')</p>

            @if ($parcelsTableExists)
                <p class="mt-1 text-2xl font-bold tabular-nums text-gray-800 dark:text-white">{{ $parcelsCount }}</p>
            @else
                <p class="mt-1 text-sm font-semibold leading-relaxed text-red-600 dark:text-red-400">
                    @lang('admin::app.cod.index.parcels-table-missing')
                </p>
            @endif
        </div>
    </div>
    @else
    <div class="mt-8 rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
        @lang('admin::app.cod.index.staff-only-note')
    </div>
    @endif

    <!-- Module status and current settings, side by side -->
    <div class="mt-8 grid gap-4 lg:grid-cols-2">
        <div class="rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
            <p class="border-b border-gray-200 p-4 text-base font-semibold text-gray-800 dark:border-gray-800 dark:text-white">
                @lang('admin::app.cod.index.modules-title')
            </p>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[320px] text-start text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-600 dark:border-gray-800 dark:text-gray-300">
                            <th class="whitespace-nowrap p-4 text-start font-semibold">@lang('admin::app.cod.index.module')</th>
                            <th class="whitespace-nowrap p-4 text-start font-semibold">@lang('admin::app.cod.index.status')</th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($modules as $module)
                            <tr class="border-b border-gray-100 last:border-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-800/50">
                                <td class="p-4 text-gray-800 dark:text-white">{{ $module['name'] }}</td>

                                <td class="p-4">
                                    @if ($module['status'] === 'ready' || $module['status'] === 'configured')
                                        <span class="whitespace-nowrap rounded-full bg-green-100 px-2.5 py-1 text-xs font-semibold text-green-800 dark:bg-green-900 dark:text-green-200">
                                            @lang('admin::app.cod.index.status-'.$module['status'])
                                        </span>
                                    @else
                                        <span class="whitespace-nowrap rounded-full bg-yellow-100 px-2.5 py-1 text-xs font-semibold text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200">
                                            @lang('admin::app.cod.index.status-not-configured')
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Current COD settings -->
        <div class="rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
            <p class="border-b border-gray-200 p-4 text-base font-semibold text-gray-800 dark:border-gray-800 dark:text-white">
                @lang('admin::app.cod.index.current-settings')
            </p>

            <div class="flex items-center justify-between gap-4 border-b border-gray-100 px-4 py-3 last:border-0 dark:border-gray-800">
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    @lang('admin::app.cod.index.daily-limit', ['limit' => ''])
                </p>

                <p class="whitespace-nowrap text-sm font-semibold tabular-nums text-gray-800 dark:text-white">{{ $confirmerDailyLimit }}</p>
            </div>

            <div class="flex items-center justify-between gap-4 border-b border-gray-100 px-4 py-3 last:border-0 dark:border-gray-800">
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    @lang('admin::app.cod.index.auto-assignment', ['value' => ''])
                </p>

                <p class="whitespace-nowrap text-sm font-semibold text-gray-800 dark:text-white">{{ $autoAssignment ? __('admin::app.cod.index.yes') : __('admin::app.cod.index.no') }}</p>
            </div>

            @if ($deliveryProvider)
                <div class="flex items-center justify-between gap-4 border-b border-gray-100 px-4 py-3 last:border-0 dark:border-gray-800">
                    <p class="text-sm text-gray-600 dark:text-gray-300">
                        @lang('admin::app.cod.index.delivery-provider', ['provider' => ''])
                    </p>

                    <p class="whitespace-nowrap text-sm font-semibold text-gray-800 dark:text-white">{{ $deliveryProvider }}</p>
                </div>
            @endif
        </div>
    </div>

    @unless ($staffOnly)
    <!-- Confirmer rates (phase 3) -->
    <div class="mt-8 rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
        <p class="border-b border-gray-200 p-4 text-base font-semibold text-gray-800 dark:border-gray-800 dark:text-white">
            @lang('admin::app.cod.index.confirmer-rates-title')
        </p>

        @if (empty($confirmerRates))
            <p class="p-4 text-sm text-gray-600 dark:text-gray-300">
                @lang('admin::app.cod.index.no-confirmers')
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[640px] text-start text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-600 dark:border-gray-800 dark:text-gray-300">
                            <th class="whitespace-nowrap p-4 text-start font-semibold">@lang('admin::app.cod.index.confirmer')</th>
                            <th class="whitespace-nowrap p-4 text-start font-semibold">@lang('admin::app.cod.index.assigned')</th>
                            <th class="whitespace-nowrap p-4 text-start font-semibold">@lang('admin::app.cod.index.confirmed')</th>
                            <th class="whitespace-nowrap p-4 text-start font-semibold">@lang('admin::app.cod.index.confirmation-rate')</th>
                            <th class="whitespace-nowrap p-4 text-start font-semibold">@lang('admin::app.cod.index.delivered')</th>
                            <th class="whitespace-nowrap p-4 text-start font-semibold">@lang('admin::app.cod.index.delivery-rate')</th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($confirmerRates as $rate)
                            <tr class="border-b border-gray-100 last:border-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-800/50">
                                <td class="p-4">
                                    <a
                                        href="{{ route('admin.cod.confirmers.show', $rate['admin']) }}"
                                        class="font-semibold text-blue-600 hover:underline dark:text-blue-400"
                                    >
                                        {{ $rate['admin']->name }}
                                    </a>
                                </td>

                                <td class="p-4 tabular-nums text-gray-800 dark:text-white">{{ $rate['assigned'] }}</td>
                                <td class="p-4 tabular-nums text-gray-800 dark:text-white">{{ $rate['confirmed'] }}</td>
                                <td class="p-4 font-semibold tabular-nums text-gray-800 dark:text-white">{{ is_null($rate['confirmation_rate']) ? '—' : $rate['confirmation_rate'].'%' }}</td>
                                <td class="p-4 tabular-nums text-gray-800 dark:text-white">{{ $rate['delivered'] }}</td>
                                <td class="p-4 font-semibold tabular-nums text-gray-800 dark:text-white">{{ is_null($rate['delivery_rate']) ? '—' : $rate['delivery_rate'].'%' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
    @endunless
</x-admin::layouts>
