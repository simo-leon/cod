<?php

namespace App\Services\Delivery;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sendit (sendit.ma, Morocco) delivery provider.
 *
 * UNVERIFIED — built from the official Sendit WooCommerce plugin
 * (v2.2.11) source code. No live call has ever succeeded with a real
 * merchant account: the login, create-parcel, tracking and district
 * shapes below come from that plugin, not from a verified live
 * response. Needs a real Sendit public key + secret key to verify.
 *
 * Spec (from the plugin):
 * - Base URL: https://app.sendit.ma/api/v1
 * - Auth: POST /login with JSON {"public_key", "secret_key"} and
 *   header `Content-Type: application/json; charset=utf-8`. On HTTP
 *   200 with data.token the token is kept for 55 minutes. Every later
 *   call sends `Authorization: Bearer <token>` + `Accept:
 *   application/json`. On 401/403 the call is retried once with a
 *   fresh token.
 * - Districts: GET /districts?page=N, each row
 *   {id, name, arabic_name, price, ville, pickup_district}. The
 *   pickup district is the row with pickup_district=1 (or
 *   ville='Casablanca').
 * - Create: POST /deliveries. Success = HTTP 200 + success=true +
 *   data.code (string, shape DXXXXXXX). The parcel code field is
 *   `code`. On 429 the API answers rate-limited with `retry-after`.
 * - Details/update: GET|PUT /deliveries/{code}. Labels:
 *   POST /deliveries/getlabels {"codesToPrint": "code1,code2",
 *   "printFormat": 1}.
 * - Statuses: PENDING, TO_PREPARE, NEW_DESTINATION, TOPICKUP,
 *   PICKEDUP, WAREHOUSE, TRANSIT, DISTRIBUTED, UNREACHABLE,
 *   POSTPONED, DELIVERING, DELIVERED, CANCELED, REJECTED, RETURNED.
 *   Final: DELIVERED, CANCELED, REJECTED, RETURNED.
 *
 * The credentials are read from core_config on every call and are
 * never logged, never stored anywhere else, and never included in
 * any exception message.
 */
class SenditProvider implements DeliveryProviderInterface
{
    public const BASE_URL = 'https://app.sendit.ma/api/v1';

    public const CONFIG_KEY = 'general.delivery_providers.sendit';

    /**
     * Sendit statuses, mapped to the unified COD statuses used in
     * delivery_parcels.last_status. The raw provider status is kept
     * in the tracking events.
     */
    public const STATUS_MAP = [
        'PENDING' => 'pending',
        'TO_PREPARE' => 'pending',
        'NEW_DESTINATION' => 'pending',
        'UNREACHABLE' => 'pending',
        'POSTPONED' => 'pending',
        'TOPICKUP' => 'in_transit',
        'PICKEDUP' => 'in_transit',
        'WAREHOUSE' => 'in_transit',
        'TRANSIT' => 'in_transit',
        'DISTRIBUTED' => 'in_transit',
        'DELIVERING' => 'out_for_delivery',
        'DELIVERED' => 'delivered',
        'CANCELED' => 'cancelled',
        'REJECTED' => 'cancelled',
        'RETURNED' => 'returned',
    ];

    public const FINAL_STATUSES = ['DELIVERED', 'CANCELED', 'REJECTED', 'RETURNED'];

    public function getName(): string
    {
        return 'sendit';
    }

    public function getLabel(): string
    {
        return 'Sendit';
    }

    public function isEnabled(): bool
    {
        return (bool) core()->getConfigData(self::CONFIG_KEY.'.status');
    }

    public function hasCredentials(): bool
    {
        return ! empty($this->getPublicKey()) && ! empty($this->getSecretKey());
    }

    public function getPublicKey(): ?string
    {
        $key = core()->getConfigData(self::CONFIG_KEY.'.public_key');

        return $key ? (string) $key : null;
    }

    public function getSecretKey(): ?string
    {
        $key = core()->getConfigData(self::CONFIG_KEY.'.secret_key');

        return $key ? (string) $key : null;
    }

    public function createParcel(array $data): DeliveryResult
    {
        if (! $this->hasCredentials()) {
            return DeliveryResult::failed('Sendit public key or secret key is not configured.');
        }

        $phone = $this->normalizePhone($data['phone'] ?? '');

        if ($phone === null) {
            return DeliveryResult::failed(
                'Sendit: phone number "'.($data['phone'] ?? '').'" is not a valid Moroccan mobile number (expected 0[5-7]xxxxxxxx).'
            );
        }

        $districtId = $this->resolveDistrictId($data['city'] ?? '');

        if ($districtId === null) {
            return DeliveryResult::failed(
                'Sendit: no district found for city "'.($data['city'] ?? '').'". Pick a Sendit district in the configuration (fallback district id) or fix the city name.'
            );
        }

        $pickupDistrictId = $this->resolvePickupDistrictId();

        if ($pickupDistrictId === null) {
            return DeliveryResult::failed('Sendit: no pickup district found. Set the pickup district id in the configuration.');
        }

        $payload = [
            'reference' => (string) ($data['internal_id'] ?? ''),
            'name' => $data['fullname'],
            'amount' => (float) $data['price'],
            'phone' => $phone,
            // Sendit expects "street, city" in a single address field.
            'address' => trim(($data['address'] ?? '').', '.($data['city'] ?? ''), ', '),
            'pickup_district_id' => $pickupDistrictId,
            'district_id' => $districtId,
            'comment' => mb_substr($data['note'] ?? '', 0, 500),
            'allow_try' => (int) (bool) core()->getConfigData(self::CONFIG_KEY.'.allow_try'),
            'allow_open' => (int) (bool) core()->getConfigData(self::CONFIG_KEY.'.allow_open'),
            // Products is a plain string, NOT an array:
            // 'T-shirt Logo (Taille: M, Couleur: Noir) x2'.
            'products' => collect($data['products'] ?? [])
                ->map(fn ($item) => $item['name'].' x'.(int) $item['qty'])
                ->implode(', '),
        ];

        try {
            $response = $this->authedRequest(fn ($token) => $this->client($token)->post(self::BASE_URL.'/deliveries', $payload));
        } catch (ConnectionException $e) {
            return DeliveryResult::failed('Connection to Sendit failed: '.$e->getMessage());
        }

        if ($response->status() === 429) {
            $retryAfter = $response->header('retry-after');

            return DeliveryResult::failed(
                'Sendit: rate limited'.($retryAfter ? ' (retry after '.$retryAfter.'s)' : '').'.',
                $this->safeBody($response)
            );
        }

        $body = $this->safeBody($response);

        if (! $response->successful() || ($body['success'] ?? false) !== true || empty($body['data']['code'])) {
            return DeliveryResult::failed(
                'Sendit: '.$this->errorMessage($response, $body),
                $body
            );
        }

        $parcelCode = (string) $body['data']['code'];

        Log::info('Sendit: parcel created', [
            'internal_id' => $data['internal_id'] ?? null,
            'parcel_code' => $parcelCode,
        ]);

        return DeliveryResult::ok($parcelCode, 'sent', $body);
    }

    public function trackParcel(string $code): array
    {
        if (! $this->hasCredentials()) {
            return ['success' => false, 'status' => null, 'events' => [], 'error' => 'Sendit public key or secret key is not configured.'];
        }

        try {
            $response = $this->authedRequest(fn ($token) => $this->client($token)->get(self::BASE_URL.'/deliveries/'.urlencode($code)));
        } catch (ConnectionException $e) {
            return ['success' => false, 'status' => null, 'events' => [], 'error' => 'Connection to Sendit failed: '.$e->getMessage()];
        }

        if (! $response->successful()) {
            return ['success' => false, 'status' => null, 'events' => [], 'error' => $this->errorMessage($response)];
        }

        $body = $this->safeBody($response);
        $delivery = $body['data'] ?? [];

        if (! is_array($delivery)) {
            return ['success' => false, 'status' => null, 'events' => [], 'error' => 'Sendit: unexpected tracking response shape.'];
        }

        // The exact details-response shape is UNVERIFIED (no live call
        // yet): look for the status in the usual places.
        $rawStatus = $delivery['status'] ?? $delivery['state'] ?? $delivery['status_code'] ?? null;
        $events = $delivery['history'] ?? $delivery['tracking'] ?? $delivery['events'] ?? [];

        return [
            'success' => true,
            'status' => is_string($rawStatus) ? $this->normalizeStatus($rawStatus) : null,
            'events' => is_array($events) ? $events : [],
            'error' => null,
        ];
    }

    public function listStatuses(): array
    {
        // No status-list endpoint in the plugin; the statuses are the
        // fixed list from the Sendit API as used by the plugin.
        return array_keys(self::STATUS_MAP);
    }

    public function listCities(): array
    {
        if (! $this->hasCredentials()) {
            return [];
        }

        return Cache::remember('sendit_districts', 86400, function () {
            try {
                return $this->fetchAllDistricts();
            } catch (ConnectionException) {
                return [];
            }
        });
    }

    public function testConnection(): array
    {
        if (! $this->isEnabled()) {
            return ['success' => false, 'message' => 'Sendit is disabled in the configuration.'];
        }

        if (! $this->hasCredentials()) {
            return ['success' => false, 'message' => 'Sendit public key or secret key is missing.'];
        }

        // A login is the cheapest credential check: it proves the keys
        // without creating anything. A network failure is reported as
        // such, not as wrong keys: the two need different fixes.
        $token = $this->login(true);

        if ($token === null) {
            return match ($this->lastLoginError) {
                'connection' => ['success' => false, 'message' => 'Sendit: could not reach the Sendit API (network error) — check the server connection, not the keys.'],
                'server' => ['success' => false, 'message' => 'Sendit: the Sendit API answered with an error — try again later.'],
                default => ['success' => false, 'message' => 'Sendit: login failed — check the public key and secret key.'],
            };
        }

        return ['success' => true, 'message' => 'Connection to Sendit works.'];
    }

    /**
     * Map a Sendit status to the unified COD status.
     */
    public function normalizeStatus(string $status): string
    {
        return self::STATUS_MAP[mb_strtoupper($status)] ?? mb_strtolower($status);
    }

    /**
     * Normalize a phone number to the Moroccan mobile shape
     * 0[5-7]xxxxxxxx. Returns null when it cannot be normalized.
     */
    public function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '212')) {
            $digits = '0'.substr($digits, 3);
        }

        return preg_match('/^0[5-7]\d{8}$/', $digits) ? $digits : null;
    }

    /**
     * Find the Sendit district id for an order city: first by the
     * district's parent city (ville), then by district name / arabic
     * name, then by the admin-configured fallback district id.
     */
    protected function resolveDistrictId(string $city): ?int
    {
        $fallback = core()->getConfigData(self::CONFIG_KEY.'.fallback_district_id');
        $fallbackId = ($fallback !== null && $fallback !== '' && (int) $fallback > 0) ? (int) $fallback : null;

        $districts = $this->listCities();
        $wanted = mb_strtolower(trim($city));

        foreach ($districts as $district) {
            if ($wanted !== '' && mb_strtolower((string) ($district['ville'] ?? '')) === $wanted) {
                return (int) $district['id'];
            }
        }

        foreach ($districts as $district) {
            if ($wanted !== '' && in_array($wanted, [
                mb_strtolower((string) ($district['name'] ?? '')),
                mb_strtolower((string) ($district['arabic_name'] ?? '')),
            ], true)) {
                return (int) $district['id'];
            }
        }

        return $fallbackId;
    }

    /**
     * The merchant pickup district: admin-configured, otherwise the
     * first district flagged pickup_district=1 (or in Casablanca).
     */
    protected function resolvePickupDistrictId(): ?int
    {
        $configured = core()->getConfigData(self::CONFIG_KEY.'.pickup_district_id');

        if ($configured !== null && $configured !== '' && (int) $configured > 0) {
            return (int) $configured;
        }

        foreach ($this->listCities() as $district) {
            if (! empty($district['pickup_district'])) {
                return (int) $district['id'];
            }
        }

        foreach ($this->listCities() as $district) {
            if (mb_strtolower((string) ($district['ville'] ?? '')) === 'casablanca') {
                return (int) $district['id'];
            }
        }

        return null;
    }

    /**
     * Fetch every district page. The plugin paginates with ?page=N;
     * stop on an empty page (and never loop forever).
     *
     * @return array<int, array>
     */
    protected function fetchAllDistricts(): array
    {
        $all = [];

        for ($page = 1; $page <= 50; $page++) {
            $response = $this->authedRequest(fn ($token) => $this->client($token)->get(self::BASE_URL.'/districts', ['page' => $page]));

            if (! $response->successful()) {
                break;
            }

            $rows = $this->safeBody($response)['data'] ?? [];

            if (! is_array($rows) || $rows === []) {
                break;
            }

            foreach ($rows as $row) {
                if (is_array($row) && isset($row['id'])) {
                    $all[] = $row;
                }
            }
        }

        return $all;
    }

    /**
     * Run a request with a cached token; on 401/403 retry once with a
     * freshly logged-in token.
     */
    protected function authedRequest(callable $request)
    {
        $response = $request($this->login());

        if ($response !== null && in_array($response->status(), [401, 403], true)) {
            $response = $request($this->login(true));
        }

        return $response;
    }

    /**
     * Why the last login() call failed, so testConnection() can tell a
     * network failure apart from wrong keys: 'connection' when the API
     * could not be reached at all, 'credentials' when it answered but
     * refused the login, 'server' for any other HTTP error.
     */
    protected ?string $lastLoginError = null;

    /**
     * Log in and return a Bearer <redacted> The plugin caches it for
     * 55 minutes; we keep 50 minutes as a safety margin.
     */
    protected function login(bool $fresh = false): ?string
    {
        if (! $this->hasCredentials()) {
            return null;
        }

        $cacheKey = 'sendit_api_token';

        if (! $fresh && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $this->lastLoginError = null;

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json; charset=utf-8',
                'Accept' => 'application/json',
            ])->timeout(15)->post(self::BASE_URL.'/login', [
                'public_key' => $this->getPublicKey(),
                'secret_key' => $this->getSecretKey(),
            ]);
        } catch (ConnectionException) {
            $this->lastLoginError = 'connection';

            return null;
        }

        if (! $response->successful()) {
            $this->lastLoginError = in_array($response->status(), [401, 403], true) ? 'credentials' : 'server';

            return null;
        }

        $token = $this->safeBody($response)['data']['token'] ?? null;

        if (! is_string($token) || $token === '') {
            $this->lastLoginError = 'credentials';

            return null;
        }

        Cache::put($cacheKey, $token, 3000);

        return $token;
    }

    protected function client(?string $token)
    {
        return Http::withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->timeout(15);
    }

    protected function errorMessage($response, ?array $body = null): string
    {
        $body ??= $this->safeBody($response);

        if (isset($body['message']) && is_string($body['message'])) {
            return $body['message'];
        }

        if (isset($body['error']) && is_string($body['error'])) {
            return $body['error'];
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
}
