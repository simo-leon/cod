<x-admin::layouts>
    <x-slot:title>
        @lang('admin::app.cod.index.title', ['name' => $systemName])
    </x-slot>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <div class="grid gap-1.5">
            <p class="text-xl font-bold !leading-normal text-gray-800 dark:text-white">
                {{ $systemName }}
            </p>

            <p class="!leading-normal text-gray-600 dark:text-gray-300">
                @lang('admin::app.cod.index.subtitle')
            </p>
        </div>

        <div class="flex gap-2">
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

            <a href="{{ $settingsUrl }}" class="primary-button">
                @lang('admin::app.cod.index.settings')
            </a>
        </div>
    </div>

    <!-- Real figures from the database -->
    <div class="mt-8 grid grid-cols-4 gap-4 max-xl:grid-cols-2 max-sm:grid-cols-1">
        <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <p class="text-sm text-gray-600 dark:text-gray-300">@lang('admin::app.cod.index.orders')</p>
            <p class="mt-1 text-2xl font-bold text-gray-800 dark:text-white">{{ $ordersCount }}</p>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <p class="text-sm text-gray-600 dark:text-gray-300">@lang('admin::app.cod.index.pending-confirmation')</p>
            <p class="mt-1 text-2xl font-bold text-gray-800 dark:text-white">{{ $pendingCount }}</p>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <p class="text-sm text-gray-600 dark:text-gray-300">@lang('admin::app.cod.index.today-orders')</p>
            <p class="mt-1 text-2xl font-bold text-gray-800 dark:text-white">{{ $todayOrdersCount }}</p>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <p class="text-sm text-gray-600 dark:text-gray-300">@lang('admin::app.cod.index.parcels')</p>

            @if ($parcelsTableExists)
                <p class="mt-1 text-2xl font-bold text-gray-800 dark:text-white">{{ $parcelsCount }}</p>
            @else
                <p class="mt-1 text-sm font-semibold text-red-600 dark:text-red-400">
                    @lang('admin::app.cod.index.parcels-table-missing')
                </p>
            @endif
        </div>
    </div>

    <!-- Module status -->
    <div class="mt-8 rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
        <p class="border-b border-gray-200 p-4 text-base font-semibold text-gray-800 dark:border-gray-800 dark:text-white">
            @lang('admin::app.cod.index.modules-title')
        </p>

        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b border-gray-200 text-gray-600 dark:border-gray-800 dark:text-gray-300">
                    <th class="p-4 font-semibold">@lang('admin::app.cod.index.module')</th>
                    <th class="p-4 font-semibold">@lang('admin::app.cod.index.status')</th>
                </tr>
            </thead>

            <tbody>
                @foreach ($modules as $module)
                    <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                        <td class="p-4 text-gray-800 dark:text-white">{{ $module['name'] }}</td>

                        <td class="p-4">
                            @if ($module['status'] === 'ready')
                                <span class="rounded-full bg-green-100 px-2.5 py-1 text-xs font-semibold text-green-800 dark:bg-green-900 dark:text-green-200">
                                    @lang('admin::app.cod.index.status-ready')
                                </span>
                            @elseif ($module['status'] === 'configured')
                                <span class="rounded-full bg-green-100 px-2.5 py-1 text-xs font-semibold text-green-800 dark:bg-green-900 dark:text-green-200">
                                    @lang('admin::app.cod.index.status-configured')
                                </span>
                            @else
                                <span class="rounded-full bg-yellow-100 px-2.5 py-1 text-xs font-semibold text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200">
                                    @lang('admin::app.cod.index.status-not-configured')
                                </span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

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
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-gray-600 dark:border-gray-800 dark:text-gray-300">
                        <th class="p-4 font-semibold">@lang('admin::app.cod.index.confirmer')</th>
                        <th class="p-4 font-semibold">@lang('admin::app.cod.index.assigned')</th>
                        <th class="p-4 font-semibold">@lang('admin::app.cod.index.confirmed')</th>
                        <th class="p-4 font-semibold">@lang('admin::app.cod.index.confirmation-rate')</th>
                        <th class="p-4 font-semibold">@lang('admin::app.cod.index.delivered')</th>
                        <th class="p-4 font-semibold">@lang('admin::app.cod.index.delivery-rate')</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($confirmerRates as $rate)
                        <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                            <td class="p-4">
                                <a
                                    href="{{ route('admin.cod.confirmers.show', $rate['admin']) }}"
                                    class="font-semibold text-blue-600 hover:underline dark:text-blue-400"
                                >
                                    {{ $rate['admin']->name }}
                                </a>
                            </td>

                            <td class="p-4 text-gray-800 dark:text-white">{{ $rate['assigned'] }}</td>
                            <td class="p-4 text-gray-800 dark:text-white">{{ $rate['confirmed'] }}</td>
                            <td class="p-4 font-semibold text-gray-800 dark:text-white">{{ is_null($rate['confirmation_rate']) ? '—' : $rate['confirmation_rate'].'%' }}</td>
                            <td class="p-4 text-gray-800 dark:text-white">{{ $rate['delivered'] }}</td>
                            <td class="p-4 font-semibold text-gray-800 dark:text-white">{{ is_null($rate['delivery_rate']) ? '—' : $rate['delivery_rate'].'%' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <!-- Current COD settings -->
    <div class="mt-8 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
        <p class="text-base font-semibold text-gray-800 dark:text-white">
            @lang('admin::app.cod.index.current-settings')
        </p>

        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
            @lang('admin::app.cod.index.daily-limit', ['limit' => $confirmerDailyLimit])
        </p>

        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
            @lang('admin::app.cod.index.auto-assignment', ['value' => $autoAssignment ? __('admin::app.cod.index.yes') : __('admin::app.cod.index.no')])
        </p>

        @if ($deliveryProvider)
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                @lang('admin::app.cod.index.delivery-provider', ['provider' => $deliveryProvider])
            </p>
        @endif
    </div>
</x-admin::layouts>
