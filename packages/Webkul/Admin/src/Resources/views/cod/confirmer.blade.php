<x-admin::layouts>
    <x-slot:title>
        @lang('admin::app.cod.confirmer.title', ['name' => $confirmer->name])
    </x-slot>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="grid gap-1.5">
            <p class="text-xl font-bold !leading-normal text-gray-800 dark:text-white">
                {{ $confirmer->name }}
            </p>

            <p class="!leading-normal text-gray-600 dark:text-gray-300">
                {{ $confirmer->email }}
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            <a href="{{ $myTasksUrl }}" class="secondary-button">
                @lang('admin::app.cod.confirmer.view-tasks')
            </a>

            <a href="{{ $dashboardUrl }}" class="secondary-button">
                @lang('admin::app.cod.confirmer.back-to-dashboard')
            </a>
        </div>
    </div>

    <!-- Confirmation and delivery rates -->
    <div class="mt-8 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
        <x-admin::cod.stat-card :label="__('admin::app.cod.confirmer.assigned')" :value="$rate['assigned']" />

        <x-admin::cod.stat-card :label="__('admin::app.cod.confirmer.confirmed')" :value="$rate['confirmed']" />

        <x-admin::cod.stat-card :label="__('admin::app.cod.confirmer.confirmation-rate')" :value="is_null($rate['confirmation_rate']) ? '—' : $rate['confirmation_rate'].'%'" />

        <x-admin::cod.stat-card :label="__('admin::app.cod.confirmer.delivered')" :value="$rate['delivered']" />

        <x-admin::cod.stat-card :label="__('admin::app.cod.confirmer.delivery-rate')" :value="is_null($rate['delivery_rate']) ? '—' : $rate['delivery_rate'].'%'" />
    </div>

    <!-- Filter by COD status -->
    <div class="mt-8 flex flex-wrap gap-2">
        <a
            href="{{ route('admin.cod.confirmers.show', $confirmer) }}"
            class="whitespace-nowrap rounded-full px-3 py-1.5 text-xs font-semibold {{ is_null($status) ? 'bg-blue-600 text-white' : 'bg-white text-gray-700 ring-1 ring-gray-200 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-800' }}"
        >
            @lang('admin::app.cod.orders.all') ({{ $orders->total() }})
        </a>

        @foreach ($statuses as $filterStatus)
            <a
                href="{{ route('admin.cod.confirmers.show', ['admin' => $confirmer->id, 'status' => $filterStatus]) }}"
                class="whitespace-nowrap rounded-full px-3 py-1.5 text-xs font-semibold {{ $status === $filterStatus ? 'bg-blue-600 text-white' : 'bg-white text-gray-700 ring-1 ring-gray-200 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-800' }}"
            >
                @lang('admin::app.cod.orders.statuses.'.$filterStatus)
            </a>
        @endforeach
    </div>

    <!-- Assigned orders -->
    <div class="mt-4 overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
        @if ($orders->isEmpty())
            <p class="p-8 text-center text-sm text-gray-600 dark:text-gray-300">
                @lang('admin::app.cod.confirmer.no-orders')
            </p>
        @else
            <table class="w-full min-w-[880px] text-start text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-gray-600 dark:border-gray-800 dark:text-gray-300">
                        <th class="whitespace-nowrap px-3 py-3 text-start font-semibold">@lang('admin::app.cod.orders.order')</th>
                        <th class="whitespace-nowrap px-3 py-3 text-start font-semibold">@lang('admin::app.cod.orders.customer')</th>
                        <th class="whitespace-nowrap px-3 py-3 text-start font-semibold">@lang('admin::app.cod.orders.phone')</th>
                        <th class="whitespace-nowrap px-3 py-3 text-start font-semibold">@lang('admin::app.cod.orders.city')</th>
                        <th class="whitespace-nowrap px-3 py-3 text-start font-semibold">@lang('admin::app.cod.orders.total')</th>
                        <th class="whitespace-nowrap px-3 py-3 text-start font-semibold">@lang('admin::app.cod.orders.status')</th>
                        <th class="whitespace-nowrap px-3 py-3 text-start font-semibold">@lang('admin::app.cod.my-tasks.attempts')</th>
                        <th class="whitespace-nowrap px-3 py-3 text-start font-semibold">@lang('admin::app.cod.orders.date')</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($orders as $codOrder)
                        <tr class="border-b border-gray-100 align-top last:border-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-800/50">
                            <td class="whitespace-nowrap px-3 py-3 font-semibold text-gray-800 dark:text-white">
                                #{{ $codOrder->external_id }}
                            </td>

                            <td class="whitespace-nowrap px-3 py-3 text-gray-800 dark:text-white">
                                {{ $codOrder->customer_name ?? '—' }}
                            </td>

                            <td class="whitespace-nowrap px-3 py-3 text-gray-800 dark:text-white" dir="ltr">
                                {{ $codOrder->customer_phone ?? '—' }}
                            </td>

                            <td class="whitespace-nowrap px-3 py-3 text-gray-800 dark:text-white">
                                {{ $codOrder->city ?? '—' }}
                            </td>

                            <td class="whitespace-nowrap px-3 py-3 tabular-nums text-gray-800 dark:text-white">
                                {{ number_format((float) $codOrder->grand_total, 2) }} {{ $codOrder->currency_code ?? '' }}
                            </td>

                            <td class="px-3 py-3">
                                <x-admin::cod.status-badge :status="$codOrder->status" />
                            </td>

                            <td class="px-3 py-3 text-center tabular-nums text-gray-800 dark:text-white">
                                {{ $codOrder->confirmation_attempts }}
                            </td>

                            <td class="whitespace-nowrap px-3 py-3 tabular-nums text-gray-600 dark:text-gray-300">
                                {{ $codOrder->created_at?->format('Y-m-d H:i') }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    @if ($orders->hasPages())
        <div class="mt-4">
            {{ $orders->links() }}
        </div>
    @endif
</x-admin::layouts>
