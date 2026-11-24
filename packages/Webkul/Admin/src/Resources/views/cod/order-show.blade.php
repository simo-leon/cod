<x-admin::layouts>
    <x-slot:title>
        @lang('admin::app.cod.order-show.title', ['id' => $codOrder->external_id])
    </x-slot>

    @php
        $callResultClasses = [
            'confirmed' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
            'no_answer' => 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200',
            'refused' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
            'postponed' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
        ];

        $phoneDigits = $codOrder->customer_phone ? preg_replace('/\D+/', '', $codOrder->customer_phone) : null;

        if ($phoneDigits && str_starts_with($phoneDigits, '0')) {
            $phoneDigits = '212'.substr($phoneDigits, 1);
        }
    @endphp

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="grid gap-1.5">
            <p class="text-xl font-bold !leading-normal text-gray-800 dark:text-white">
                @lang('admin::app.cod.order-show.title', ['id' => $codOrder->external_id])
            </p>

            <p class="!leading-normal text-gray-600 dark:text-gray-300">
                COD #{{ $codOrder->id }}
                · @lang('admin::app.cod.orders.date'): {{ $codOrder->created_at?->format('Y-m-d H:i') }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($canViewMyTasks)
                <a href="{{ $myTasksUrl }}" class="secondary-button">
                    @lang('admin::app.cod.order-show.back-to-my-tasks')
                </a>
            @endif

            <a href="{{ $ordersUrl }}" class="secondary-button">
                @lang('admin::app.cod.order-show.back-to-orders')
            </a>
        </div>
    </div>

    <div class="mt-4 flex flex-wrap items-center gap-2">
        <x-admin::cod.status-badge :status="$codOrder->status" />

        <span class="text-sm text-gray-600 dark:text-gray-300">
            @lang('admin::app.cod.orders.assigned-to'): {{ $codOrder->assignedTo?->name ?? __('admin::app.cod.order-show.unassigned') }}
        </span>

        @if ($canViewStoreOrder && $codOrder->order_id)
            <a
                href="{{ route('admin.sales.orders.view', $codOrder->order_id) }}"
                class="text-sm font-semibold text-blue-600 hover:underline dark:text-blue-400"
            >
                @lang('admin::app.cod.order-show.view-store-order')
            </a>
        @endif

        @if ($canLogCall && $codOrder->status === 'pending_confirmation')
            <form method="POST" action="{{ route('admin.cod.orders.resend-confirmation', $codOrder) }}">
                @csrf

                <button type="submit" class="secondary-button">
                    @lang('admin::app.cod.order-show.resend-confirmation')
                </button>
            </form>
        @endif
    </div>

    <div class="mt-4 grid gap-4 md:grid-cols-2">
        <!-- Customer -->
        <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <p class="text-base font-semibold text-gray-800 dark:text-white">
                @lang('admin::app.cod.orders.customer')
            </p>

            <div class="mt-2 grid gap-2 text-sm">
                <p class="font-semibold text-gray-800 dark:text-white">{{ $codOrder->customer_name ?? '—' }}</p>

                @if ($codOrder->customer_phone)
                    <p class="text-gray-800 dark:text-white">
                        <span class="text-gray-500 dark:text-gray-400">@lang('admin::app.cod.orders.phone'): </span>
                        <a href="tel:{{ $phoneDigits }}" dir="ltr" class="font-semibold tabular-nums text-blue-600 hover:underline dark:text-blue-400">{{ $codOrder->customer_phone }}</a>

                        @if ($phoneDigits)
                            <a
                                href="https://wa.me/{{ $phoneDigits }}"
                                target="_blank"
                                class="whitespace-nowrap font-semibold text-green-600 hover:underline"
                            >
                                WhatsApp
                            </a>
                        @endif
                    </p>
                @endif

                @if ($codOrder->customer_email)
                    <p class="text-gray-800 dark:text-white">
                        <span class="text-gray-500 dark:text-gray-400">@lang('admin::app.cod.order-show.email'): </span>
                        {{ $codOrder->customer_email }}
                    </p>
                @endif

                @if ($codOrder->city)
                    <p class="text-gray-800 dark:text-white">
                        <span class="text-gray-500 dark:text-gray-400">@lang('admin::app.cod.orders.city'): </span>
                        {{ $codOrder->city }}
                    </p>
                @endif

                @if ($codOrder->address)
                    <p class="text-gray-800 dark:text-white">
                        <span class="text-gray-500 dark:text-gray-400">@lang('admin::app.cod.order-show.address'): </span>
                        {{ $codOrder->address }}
                    </p>
                @endif
            </div>
        </div>

        <!-- Order info -->
        <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <p class="text-base font-semibold text-gray-800 dark:text-white">
                @lang('admin::app.cod.order-show.order-info')
            </p>

            <div class="mt-2 grid gap-2 text-sm">
                <p class="text-gray-800 dark:text-white">
                    <span class="text-gray-500 dark:text-gray-400">@lang('admin::app.cod.orders.total'): </span>
                    <span class="font-semibold tabular-nums">{{ number_format((float) $codOrder->grand_total, 2) }} {{ $codOrder->currency_code ?? '' }}</span>
                </p>

                <p class="text-gray-800 dark:text-white">
                    <span class="text-gray-500 dark:text-gray-400">@lang('admin::app.cod.orders.source'): </span>
                    @lang('admin::app.cod.orders.sources.'.$codOrder->source)
                </p>

                @if ($codOrder->store_status)
                    <p class="text-gray-800 dark:text-white">
                        <span class="text-gray-500 dark:text-gray-400">@lang('admin::app.cod.order-show.store-status'): </span>
                        {{ $codOrder->store_status }}
                    </p>
                @endif

                <p class="text-gray-800 dark:text-white">
                    <span class="text-gray-500 dark:text-gray-400">@lang('admin::app.cod.my-tasks.attempts'): </span>
                    <span class="tabular-nums">{{ $codOrder->confirmation_attempts }}</span>
                </p>

                @if ($codOrder->callback_at)
                    <p class="text-gray-800 dark:text-white">
                        <span class="text-gray-500 dark:text-gray-400">@lang('admin::app.cod.my-tasks.callback-at', ['date' => $codOrder->callback_at->format('Y-m-d H:i')])</span>
                    </p>
                @endif
            </div>
        </div>
    </div>

    <!-- Items -->
    <div class="mt-4 overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
        <p class="px-3 pt-3 text-base font-semibold text-gray-800 dark:text-white">
            @lang('admin::app.cod.order-show.items')
        </p>

        @if (empty($items))
            <p class="p-8 text-center text-sm text-gray-600 dark:text-gray-300">—</p>
        @else
            <table class="mt-2 w-full min-w-[920px] text-start text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-gray-600 dark:border-gray-800 dark:text-gray-300">
                        <th class="whitespace-nowrap px-3 py-3 text-start font-semibold">@lang('admin::app.cod.order-show.sku')</th>
                        <th class="whitespace-nowrap px-3 py-3 text-start font-semibold">@lang('admin::app.cod.order-show.product')</th>
                        <th class="whitespace-nowrap px-3 py-3 text-start font-semibold">@lang('admin::app.cod.order-show.qty')</th>
                        <th class="whitespace-nowrap px-3 py-3 text-start font-semibold">@lang('admin::app.cod.order-show.unit-price')</th>
                        <th class="whitespace-nowrap px-3 py-3 text-start font-semibold">@lang('admin::app.cod.order-show.line-total')</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($items as $item)
                        <tr class="border-b border-gray-100 align-top last:border-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-800/50">
                            <td class="whitespace-nowrap px-3 py-3 tabular-nums text-gray-800 dark:text-white">{{ $item['sku'] }}</td>
                            <td class="px-3 py-3 text-gray-800 dark:text-white">{{ $item['name'] }}</td>
                            <td class="whitespace-nowrap px-3 py-3 tabular-nums text-gray-800 dark:text-white">{{ $item['qty'] }}</td>
                            <td class="whitespace-nowrap px-3 py-3 tabular-nums text-gray-800 dark:text-white">{{ $item['price'] !== null ? number_format($item['price'], 2) : '—' }}</td>
                            <td class="whitespace-nowrap px-3 py-3 tabular-nums text-gray-800 dark:text-white">{{ $item['price'] !== null ? number_format($item['qty'] * $item['price'], 2) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    @if ($canViewSouk && $soukTasks->isNotEmpty())
    <!-- Souk sourcing for this order -->
    <div class="mt-4 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
        <p class="text-base font-semibold text-gray-800 dark:text-white">
            @lang('admin::app.cod.order-show.souk-title')
        </p>

        <div class="mt-3 grid gap-2">
            @foreach ($soukTasks as $soukTask)
                <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-800">
                    <div class="grid gap-0.5">
                        <span class="font-semibold text-gray-800 dark:text-white">
                            {{ $soukTask->product?->name ?? $soukTask->product?->sku ?? '—' }}
                            · @lang('admin::app.cod.souk.qty'): {{ $soukTask->qty }}
                        </span>

                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            @lang('admin::app.cod.souk.order-card.buyer', ['buyer' => $soukTask->buyer?->name ?? trans('admin::app.cod.souk.order-card.no-buyer')])
                            @if ($soukTask->purchase_price !== null)
                                · @lang('admin::app.cod.souk.order-card.purchase-price', ['price' => number_format((float) $soukTask->purchase_price, 2)])
                            @endif
                        </span>
                    </div>

                    <x-admin::cod.status-badge :status="$soukTask->status" :label="trans('admin::app.cod.souk.statuses.'.$soukTask->status)" />
                </div>
            @endforeach
        </div>

        <a href="{{ $soukUrl }}" class="mt-3 inline-block text-sm font-semibold text-blue-600 hover:underline dark:text-blue-400">
            @lang('admin::app.cod.souk.order-card.view-board')
        </a>
    </div>
    @endif

    <!-- Call history -->
    <div class="mt-4 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
        <p class="text-base font-semibold text-gray-800 dark:text-white">
            @lang('admin::app.cod.order-show.call-history')
        </p>

        @if ($codOrder->callLogs->isEmpty())
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                @lang('admin::app.cod.my-tasks.no-calls-yet')
            </p>
        @else
            <div class="mt-2 divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($codOrder->callLogs as $call)
                    <div class="grid gap-1.5 py-3 last:pb-0">
                        <p class="flex flex-wrap items-center gap-2 text-sm">
                            <span class="whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold {{ $callResultClasses[$call->result] ?? 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200' }}">
                                @lang('admin::app.cod.calls.results.'.$call->result)
                            </span>

                            <span class="text-gray-800 dark:text-white">{{ $call->confirmer?->name ?? '—' }}</span>

                            <span class="whitespace-nowrap tabular-nums text-gray-500 dark:text-gray-400">{{ $call->called_at?->format('Y-m-d H:i') }}</span>

                            @if ($call->callback_at)
                                <span class="whitespace-nowrap tabular-nums text-gray-500 dark:text-gray-400">
                                    @lang('admin::app.cod.my-tasks.callback-at', ['date' => $call->callback_at->format('Y-m-d H:i')])
                                </span>
                            @endif
                        </p>

                        @if ($call->note)
                            <p class="text-sm text-gray-600 dark:text-gray-300">{{ $call->note }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <!-- Record call -->
    @if ($canLogCall && $codOrder->status === 'pending_confirmation')
        <div class="mt-4 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <p class="text-base font-semibold text-gray-800 dark:text-white">
                @lang('admin::app.cod.my-tasks.record-call')
            </p>

            @if ($errors->any())
                <div class="mt-2 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                    @foreach ($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('admin.cod.orders.calls.store', $codOrder) }}" class="mt-2 grid gap-2">
                @csrf

                <select name="result" required class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 dark:border-gray-800 dark:bg-gray-900 dark:text-white">
                    <option value="" disabled selected>@lang('admin::app.cod.calls.choose-result')</option>

                    @foreach ($results as $result)
                        <option value="{{ $result }}">
                            @lang('admin::app.cod.calls.results.'.$result)
                        </option>
                    @endforeach
                </select>

                <input
                    type="text"
                    name="note"
                    maxlength="1000"
                    placeholder="@lang('admin::app.cod.calls.note-placeholder')"
                    class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                >

                <input
                    type="datetime-local"
                    name="callback_at"
                    title="@lang('admin::app.cod.calls.callback-at')"
                    class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                >

                <button type="submit" class="primary-button justify-center">
                    @lang('admin::app.cod.my-tasks.save-call')
                </button>
            </form>
        </div>
    @endif
</x-admin::layouts>
