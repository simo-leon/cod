<x-admin::layouts>
    <x-slot:title>
        @lang('admin::app.cod.orders.title')
    </x-slot>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <div class="grid gap-1.5">
            <p class="text-xl font-bold !leading-normal text-gray-800 dark:text-white">
                @lang('admin::app.cod.orders.title')
            </p>

            <p class="!leading-normal text-gray-600 dark:text-gray-300">
                @lang('admin::app.cod.orders.subtitle')
            </p>
        </div>

        <div class="flex gap-2">
            @if ($canManageAssignment && $unassignedCount > 0)
                <form method="POST" action="{{ route('admin.cod.assignment.distribute') }}">
                    @csrf

                    <button type="submit" class="primary-button">
                        @lang('admin::app.cod.orders.distribute', ['count' => $unassignedCount])
                    </button>
                </form>
            @endif

            <a href="{{ $dashboardUrl }}" class="secondary-button">
                @lang('admin::app.cod.orders.back-to-dashboard')
            </a>
        </div>
    </div>

    <!-- Filter by COD status -->
    <div class="mt-8 flex flex-wrap gap-2">
        <a
            href="{{ route('admin.cod.orders.index') }}"
            class="rounded-full px-3 py-1.5 text-xs font-semibold {{ is_null($status) ? 'bg-blue-600 text-white' : 'bg-white text-gray-700 ring-1 ring-gray-200 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-800' }}"
        >
            @lang('admin::app.cod.orders.all') ({{ $orders->total() }})
        </a>

        @foreach ($statuses as $filterStatus)
            <a
                href="{{ route('admin.cod.orders.index', ['status' => $filterStatus]) }}"
                class="rounded-full px-3 py-1.5 text-xs font-semibold {{ $status === $filterStatus ? 'bg-blue-600 text-white' : 'bg-white text-gray-700 ring-1 ring-gray-200 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-800' }}"
            >
                @lang('admin::app.cod.orders.statuses.'.$filterStatus) ({{ $statusCounts[$filterStatus] ?? 0 }})
            </a>
        @endforeach
    </div>

    <!-- Imported COD orders -->
    <div class="mt-4 overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
        @if ($orders->isEmpty())
            <p class="p-8 text-center text-sm text-gray-600 dark:text-gray-300">
                @lang('admin::app.cod.orders.empty')
            </p>
        @else
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-gray-600 dark:border-gray-800 dark:text-gray-300">
                        <th class="p-4 font-semibold">@lang('admin::app.cod.orders.order')</th>
                        <th class="p-4 font-semibold">@lang('admin::app.cod.orders.store-order')</th>
                        <th class="p-4 font-semibold">@lang('admin::app.cod.orders.customer')</th>
                        <th class="p-4 font-semibold">@lang('admin::app.cod.orders.phone')</th>
                        <th class="p-4 font-semibold">@lang('admin::app.cod.orders.city')</th>
                        <th class="p-4 font-semibold">@lang('admin::app.cod.orders.total')</th>
                        <th class="p-4 font-semibold">@lang('admin::app.cod.orders.status')</th>
                        <th class="p-4 font-semibold">@lang('admin::app.cod.orders.assigned-to')</th>
                        <th class="p-4 font-semibold">@lang('admin::app.cod.orders.source')</th>
                        <th class="p-4 font-semibold">@lang('admin::app.cod.orders.date')</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($orders as $codOrder)
                        <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                            <td class="p-4 font-semibold text-gray-800 dark:text-white">
                                #{{ $codOrder->id }}
                            </td>

                            <td class="p-4">
                                @if ($codOrder->order_id)
                                    <a
                                        href="{{ route('admin.sales.orders.view', $codOrder->order_id) }}"
                                        class="font-semibold text-blue-600 hover:underline dark:text-blue-400"
                                    >
                                        #{{ $codOrder->external_id }}
                                    </a>
                                @else
                                    <span class="text-gray-800 dark:text-white">#{{ $codOrder->external_id }}</span>
                                @endif
                            </td>

                            <td class="p-4 text-gray-800 dark:text-white">
                                {{ $codOrder->customer_name ?? '—' }}

                                @if ($codOrder->customer_email)
                                    <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $codOrder->customer_email }}</span>
                                @endif
                            </td>

                            <td class="p-4 text-gray-800 dark:text-white" dir="ltr">
                                {{ $codOrder->customer_phone ?? '—' }}
                            </td>

                            <td class="p-4 text-gray-800 dark:text-white">
                                {{ $codOrder->city ?? '—' }}
                            </td>

                            <td class="p-4 whitespace-nowrap text-gray-800 dark:text-white">
                                {{ number_format((float) $codOrder->grand_total, 2) }} {{ $codOrder->currency_code ?? '' }}
                            </td>

                            <td class="p-4">
                                <span class="whitespace-nowrap rounded-full bg-blue-100 px-2.5 py-1 text-xs font-semibold text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                                    @lang('admin::app.cod.orders.statuses.'.$codOrder->status)
                                </span>
                            </td>

                            <td class="p-4 text-gray-800 dark:text-white">
                                @if ($canManageAssignment && $codOrder->status === 'pending_confirmation')
                                    <form method="POST" action="{{ route('admin.cod.orders.assign', $codOrder) }}" class="flex items-center gap-2">
                                        @csrf

                                        <select name="confirmer_id" required class="rounded-lg border border-gray-200 bg-white px-2 py-1.5 text-xs text-gray-800 dark:border-gray-800 dark:bg-gray-900 dark:text-white">
                                            <option value="" disabled @selected(! $codOrder->assigned_to)>@lang('admin::app.cod.orders.choose-confirmer')</option>

                                            @foreach ($confirmers as $confirmer)
                                                <option value="{{ $confirmer->id }}" @selected($codOrder->assigned_to === $confirmer->id)>
                                                    {{ $confirmer->name }}
                                                </option>
                                            @endforeach
                                        </select>

                                        <button type="submit" class="whitespace-nowrap rounded-lg bg-blue-600 px-2.5 py-1.5 text-xs font-semibold text-white hover:bg-blue-700">
                                            @lang('admin::app.cod.orders.assign')
                                        </button>
                                    </form>
                                @else
                                    {{ $codOrder->assignedTo?->name ?? '—' }}
                                @endif
                            </td>

                            <td class="p-4 text-gray-800 dark:text-white">
                                @lang('admin::app.cod.orders.sources.'.$codOrder->source)
                            </td>

                            <td class="whitespace-nowrap p-4 text-gray-600 dark:text-gray-300">
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
