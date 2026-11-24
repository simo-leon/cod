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
     * the order's shipping address (billing address as fallback — the
     * parcel must go where the customer asked for delivery, H5), the
     * COD amount is the order grand total, products and quantities come
     * from the order items.
     */
    public function buildParcelData(Order $order): array
    {
        $address = $order->shipping_address ?: $order->billing_address;

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

            // M16: one live parcel per order across ALL providers — a
            // provider switch must not ship the same order twice, and a
            // "resend" click must not create a second parcel at the
            // carrier. Only an explicit force (the admin re-send button,
            // which shows the existing parcel first) creates another.
            $existing = DeliveryParcel::where('order_id', $order->id)
                ->where('status', 'sent')
                ->latest()
                ->first();

            if ($existing && ! $force) {
                return $existing;
            }

            if ($existing && $force) {
                Log::warning('Delivery: forced re-send, a sent parcel already exists for this order', [
                    'order_id' => $order->id,
                    'existing_parcel_id' => $existing->id,
                    'existing_provider' => $existing->provider,
                ]);
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
                // T11: store only a short summary of the provider reply,
                // not the full raw response.
                'response' => $this->trimRawResponse($result->raw),
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
     * Whether the parcel's provider offers cancellation through the
     * API (M12). The admin only shows the cancel button when this is
     * true; Vitis and Sendit have no cancel endpoint.
     */
    public function supportsCancel(DeliveryParcel $parcel): bool
    {
        return $this->provider($parcel->provider)?->supportsCancel() ?? false;
    }

    /**
     * Cancel a sent parcel at its provider and mark it cancelled.
     * Returns ['success' => bool, 'message' => string]. Never throws.
     */
    public function cancelParcel(DeliveryParcel $parcel): array
    {
        $provider = $this->provider($parcel->provider);

        if (! $provider) {
            return ['success' => false, 'message' => 'Unknown delivery provider "'.$parcel->provider.'".'];
        }

        if (! $provider->supportsCancel()) {
            return ['success' => false, 'message' => $provider->getLabel().' has no cancel endpoint — cancel the parcel from the provider dashboard.'];
        }

        if (! $parcel->parcel_code) {
            return ['success' => false, 'message' => 'This parcel has no tracking code yet, so it cannot be cancelled at the provider.'];
        }

        try {
            $result = $provider->cancelParcel($parcel->parcel_code);
        } catch (\Throwable $e) {
            Log::warning('Delivery: parcel cancellation failed', [
                'parcel_id' => $parcel->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }

        if ($result['success']) {
            $parcel->update(['status' => 'cancelled', 'last_status' => 'cancelled']);
        }

        return $result;
    }

    /**
     * T11: keep only a short summary of a provider reply (code /
     * message / status, capped) instead of the full raw response,
     * which can carry customer data and large payloads.
     */
    protected function trimRawResponse(array $raw): array
    {
        $summary = [];

        foreach (['code', 'message', 'status', 'error'] as $key) {
            if (isset($raw[$key]) && is_scalar($raw[$key])) {
                $summary[$key] = mb_substr((string) $raw[$key], 0, 200);
            }
        }

        if (isset($raw['data']) && is_array($raw['data'])) {
            foreach (['code', 'parcel_code', 'tracking_code', 'message', 'status'] as $key) {
                if (isset($raw['data'][$key]) && is_scalar($raw['data'][$key])) {
                    $summary['data_'.$key] = mb_substr((string) $raw['data'][$key], 0, 200);
                }
            }
        }

        return $summary;
    }

    /**
     * Refresh the last known status of a sent parcel from the provider.
     * Never throws: a provider failure is reported in the result and
     * stored in `error_message` on the parcel, so the admin action can
     * flash it instead of breaking the order page.
     *
     * The raw provider status is normalized through
     * TrackingStatusMapper, so `last_status` always holds one unified
     * value (pending / in_transit / out_for_delivery / delivered /
     * returned / cancelled). Idempotent: when neither the status nor
     * the error changed, nothing is written.
     *
     * @return array{success: bool, status: ?string, error: ?string, changed: bool}
     */
    public function refreshTracking(DeliveryParcel $parcel): array
    {
        $provider = $this->provider($parcel->provider);

        if (! $provider) {
            return ['success' => false, 'status' => $parcel->last_status, 'error' => 'Unknown delivery provider "'.$parcel->provider.'".', 'changed' => false];
        }

        if (! $parcel->parcel_code) {
            return ['success' => false, 'status' => $parcel->last_status, 'error' => 'This parcel has no tracking code yet.', 'changed' => false];
        }

        try {
            $tracking = $provider->trackParcel($parcel->parcel_code);
        } catch (\Throwable $e) {
            Log::warning('Delivery: tracking refresh failed', [
                'parcel_id' => $parcel->id,
                'provider' => $parcel->provider,
                'error' => $e->getMessage(),
            ]);

            $this->storeTrackingError($parcel, $e->getMessage());

            return ['success' => false, 'status' => $parcel->last_status, 'error' => $e->getMessage(), 'changed' => false];
        }

        if (! ($tracking['success'] ?? false)) {
            $error = $tracking['error'] ?? 'Tracking failed.';
            $this->storeTrackingError($parcel, $error);

            return ['success' => false, 'status' => $parcel->last_status, 'error' => $error, 'changed' => false];
        }

        $status = TrackingStatusMapper::normalize($parcel->provider, $tracking['status'] ?? null);
        $events = is_array($tracking['events'] ?? null) ? $tracking['events'] : [];

        // No recognizable status: the provider answered but said
        // nothing usable — record the events, change nothing.
        if ($status === null) {
            if ($events !== [] && $parcel->tracking_events !== $events) {
                $parcel->update(['tracking_events' => $events]);
            }

            return ['success' => true, 'status' => $parcel->fresh()->last_status, 'error' => null, 'changed' => false];
        }

        if ($status !== $parcel->last_status || $parcel->error_message !== null || (array) ($parcel->tracking_events ?? []) !== $events) {
            $parcel->update([
                'last_status' => $status,
                'tracking_events' => $events,
                'error_message' => null,
            ]);

            return ['success' => true, 'status' => $status, 'error' => null, 'changed' => true];
        }

        return ['success' => true, 'status' => $status, 'error' => null, 'changed' => false];
    }

    /**
     * Record a tracking failure on the parcel without touching its
     * last known status. Only writes when the error text changed, so
     * repeated failures with the same message do not rewrite the row.
     */
    protected function storeTrackingError(DeliveryParcel $parcel, string $error): void
    {
        if ($parcel->error_message !== $error) {
            $parcel->update(['error_message' => $error]);
        }
    }
}
