<?php

namespace App\Services\Delivery;

use App\Models\DeliveryParcel;
use Illuminate\Support\Facades\Log;
use Webkul\Sales\Models\Order;

/**
 * Registry and order wiring for delivery providers.
 *
 * To add a second delivery company:
 *  1. Implement DeliveryProviderInterface (see VitisExpressProvider).
 *  2. Add its config section under general.delivery_providers in
 *     packages/Webkul/Admin/src/Config/system.php + language keys.
 *  3. Add one line to $providers below.
 *  4. Add it as an option of the default_provider select field.
 * Orders, the admin order view and the test-connection action need no
 * changes: they only use this manager and the interface.
 */
class DeliveryProviderManager
{
    /**
     * Provider name => provider class. Add new companies here.
     *
     * @var array<string, class-string<DeliveryProviderInterface>>
     */
    protected array $providers = [
        'vitis_express' => VitisExpressProvider::class,
        'sendit' => SenditProvider::class,
        'speedaf_express' => SpeedafExpressProvider::class,
    ];

    public function defaultProviderName(): string
    {
        $configured = core()->getConfigData('general.delivery_providers.settings.default_provider');

        return $configured ? (string) $configured : 'vitis_express';
    }

    public function provider(?string $name = null): ?DeliveryProviderInterface
    {
        $name ??= $this->defaultProviderName();

        $class = $this->providers[$name] ?? null;

        return $class ? app($class) : null;
    }

    public function defaultProvider(): ?DeliveryProviderInterface
    {
        return $this->provider($this->defaultProviderName());
    }

    /**
     * Whether a parcel can be sent right now: a known provider that is
     * enabled and has its credentials set.
     */
    public function isConfigured(): bool
    {
        $provider = $this->defaultProvider();

        return $provider !== null && $provider->isEnabled() && $provider->hasCredentials();
    }

    /**
     * Build the normalized parcel data for an order from its real data.
     * No values are invented: names, phone, city and address come from
     * the order's billing address (shipping address as fallback), the
     * COD amount is the order grand total, products and quantities come
     * from the order items.
     */
    public function buildParcelData(Order $order): array
    {
        $address = $order->billing_address ?: $order->shipping_address;

        return [
            'fullname' => trim(($address->first_name ?? '').' '.($address->last_name ?? '')) ?: $order->customer_full_name,
            'phone' => (string) ($address->phone ?? ''),
            'city' => (string) ($address->city ?? ''),
            'state' => (string) ($address->state ?? ''),
            'country' => strtoupper((string) ($address->country ?? 'MA')) ?: 'MA',
            'address' => trim(($address->address1 ?? '').' '.($address->address2 ?? '')),
            'price' => (float) $order->grand_total,
            'products' => $order->items->map(fn ($item) => [
                'name' => (string) $item->name,
                'qty' => (int) $item->qty_ordered,
                'sku' => (string) ($item->sku ?? ''),
                'price' => (float) ($item->price ?? 0),
                'total' => (float) ($item->total ?? ((float) ($item->price ?? 0) * (int) $item->qty_ordered)),
            ])->values()->all(),
            'note' => 'Order #'.$order->increment_id,
            'internal_id' => (string) $order->increment_id,
        ];
    }

    /**
     * Send one order to the default delivery provider and record the
     * outcome. Returns null when nothing was sent (not a COD order, or
     * the provider is disabled / has no credentials). Never throws: a
     * delivery failure must never break checkout or the admin action —
     * the failure is stored on the parcel record instead.
     */
    public function sendOrder(Order $order, bool $force = false): ?DeliveryParcel
    {
        try {
            if (($order->payment?->method ?? null) !== 'cashondelivery') {
                return null;
            }

            $provider = $this->defaultProvider();

            if ($provider === null || ! $provider->isEnabled() || ! $provider->hasCredentials()) {
                return null;
            }

            $existing = DeliveryParcel::where('order_id', $order->id)
                ->where('provider', $provider->getName())
                ->where('status', 'sent')
                ->first();

            if ($existing && ! $force) {
                return $existing;
            }

            $data = $this->buildParcelData($order);
            $result = $provider->createParcel($data);

            return DeliveryParcel::create([
                'order_id' => $order->id,
                'provider' => $provider->getName(),
                'parcel_code' => $result->parcelCode,
                'status' => $result->success ? 'sent' : 'failed',
                'last_status' => $result->success ? ($result->status ?? 'sent') : null,
                'error_message' => $result->success ? null : $result->error,
                'payload' => array_merge($data, ['used_form_encoding' => $result->usedFormEncoding]),
                'response' => $result->raw,
                'sent_at' => $result->success ? now() : null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Delivery: failed to send order to provider', [
                'order_id' => $order->id ?? null,
                'error' => $e->getMessage(),
            ]);

            try {
                return DeliveryParcel::create([
                    'order_id' => $order->id,
                    'provider' => $this->defaultProviderName(),
                    'parcel_code' => null,
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'payload' => $data ?? [],
                    'response' => [],
                ]);
            } catch (\Throwable) {
                return null;
            }
        }
    }

    /**
     * Refresh the last known status of a sent parcel from the provider.
     */
    public function refreshTracking(DeliveryParcel $parcel): ?string
    {
        $provider = $this->provider($parcel->provider);

        if (! $provider || ! $parcel->parcel_code) {
            return $parcel->last_status;
        }

        $tracking = $provider->trackParcel($parcel->parcel_code);

        if ($tracking['success'] && $tracking['status']) {
            $parcel->update(['last_status' => $tracking['status']]);
        }

        return $parcel->last_status;
    }
}
