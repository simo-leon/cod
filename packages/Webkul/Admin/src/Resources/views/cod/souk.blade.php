<x-admin::layouts>
    <x-slot:title>
        @lang('admin::app.cod.souk.title')
    </x-slot>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="grid gap-1.5">
            <p class="text-xl font-bold !leading-normal text-gray-800 dark:text-white">
                @lang('admin::app.cod.souk.title')
            </p>

            <p class="!leading-normal text-gray-600 dark:text-gray-300">
                @lang('admin::app.cod.souk.subtitle')
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            <a href="{{ $settingsUrl }}" class="secondary-button">
                @lang('admin::app.cod.souk.settings')
            </a>

            <a href="{{ $dashboardUrl }}" class="secondary-button">
                @lang('admin::app.cod.souk.back-to-dashboard')
            </a>
        </div>
    </div>

    @if ($errors->any())
        <div class="mt-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    @if (! $enabled)
        <div class="mt-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-900/30 dark:text-amber-200">
            @lang('admin::app.cod.souk.disabled-warning')
        </div>
    @endif

    @if ($escalatedCount > 0)
        <div class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-800 dark:border-rose-900 dark:bg-rose-900/30 dark:text-rose-200">
            @lang('admin::app.cod.souk.escalated-banner', ['count' => $escalatedCount])
        </div>
    @endif

    @if ($uncoveredOrdersCount > 0)
        <div class="mt-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm font-semibold text-amber-800 dark:border-amber-900 dark:bg-amber-900/30 dark:text-amber-200">
            @lang('admin::app.cod.souk.uncovered-banner', ['count' => $uncoveredOrdersCount])
        </div>
    @endif

    <!-- Figures -->
    <div class="mt-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-admin::cod.stat-card
            :label="trans('admin::app.cod.souk.stats.available')"
            :value="$tasks->where('status', 'assigned')->count()"
            :hint="trans('admin::app.cod.souk.stats.available-hint')"
        />

        <x-admin::cod.stat-card
            :label="trans('admin::app.cod.souk.stats.claimed')"
            :value="$tasks->whereIn('status', ['claimed', 'purchased', 'received'])->count()"
            :hint="trans('admin::app.cod.souk.stats.claimed-hint')"
        />

        <x-admin::cod.stat-card
            :label="trans('admin::app.cod.souk.stats.escalated')"
            :value="$tasks->where('is_escalated', true)->count()"
            :hint="trans('admin::app.cod.souk.stats.escalated-hint')"
        />

        <x-admin::cod.stat-card
            :label="trans('admin::app.cod.souk.stats.awaiting-payment')"
            :value="$tasks->where('status', 'verified')->count()"
            :hint="trans('admin::app.cod.souk.stats.awaiting-payment-hint')"
        />
    </div>

    <!-- Panel settings summary -->
    <div class="mt-4 rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
        <span class="font-semibold text-gray-800 dark:text-white">@lang('admin::app.cod.souk.settings-summary'):</span>
        @lang('admin::app.cod.souk.settings-line', [
            'hours' => $claimTimeoutHours,
            'cap' => $defaultPriceCap !== null ? number_format($defaultPriceCap, 2).' DH' : trans('admin::app.cod.souk.no-cap'),
            'margin' => $defaultMinMarginPercent !== null ? number_format($defaultMinMarginPercent, 2).'%' : trans('admin::app.cod.souk.no-margin'),
            'model' => trans('admin::app.cod.souk.payment-models.'.$paymentModel),
        ])
    </div>

    <!-- Live tasks -->
    <div class="mt-8">
        <p class="text-lg font-bold text-gray-800 dark:text-white">
            @lang('admin::app.cod.souk.open-tasks')
        </p>

        <div class="mt-4 overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
            @if ($tasks->isEmpty())
                <p class="p-8 text-center text-sm text-gray-600 dark:text-gray-300">
                    @lang('admin::app.cod.souk.empty')
                </p>
            @else
                <table class="w-full min-w-[860px] text-start text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-600 dark:border-gray-800 dark:text-gray-300">
                            <th class="whitespace-nowrap px-2 py-3 text-start font-semibold">@lang('admin::app.cod.souk.product')</th>
                            <th class="whitespace-nowrap px-2 py-3 text-start font-semibold">@lang('admin::app.cod.souk.qty')</th>
                            <th class="whitespace-nowrap px-2 py-3 text-start font-semibold">@lang('admin::app.cod.souk.sale-price')</th>
                            <th class="whitespace-nowrap px-2 py-3 text-start font-semibold">@lang('admin::app.cod.souk.status')</th>
                            <th class="whitespace-nowrap px-2 py-3 text-start font-semibold">@lang('admin::app.cod.souk.buyer')</th>
                            <th class="whitespace-nowrap px-2 py-3 text-start font-semibold">@lang('admin::app.cod.souk.purchase-price')</th>
                            <th class="whitespace-nowrap px-2 py-3 text-start font-semibold">@lang('admin::app.cod.souk.deadline')</th>
                            <th class="whitespace-nowrap px-2 py-3 text-start font-semibold">@lang('admin::app.cod.souk.actions')</th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($tasks as $task)
                            <tr class="border-b border-gray-100 align-top last:border-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-800/50">
                                <td class="px-2 py-3 text-gray-800 dark:text-white">
                                    <a
                                        href="{{ route('admin.catalog.products.edit', $task->product_id) }}"
                                        class="font-semibold text-blue-600 hover:underline dark:text-blue-400"
                                    >
                                        {{ $task->product?->name ?? $task->product?->sku ?? '—' }}
                                    </a>

                                    <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $task->product?->sku ?? '' }}</span>

                                    @if ($task->cod_order_id)
                                        <span class="block text-xs text-gray-500 dark:text-gray-400">
                                            @lang('admin::app.cod.souk.cod-order', ['id' => $task->cod_order_id])
                                        </span>
                                    @endif

                                    @if ($task->is_escalated)
                                        <span class="mt-1 inline-block whitespace-nowrap rounded-full bg-rose-100 px-2 py-0.5 text-xs font-semibold text-rose-800 dark:bg-rose-900 dark:text-rose-200">
                                            @lang('admin::app.cod.souk.escalated')
                                        </span>
                                    @endif
                                </td>

                                <td class="whitespace-nowrap px-2 py-3 tabular-nums text-gray-800 dark:text-white">
                                    {{ $task->qty }}
                                </td>

                                <td class="whitespace-nowrap px-2 py-3 tabular-nums text-gray-800 dark:text-white">
                                    {{ $task->sale_price !== null ? number_format((float) $task->sale_price, 2).' DH' : '—' }}
                                </td>

                                <td class="px-2 py-3">
                                    <x-admin::cod.status-badge :status="$task->status" :label="trans('admin::app.cod.souk.statuses.'.$task->status)" />
                                </td>

                                <td class="whitespace-nowrap px-2 py-3 text-gray-800 dark:text-white">
                                    {{ $task->buyer?->name ?? '—' }}
                                </td>

                                <td class="px-2 py-3 tabular-nums text-gray-800 dark:text-white">
                                    @if ($task->purchase_price !== null && ($isManager || $task->buyer_id === auth()->guard('admin')->id()))
                                        <span class="whitespace-nowrap">{{ number_format((float) $task->purchase_price, 2) }} DH</span>

                                        @if ($task->over_cap)
                                            <span class="mt-1 block rounded-full bg-rose-100 px-2 py-0.5 text-xs font-semibold text-rose-800 dark:bg-rose-900 dark:text-rose-200">
                                                @lang('admin::app.cod.souk.over-cap')
                                            </span>
                                        @endif

                                        @if ($task->below_margin)
                                            <span class="mt-1 block rounded-full bg-rose-100 px-2 py-0.5 text-xs font-semibold text-rose-800 dark:bg-rose-900 dark:text-rose-200">
                                                @lang('admin::app.cod.souk.below-margin')
                                            </span>
                                        @endif
                                    @elseif ($task->purchase_price !== null)
                                        <span class="text-gray-500 dark:text-gray-400">@lang('admin::app.cod.souk.purchase-price-hidden')</span>
                                    @else
                                        —
                                    @endif
                                </td>

                                <td class="whitespace-nowrap px-2 py-3 tabular-nums text-gray-600 dark:text-gray-300">
                                    {{ $task->deadline_at?->format('Y-m-d H:i') ?? '—' }}

                                    @if ($task->deadline_at && in_array($task->status, ['assigned', 'claimed'], true))
                                        <span class="mt-1 block text-xs font-semibold {{ $task->deadline_at->diffInHours(now()) < 2 ? 'text-rose-600 dark:text-rose-400' : 'text-gray-500 dark:text-gray-400' }}">
                                            {{ $task->deadline_at->diffForHumans() }}
                                        </span>
                                    @endif
                                </td>

                                <td class="px-2 py-3">
                                    <div class="grid w-44 gap-2">
                                        @if ($task->status === 'assigned' && $isBuyer)
                                            <form method="POST" action="{{ route('admin.cod.souk.tasks.claim', $task) }}">
                                                @csrf

                                                <button type="submit" class="w-full whitespace-nowrap rounded-lg bg-blue-600 px-2.5 py-1.5 text-xs font-semibold text-white hover:bg-blue-700">
                                                    @lang('admin::app.cod.souk.claim')
                                                </button>
                                            </form>
                                        @endif

                                        @if ($task->status === 'claimed' && $isBuyer && $task->buyer_id === auth()->guard('admin')->id())
                                            <form method="POST" action="{{ route('admin.cod.souk.tasks.purchase', $task) }}" class="grid gap-1.5">
                                                @csrf

                                                <input
                                                    type="number"
                                                    name="purchase_price"
                                                    step="0.01"
                                                    min="0.01"
                                                    required
                                                    placeholder="@lang('admin::app.cod.souk.purchase-price-placeholder')"
                                                    class="w-full rounded-lg border border-gray-200 bg-white px-2 py-1.5 text-xs text-gray-800 dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                                >

                                                <button type="submit" class="whitespace-nowrap rounded-lg bg-blue-600 px-2.5 py-1.5 text-xs font-semibold text-white hover:bg-blue-700">
                                                    @lang('admin::app.cod.souk.record-purchase')
                                                </button>
                                            </form>
                                        @endif

                                        @if ($task->status === 'purchased' && $isBuyer && $task->buyer_id === auth()->guard('admin')->id())
                                            <form method="POST" action="{{ route('admin.cod.souk.tasks.receive', $task) }}">
                                                @csrf

                                                <button type="submit" class="w-full whitespace-nowrap rounded-lg bg-blue-600 px-2.5 py-1.5 text-xs font-semibold text-white hover:bg-blue-700">
                                                    @lang('admin::app.cod.souk.mark-received')
                                                </button>
                                            </form>
                                        @endif

                                        @if ($task->status === 'received' && $isManager)
                                            @if ($task->over_cap || $task->below_margin)
                                                <p class="rounded-lg border border-rose-200 bg-rose-50 p-2 text-xs leading-relaxed text-rose-800 dark:border-rose-900 dark:bg-rose-900/30 dark:text-rose-200">
                                                    @lang('admin::app.cod.souk.verify-warning')
                                                </p>
                                            @endif

                                            <form method="POST" action="{{ route('admin.cod.souk.tasks.verify', $task) }}" class="grid gap-1.5">
                                                @csrf

                                                <input
                                                    type="text"
                                                    name="reason"
                                                    placeholder="@lang('admin::app.cod.souk.verify-reason-placeholder')"
                                                    class="w-full rounded-lg border border-gray-200 bg-white px-2 py-1.5 text-xs text-gray-800 dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                                >

                                                @if ($task->over_cap || $task->below_margin)
                                                    <label class="flex items-start gap-1.5 text-xs leading-relaxed text-gray-700 dark:text-gray-300">
                                                        <input type="checkbox" name="confirm_override" value="1" class="mt-0.5 shrink-0">
                                                        @lang('admin::app.cod.souk.confirm-override')
                                                    </label>
                                                @endif

                                                <div class="flex gap-1.5">
                                                    <button type="submit" name="approve" value="1" class="flex-1 whitespace-nowrap rounded-lg bg-teal-600 px-2.5 py-1.5 text-xs font-semibold text-white hover:bg-teal-700">
                                                        @lang('admin::app.cod.souk.approve')
                                                    </button>

                                                    <button type="submit" name="approve" value="0" class="flex-1 whitespace-nowrap rounded-lg bg-rose-600 px-2.5 py-1.5 text-xs font-semibold text-white hover:bg-rose-700">
                                                        @lang('admin::app.cod.souk.reject')
                                                    </button>
                                                </div>
                                            </form>
                                        @endif

                                        @if ($task->status === 'verified' && $isManager)
                                            <form method="POST" action="{{ route('admin.cod.souk.tasks.pay', $task) }}" class="grid gap-1.5">
                                                @csrf

                                                <input
                                                    type="number"
                                                    name="amount"
                                                    step="0.01"
                                                    min="0.01"
                                                    required
                                                    value="{{ $task->amountDue() !== null && $task->amountDue() > 0 ? number_format($task->amountDue(), 2, '.', '') : '' }}"
                                                    placeholder="@lang('admin::app.cod.souk.amount-placeholder')"
                                                    class="w-full rounded-lg border border-gray-200 bg-white px-2 py-1.5 text-xs text-gray-800 dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                                >

                                                <select name="type" required class="w-full rounded-lg border border-gray-200 bg-white px-2 py-1.5 text-xs text-gray-800 dark:border-gray-800 dark:bg-gray-900 dark:text-white">
                                                    @foreach (['advance', 'reimbursement'] as $type)
                                                        <option value="{{ $type }}" @selected($type === $paymentModel)>
                                                            @lang('admin::app.cod.souk.payment-types.'.$type)
                                                        </option>
                                                    @endforeach
                                                </select>

                                                <button type="submit" class="whitespace-nowrap rounded-lg bg-green-600 px-2.5 py-1.5 text-xs font-semibold text-white hover:bg-green-700">
                                                    @lang('admin::app.cod.souk.record-payment', ['due' => $task->amountDue() !== null ? number_format($task->amountDue(), 2).' DH' : '—'])
                                                </button>
                                            </form>

                                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                                @lang('admin::app.cod.souk.already-paid', ['amount' => number_format($task->totalPaid(), 2).' DH'])
                                            </p>
                                        @endif

                                        @if (in_array($task->status, ['assigned', 'claimed'], true) && $isManager)
                                            <form method="POST" action="{{ route('admin.cod.souk.tasks.cancel', $task) }}">
                                                @csrf

                                                <button type="submit" class="w-full whitespace-nowrap rounded-lg border border-gray-300 px-2.5 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-100 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">
                                                    @lang('admin::app.cod.souk.cancel')
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

    <!-- My tasks (souk buyer) -->
    @if ($isBuyer && $myTasks->isNotEmpty())
        <div class="mt-8">
            <p class="text-lg font-bold text-gray-800 dark:text-white">
                @lang('admin::app.cod.souk.my-tasks')
            </p>

            <div class="mt-4 overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                <table class="w-full min-w-[700px] text-start text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-600 dark:border-gray-800 dark:text-gray-300">
                            <th class="whitespace-nowrap px-3 py-3 text-start font-semibold">@lang('admin::app.cod.souk.product')</th>
                            <th class="whitespace-nowrap px-3 py-3 text-start font-semibold">@lang('admin::app.cod.souk.status')</th>
                            <th class="whitespace-nowrap px-3 py-3 text-start font-semibold">@lang('admin::app.cod.souk.purchase-price')</th>
                            <th class="whitespace-nowrap px-3 py-3 text-start font-semibold">@lang('admin::app.cod.souk.paid')</th>
                            <th class="whitespace-nowrap px-3 py-3 text-start font-semibold">@lang('admin::app.cod.souk.rejection-reason')</th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($myTasks as $myTask)
                            <tr class="border-b border-gray-100 align-top last:border-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-800/50">
                                <td class="px-3 py-3 text-gray-800 dark:text-white">
                                    {{ $myTask->product?->name ?? $myTask->product?->sku ?? '—' }}
                                    <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $myTask->product?->sku ?? '' }}</span>
                                </td>

                                <td class="px-3 py-3">
                                    <x-admin::cod.status-badge :status="$myTask->status" :label="trans('admin::app.cod.souk.statuses.'.$myTask->status)" />
                                </td>

                                <td class="whitespace-nowrap px-3 py-3 tabular-nums text-gray-800 dark:text-white">
                                    {{ $myTask->purchase_price !== null ? number_format((float) $myTask->purchase_price, 2).' DH' : '—' }}
                                </td>

                                <td class="whitespace-nowrap px-3 py-3 tabular-nums text-gray-800 dark:text-white">
                                    {{ number_format($myTask->totalPaid(), 2) }} DH
                                </td>

                                <td class="px-3 py-3 text-gray-800 dark:text-white">
                                    {{ $myTask->rejection_reason ?? '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <!-- Buyer trust statistics -->
    <div class="mt-8">
        <p class="text-lg font-bold text-gray-800 dark:text-white">
            @lang('admin::app.cod.souk.buyer-stats')
        </p>

        <div class="mt-4 overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
            @if (empty($buyerStats))
                <p class="p-8 text-center text-sm text-gray-600 dark:text-gray-300">
                    @lang('admin::app.cod.souk.no-buyers')
                </p>
            @else
                <table class="w-full min-w-[700px] text-start text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-600 dark:border-gray-800 dark:text-gray-300">
                            <th class="whitespace-nowrap px-3 py-3 text-start font-semibold">@lang('admin::app.cod.souk.buyer')</th>
                            <th class="whitespace-nowrap px-3 py-3 text-start font-semibold">@lang('admin::app.cod.souk.stats.claimed-count')</th>
                            <th class="whitespace-nowrap px-3 py-3 text-start font-semibold">@lang('admin::app.cod.souk.stats.delivered-count')</th>
                            <th class="whitespace-nowrap px-3 py-3 text-start font-semibold">@lang('admin::app.cod.souk.stats.rejected-count')</th>
                            <th class="whitespace-nowrap px-3 py-3 text-start font-semibold">@lang('admin::app.cod.souk.stats.paid-count')</th>
                            <th class="whitespace-nowrap px-3 py-3 text-start font-semibold">@lang('admin::app.cod.souk.stats.delivery-rate')</th>
                            <th class="whitespace-nowrap px-3 py-3 text-start font-semibold">@lang('admin::app.cod.souk.stats.rejection-rate')</th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($buyerStats as $stat)
                            <tr class="border-b border-gray-100 align-top last:border-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-800/50">
                                <td class="px-3 py-3 font-semibold text-gray-800 dark:text-white">{{ $stat['admin']->name }}</td>
                                <td class="whitespace-nowrap px-3 py-3 tabular-nums text-gray-800 dark:text-white">{{ $stat['claimed'] }}</td>
                                <td class="whitespace-nowrap px-3 py-3 tabular-nums text-gray-800 dark:text-white">{{ $stat['delivered'] }}</td>
                                <td class="whitespace-nowrap px-3 py-3 tabular-nums text-gray-800 dark:text-white">{{ $stat['rejected'] }}</td>
                                <td class="whitespace-nowrap px-3 py-3 tabular-nums text-gray-800 dark:text-white">{{ $stat['paid'] }}</td>
                                <td class="whitespace-nowrap px-3 py-3 tabular-nums text-gray-800 dark:text-white">{{ $stat['delivery_rate'] !== null ? $stat['delivery_rate'].'%' : '—' }}</td>
                                <td class="whitespace-nowrap px-3 py-3 tabular-nums text-gray-800 dark:text-white">{{ $stat['rejection_rate'] !== null ? $stat['rejection_rate'].'%' : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</x-admin::layouts>
