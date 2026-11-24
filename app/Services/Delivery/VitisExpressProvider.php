<?php

namespace App\Services\Delivery;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Vitis Express (Morocco) delivery provider.
 *
 * API docs: https://app.vitipsexpress.com/doc/api-client
 * Base URL: https://app.vitipsexpress.com/api/client
 * Auth: header `api-Token: <token>` (exact case) + `Accept: application/json`.
 * Rate limit: 1000 requests/hour.
 *
 * Known gaps in the docs (2026-11-21), handled as documented below:
 * - The docs give no request-body example and no content type for
 *   POST /add-colis. We send JSON only (see M14 note in createParcel).
 * - The docs do not say whether `city` is a name or a numeric id. We send
 *   the city name exactly as stored on the order.
 * - The documented add-colis success response
 *   {"code":"ok","message":"Le colis est ajouté avec succes"} contains
 *   NO parcel code field. We look for a code in the usual places
 *   (data.code, data.parcel_code, data.tracking_code, data.id, ...); if
 *   the response carries none, the parcel is recorded FAILED (M13) —
 *   a fake tracking code equal to the order number would make
 *   tracking fail silently forever.
 * - The docs give no request-body example and no content type for
 *   POST /add-colis. We send JSON only (M14): retrying a rejected
 *   request as form-encoded could create a second parcel at the
 *   carrier for the same internal_id, with no idempotency key to
 *   tell them apart. A 400/422 is reported to the admin instead.
 * - There is no cancel/update/delete endpoint in the docs.
 *
 * The token is read from core_config on every call and is never logged,
 * never stored anywhere else, and never included in any exception message.
 */
class VitisExpressProvider implements DeliveryProviderInterface
{
    public const BASE_URL = 'https://app.vitipsexpress.com/api/client';

    public const CONFIG_KEY = 'general.delivery_providers.vitis_express';

    public function getName(): string
    {
        return 'vitis_express';
    }

    public function getLabel(): string
    {
        return 'Vitis Express';
    }

    public function isEnabled(): bool
    {
        return (bool) core()->getConfigData(self::CONFIG_KEY.'.status');
    }

    public function hasCredentials(): bool
    {
        return ! empty($this->getToken());
    }

    public function getToken(): ?string
    {
        $token = core()->getConfigData(self::CONFIG_KEY.'.api_token');

        return $token ? (string) $token : null;
    }

    public function createParcel(array $data): DeliveryResult
    {
        $token = $this->getToken();

        if (empty($token)) {
            return DeliveryResult::failed('Vitis Express API token is not configured.');
        }

        $payload = [
            'fullname' => $data['fullname'],
            'phone' => $data['phone'],
            'city' => $data['city'],
            'address' => $data['address'] ?? '',
            'price' => $data['price'],
            'product' => collect($data['products'] ?? [])->pluck('name')->implode(', '),
            'qty' => collect($data['products'] ?? [])->pluck('qty')->implode(','),
            'note' => $data['note'] ?? '',
            'change' => 0,
            'openpackage' => (int) (bool) core()->getConfigData(self::CONFIG_KEY.'.openpackage'),
            'from_stock' => (int) (bool) core()->getConfigData(self::CONFIG_KEY.'.from_stock'),
            'try_product' => (int) (bool) core()->getConfigData(self::CONFIG_KEY.'.try_product'),
            'internal_id' => $data['internal_id'] ?? '',
        ];

        $response = null;
        $usedForm = false;

        try {
            $response = $this->post('/add-colis', $payload, $token);
        } catch (ConnectionException $e) {
            // Unreachable host / timeout: report as a failed parcel, never throw.
            return DeliveryResult::failed('Connection to Vitis Express failed: '.$e->getMessage());
        }

        if (! $this->isSuccessResponse($response)) {
            if ($this->isUnauthorized($response)) {
                return DeliveryResult::failed(
                    'Vitis Express: unauthorized — check the API token.',
                    $this->safeBody($response)
                );
            }

            // M14: no automatic retry with another encoding. A 400/422
            // may mean the parcel was already created server-side and
            // only a later validation failed — re-sending the same
            // add-colis could create a duplicate parcel at the carrier,
            // and there is no idempotency key to prevent it. Report the
            // error to the admin instead; they can re-send deliberately.
            return DeliveryResult::failed(
                'Vitis Express: '.$this->errorMessage($response).' (not retried automatically: re-sending could create a duplicate parcel).',
                $this->safeBody($response)
            );
        }

        $body = $this->safeBody($response);
        $parcelCode = $this->extractParcelCode($body, null);

        // M13: no tracking code from the provider means the parcel
        // cannot be tracked — record it failed, not "sent" with a fake
        // code equal to the order number.
        if ($parcelCode === null || $parcelCode === '') {
            return DeliveryResult::failed(
                'Vitis Express: the parcel was accepted but the response carried no tracking code, so it cannot be tracked.',
                $body
            );
        }

        Log::info('Vitis Express: parcel created', [
            'internal_id' => $data['internal_id'] ?? null,
            'parcel_code' => $parcelCode,
            'used_form_encoding' => $usedForm,
        ]);

        return DeliveryResult::ok($parcelCode, 'sent', $body, $usedForm);
    }

    public function trackParcel(string $code): array
    {
        $token = $this->getToken();

        if (empty($token)) {
            return ['success' => false, 'status' => null, 'events' => [], 'error' => 'Vitis Express API token is not configured.'];
        }

        try {
            $response = $this->client($token)->get(self::BASE_URL.'/track/'.urlencode($code));
        } catch (ConnectionException $e) {
            return ['success' => false, 'status' => null, 'events' => [], 'error' => 'Connection to Vitis Express failed: '.$e->getMessage()];
        }

        if (! $response->successful()) {
            return ['success' => false, 'status' => null, 'events' => [], 'error' => $this->errorMessage($response)];
        }

        $events = $response->json('data', []);
        $last = is_array($events) && $events !== [] ? end($events) : null;

        return [
            'success' => true,
            'status' => is_array($last) ? ($last['status'] ?? null) : null,
            'events' => is_array($events) ? $events : [],
            'error' => null,
        ];
    }

    public function listStatuses(): array
    {
        $token = $this->getToken();

        if (empty($token)) {
            return [];
        }

        return Cache::remember('vitis_express_statuses', 3600, function () use ($token) {
            try {
                $response = $this->client($token)->get(self::BASE_URL.'/list-status');

                return $response->successful() ? (array) $response->json('data', []) : [];
            } catch (ConnectionException) {
                return [];
            }
        });
    }

    public function listCities(): array
    {
        $token = $this->getToken();

        if (empty($token)) {
            return [];
        }

        return Cache::remember('vitis_express_cities', 86400, function () use ($token) {
            try {
                $response = $this->client($token)->get(self::BASE_URL.'/villes');

                return $response->successful() ? (array) $response->json('data', []) : [];
            } catch (ConnectionException) {
                return [];
            }
        });
    }

    public function testConnection(): array
    {
        if (! $this->isEnabled()) {
            return ['success' => false, 'message' => 'Vitis Express is disabled in the configuration.'];
        }

        if (! $this->hasCredentials()) {
            return ['success' => false, 'message' => 'Vitis Express API token is missing.'];
        }

        try {
            $response = $this->client($this->getToken())->get(self::BASE_URL.'/list-status');
        } catch (ConnectionException $e) {
            // The caller prefixes the message ("Connection to Vitis Express failed: ...").
            return ['success' => false, 'message' => $e->getMessage()];
        }

        if ($response->successful()) {
            return ['success' => true, 'message' => 'Connection to Vitis Express works.'];
        }

        if ($this->isUnauthorized($response)) {
            return ['success' => false, 'message' => 'Vitis Express: unauthorized — check the API token.'];
        }

        return ['success' => false, 'message' => 'Vitis Express: '.$this->errorMessage($response)];
    }

    /**
     * JSON request (tried first for add-colis).
     */
    protected function post(string $path, array $payload, string $token)
    {
        return $this->client($token)
            ->retry(1, 500, fn ($exception) => $exception instanceof ConnectionException)
            ->post(self::BASE_URL.$path, $payload);
    }

    /**
     * Form-encoded request (fallback for add-colis, docs give no content type).
     */
    protected function postForm(string $path, array $payload, string $token)
    {
        return $this->client($token)
            ->retry(1, 500, fn ($exception) => $exception instanceof ConnectionException)
            ->asForm()
            ->post(self::BASE_URL.$path, $payload);
    }

    protected function client(string $token)
    {
        return Http::withHeaders([
            'api-Token' => $token,
            'Accept' => 'application/json',
        ])->timeout(15);
    }

    protected function isSuccessResponse($response): bool
    {
        if (! $response->successful()) {
            return false;
        }

        $body = $this->safeBody($response);

        // The documented success payload is {"code":"ok",...}.
        return ! isset($body['code']) || $body['code'] === 'ok';
    }

    protected function isUnauthorized($response): bool
    {
        $body = $this->safeBody($response);

        return in_array($response->status(), [401, 403], true)
            || ($body['error'] ?? null) === "Vous n'avez pas les autorisations nécessaires";
    }

    protected function errorMessage($response): string
    {
        $body = $this->safeBody($response);

        if (isset($body['error']) && is_string($body['error'])) {
            return $body['error'];
        }

        if (isset($body['message']) && is_string($body['message'])) {
            return $body['message'];
        }

        return 'HTTP '.$response->status();
    }

    protected function safeBody($response): array
    {
        try {
            $json = $response->json();
        } catch (\Throwable) {
            return [];
        }

        return is_array($json) ? $json : [];
    }

    /**
     * The docs show no parcel code in the add-colis success response, so
     * look in the usual places. Returns null when the response carries
     * no code at all (M13: the caller records the parcel failed
     * instead of inventing a tracking code).
     */
    protected function extractParcelCode(array $body, ?string $fallback = null): ?string
    {
        $data = $body['data'] ?? [];

        if (is_array($data)) {
            foreach (['code', 'parcel_code', 'tracking_code', 'colis_code', 'id'] as $key) {
                if (isset($data[$key]) && is_scalar($data[$key])) {
                    return (string) $data[$key];
                }
            }
        }

        foreach (['parcel_code', 'tracking_code', 'colis_code'] as $key) {
            if (isset($body[$key]) && is_scalar($body[$key])) {
                return (string) $body[$key];
            }
        }

        if (isset($body['code']) && is_string($body['code']) && $body['code'] !== 'ok') {
            return $body['code'];
        }

        return null;
    }

    /**
     * M12: Vitis Express has no cancel/update/delete endpoint in its
     * documentation, so a parcel cannot be cancelled through the API.
     * Cancel it from the Vitis dashboard instead.
     */
    public function supportsCancel(): bool
    {
        return false;
    }

    public function cancelParcel(string $parcelCode): array
    {
        return ['success' => false, 'message' => 'Vitis Express has no cancel endpoint in its API documentation — cancel parcel '.$parcelCode.' from the Vitis dashboard.'];
    }
}
