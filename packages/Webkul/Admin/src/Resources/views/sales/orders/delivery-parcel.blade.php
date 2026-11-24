@php
    // The delivery_parcels table only exists after `php artisan migrate`
    // has run. Never let the order page crash (500) when it hasn't:
    // show a notice instead of querying a missing table.
    $deliveryTableExists = \Illuminate\Support\Facades\Schema::hasTable('delivery_parcels');

    $deliveryParcel = $deliveryTableExists
        ? \App\Models\DeliveryParcel::where('order_id', $order->id)
            ->latest()
            ->first()
        : null;

    $deliveryManager = app(\App\Services\Delivery\DeliveryProviderManager::class);
    $deliveryProvider = $deliveryManager->defaultProvider();
    $isCodOrder = ($order->payment?->method ?? null) === 'cashondelivery';
    $deliveryConfigured = $deliveryManager->isConfigured();

    // The parcel's own provider, not the configured default: a parcel
    // sent through Sendit / Speedaf must not be shown as Vitis
    // Express. Falls back to the raw provider name when unknown.
    $parcelProvider = $deliveryParcel ? $deliveryManager->provider($deliveryParcel->provider) : null;
    $parcelProviderLabel = $parcelProvider?->getLabel() ?? $deliveryParcel->provider ?? null;
@endphp

<div class="box-shadow rounded bg-white dark:bg-gray-900">
    <div class="p-4">
        <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">
            @lang('admin::app.sales.orders.view.delivery.title')
        </p>

        @if (! $deliveryTableExists)
            <p class="text-sm text-gray-600 dark:text-gray-300">
                @lang('admin::app.sales.orders.view.delivery.migration-pending')
            </p>
        @elseif (! $isCodOrder)
            <p class="text-sm text-gray-600 dark:text-gray-300">
                @lang('admin::app.sales.orders.view.delivery.not-cod')
            </p>
        @elseif (! $deliveryConfigured && ! $deliveryParcel)
            <p class="text-sm text-gray-600 dark:text-gray-300">
                @lang('admin::app.sales.orders.view.delivery.not-configured')
            </p>
        @else
            <div class="flex flex-col gap-1.5 text-sm text-gray-600 dark:text-gray-300">
                <p>
                    @lang('admin::app.sales.orders.view.delivery.provider'): <span class="font-semibold text-gray-800 dark:text-white">{{ $parcelProviderLabel ?? $deliveryProvider?->getLabel() }}</span>
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

                    <p>
                        @lang('admin::app.sales.orders.view.delivery.updated-at'): <span class="font-semibold text-gray-800 dark:text-white">{{ $deliveryParcel->updated_at->format('Y-m-d H:i') }}</span>
                    </p>

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

            {{-- P-A1 (round 2): the Delivery Manager holds
                sales.orders.view (CodRolesSeeder) and may test the
                provider connection too — the order page itself was
                closed to them before. Sending, refreshing and
                cancelling stay edit-only below. --}}
            @if (bouncer()->hasPermission('sales.orders.edit') || bouncer()->hasPermission('sales.orders.view'))
                <div class="mt-4 flex flex-wrap gap-2">
                    @if (bouncer()->hasPermission('sales.orders.edit') && $deliveryConfigured)
                        <form
                            method="POST"
                            action="{{ route('admin.sales.orders.send-to-delivery', $order->id) }}"
                        >
                            @csrf

                            <button type="submit" class="primary-button px-3 py-1.5 text-sm">
                                {{ $deliveryParcel && $deliveryParcel->status === 'sent'
                                    ? trans('admin::app.sales.orders.view.delivery.resend', ['provider' => $deliveryProvider?->getLabel()])
                                    : trans('admin::app.sales.orders.view.delivery.send', ['provider' => $deliveryProvider?->getLabel()]) }}
                            </button>
                        </form>
                    @endif

                    {{-- P-A1 (round 2): the connection test pings the
                        provider, not a shipment — it needs no parcel
                        (and no parcel_code) to be useful. --}}
                    <form
                        method="POST"
                        action="{{ route('admin.delivery-providers.test-connection') }}"
                    >
                        @csrf

                        <button
                            type="submit"
                            class="whitespace-nowrap rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-800"
                        >
                            @lang('admin::app.sales.orders.view.delivery.test-connection')
                        </button>
                    </form>

                    @if (bouncer()->hasPermission('sales.orders.edit'))
                        @if ($deliveryParcel && $deliveryParcel->parcel_code)
                            <form
                                method="POST"
                                action="{{ route('admin.sales.orders.refresh-delivery-tracking', $order->id) }}"
                            >
                                @csrf

                                <button type="submit" class="secondary-button px-3 py-1.5 text-sm">
                                    @lang('admin::app.sales.orders.view.delivery.refresh')
                                </button>
                            </form>
                        @endif

                        @if ($deliveryParcel && $deliveryParcel->status === 'sent' && $deliveryManager->supportsCancel($deliveryParcel))
                            <form
                                method="POST"
                                action="{{ route('admin.sales.orders.cancel-delivery-parcel', $order->id) }}"
                                onsubmit="return confirm(@js(trans('admin::app.sales.orders.view.delivery.cancel-confirm', ['code' => $deliveryParcel->parcel_code])));"
                            >
                                @csrf

                                <button type="submit" class="secondary-button px-3 py-1.5 text-sm !text-red-600">
                                    @lang('admin::app.sales.orders.view.delivery.cancel')
                                </button>
                            </form>
                        @endif
                    @endif
                </div>
            @endif
        @endif
    </div>
</div>
