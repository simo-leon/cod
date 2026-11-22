{{--
    Souk tasks card on the admin store order view (rendered above
    the order items via bagisto.admin.sales.order.left_component.before).
    Only rendered when the order has souk tasks: it shows which items
    come from the souk, who the buyer is, the price they paid, and
    the task status.
--}}
<div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
    <p class="text-base font-semibold text-gray-800 dark:text-white">
        @lang('admin::app.cod.souk.order-card.title')
    </p>

    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
        @lang('admin::app.cod.souk.order-card.subtitle')
    </p>

    <div class="mt-3 grid gap-2">
        @foreach ($tasks as $task)
            <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-800">
                <div class="grid gap-0.5">
                    <span class="font-semibold text-gray-800 dark:text-white">
                        {{ $task->product?->name ?? $task->product?->sku ?? '—' }}
                    </span>

                    <span class="text-xs text-gray-500 dark:text-gray-400">
                        @lang('admin::app.cod.souk.order-card.buyer', ['buyer' => $task->buyer?->name ?? trans('admin::app.cod.souk.order-card.no-buyer')])
                    </span>

                    @if ($task->purchase_price !== null)
                        <span class="text-xs tabular-nums text-gray-500 dark:text-gray-400">
                            @lang('admin::app.cod.souk.order-card.purchase-price', ['price' => number_format((float) $task->purchase_price, 2)])
                        </span>
                    @endif
                </div>

                <x-admin::cod.status-badge :status="$task->status" :label="trans('admin::app.cod.souk.statuses.'.$task->status)" />
            </div>
        @endforeach
    </div>

    <a href="{{ route('admin.cod.souk.index') }}" class="mt-3 inline-block text-sm font-semibold text-blue-600 hover:underline dark:text-blue-400">
        @lang('admin::app.cod.souk.order-card.view-board')
    </a>
</div>
