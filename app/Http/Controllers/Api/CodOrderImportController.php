<?php

namespace App\Http\Controllers\Api;

use App\Cod\Orders\OrderServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Secure endpoint that receives store orders into the COD System.
 *
 * POST /api/cod/orders
 *
 * Auth: the secret key configured in Admin → Configuration → General →
 * COD System → Import Secret Key, sent as the `X-COD-Import-Key` header
 * or as a Bearer token. Requests without a key, with a wrong key, or
 * while no key is configured are rejected with 401. The key is never
 * hard-coded and never logged.
 *
 * Payload (JSON):
 * {
 *   "external_id": "123",            // required: store order increment_id
 *   "customer_name": "…", "customer_phone": "…", "customer_email": "…",
 *   "city": "…", "address": "…",
 *   "grand_total": 240, "currency_code": "MAD",
 *   "items_count": 2, "status": "pending_confirmation",
 *   "items": [{"sku": "JEMLA-W8-ULTRA-MAX", "name": "…", "qty": 1}],
 *   "order_id": 123                   // optional: local store order id
 * }
 * `increment_id` is accepted as an alias for `external_id`.
 *
 * `items` are stored in the COD record payload: the product-distribution
 * rules read the SKUs from them (AssignmentService::orderSkus). An item
 * without a SKU is kept but ignored by the distribution. `qty` and
 * `quantity` are accepted as aliases for the item quantity.
 *
 * The import is idempotent: sending the same external_id twice updates
 * the single COD record instead of creating a duplicate.
 */
class CodOrderImportController extends Controller
{
    public function __invoke(Request $request, OrderServiceInterface $orders): JsonResponse
    {
        $configuredKey = $orders->getImportSecretKey();

        $providedKey = $request->header('X-COD-Import-Key')
            ?? $request->bearerToken();

        if (empty($configuredKey) || empty($providedKey) || ! hash_equals($configuredKey, $providedKey)) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 401);
        }

        $validated = $request->validate([
            'external_id' => 'required_without:increment_id|string|max:255',
            'increment_id' => 'required_without:external_id|string|max:255',
            'order_id' => 'nullable|integer|min:1',
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:50',
            'customer_email' => 'nullable|email|max:255',
            'city' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:1000',
            'grand_total' => 'nullable|numeric|min:0',
            'currency_code' => 'nullable|string|size:3',
            'items_count' => 'nullable|integer|min:0',
            'items' => 'nullable|array',
            'items.*.sku' => 'nullable|string|max:255',
            'items.*.name' => 'nullable|string|max:255',
            'items.*.qty' => 'nullable|integer|min:1',
            'items.*.quantity' => 'nullable|integer|min:1',
            'status' => 'nullable|string|max:50',
        ]);

        $codOrder = $orders->importFromPayload($validated);

        if (! $codOrder) {
            return response()->json([
                'message' => 'Order could not be imported (not a cash-on-delivery order, or missing external_id).',
            ], 422);
        }

        return response()->json([
            'created' => $codOrder->wasRecentlyCreated,
            'cod_order' => [
                'id' => $codOrder->id,
                'external_id' => $codOrder->external_id,
                'order_id' => $codOrder->order_id,
                'status' => $codOrder->status,
                'customer_name' => $codOrder->customer_name,
                'customer_phone' => $codOrder->customer_phone,
                'city' => $codOrder->city,
                'grand_total' => $codOrder->grand_total,
                'currency_code' => $codOrder->currency_code,
                'source' => $codOrder->source,
            ],
        ], $codOrder->wasRecentlyCreated ? 201 : 200);
    }
}
