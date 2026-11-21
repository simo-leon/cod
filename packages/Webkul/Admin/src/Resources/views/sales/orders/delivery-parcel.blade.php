@php
    $deliveryParcel = \App\Models\DeliveryParcel::where('order_id', $order->id)
        ->latest()
        ->first();

    $deliveryManager = app(\App\Services\Delivery\DeliveryProviderManager::class);
    $deliveryProvider = $deliveryManager->defaultProvider();
    $isCodOrder = ($order->payment?->method ?? null) === 'cashondelivery';
    $deliveryConfigured = $deliveryManager->isConfigured();
@endphp

<div class="box-shadow rounded bg-white dark:bg-gray-900">
    <div class="p-4">
        <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">
            @lang('admin::app.sales.orders.view.delivery.title')
        </p>

        @if (! $isCodOrder)
            <p class="text-sm text-gray-600 dark:text-gray-300">
                @lang('admin::app.sales.orders.view.delivery.not-cod')
            </p>
        @elseif (! $deliveryConfigured)
            <p class="text-sm text-gray-600 dark:text-gray-300">
                @lang('admin::app.sales.orders.view.delivery.not-configured')
            </p>
        @else
            <div class="flex flex-col gap-1.5 text-sm text-gray-600 dark:text-gray-300">
                <p>
                    @lang('admin::app.sales.orders.view.delivery.provider'): <span class="font-semibold text-gray-800 dark:text-white">{{ $deliveryProvider?->getLabel() }}</span>
                </p>

                @if ($deliveryParcel)
                    @if ($deliveryParcel->parcel_code)
                        <p>
                            @lang('admin::app.sales.orders.view.delivery.parcel-code'): <span class="font-semibold text-gray-800 dark:text-white">{{ $deliveryParcel->parcel_code }}</span>
                        </p>
                    @endif

                    @if ($deliveryParcel->last_status)
                        <p>
                            @lang('admin::app.sales.orders.view.delivery.last-status'): <span class="font-semibold text-gray-800 dark:text-white">{{ $deliveryParcel->last_status }}</span>
                        </p>
                    @endif

                    @if ($deliveryParcel->sent_at)
                        <p>
                            @lang('admin::app.sales.orders.view.delivery.sent-at'): {{ $deliveryParcel->sent_at->format('Y-m-d H:i') }}
                        </p>
                    @endif

                    @if ($deliveryParcel->status === 'failed')
                        <p class="rounded bg-red-100 px-3 py-1.5 font-semibold text-red-700 dark:bg-red-900/30 dark:text-red-400">
                            @lang('admin::app.sales.orders.view.delivery.error'): {{ $deliveryParcel->error_message }}
                        </p>
                    @endif
                @else
                    <p>
                        @lang('admin::app.sales.orders.view.delivery.provider'): <span class="font-semibold text-gray-800 dark:text-white">{{ $deliveryProvider?->getLabel() }}</span>
                    </p>
                @endif
            </div>

            @if (bouncer()->hasPermission('sales.orders.edit'))
                <form
                    method="POST"
                    action="{{ route('admin.sales.orders.send-to-delivery', $order->id) }}"
                    class="mt-4"
                >
                    @csrf

                    <button type="submit" class="primary-button px-3 py-1.5 text-sm">
                        {{ $deliveryParcel && $deliveryParcel->status === 'sent'
                            ? trans('admin::app.sales.orders.view.delivery.resend')
                            : trans('admin::app.sales.orders.view.delivery.send') }}
                    </button>
                </form>
            @endif
        @endif
    </div>
</div>
