<?php

namespace App\Services\Delivery;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Speedaf Express (Morocco + other countries) delivery provider.
 *
 * UNVERIFIED — built from the official Speedaf WooCommerce plugin
 * (v2.0.6) source code: sdk/Configuration.php, sdk/ApiServices.php,
 * sdk/CryptoServices.php, src/Speedaf/CreateOrder.php and
 * src/Speedaf/Carrier.php. No live call has ever succeeded with a
 * real merchant account: the endpoints, the DES signing and the
 * field names below come from that plugin, not from a verified live
 * response. Needs a real Speedaf merchant API key to verify.
 *
 * Spec (from the plugin):
 * - Production base URL: https://apis.speedaf.com/ (Express);
 *   UAT OMS: https://uat-api.speedaf.com/; test base:
 *   http://8.214.27.92:8480/. Morocco is supported
 *   (allowedCurrencies includes 'MA' => 'Morocco').
 * - Two-layer auth: (a) the merchant API key is entered in the
 *   settings; saving it calls getCustomerInfo, which returns
 *   {bizType, customerCode, warehouseCode, countryCode, apiKey} and
 *   the customerCode is kept. (b) Every Express call is
 *   POST base_path + path + ?timestamp=<ms>&appCode=880056 with a
 *   DES-CBC encrypted body (IV bytes 12 34 56 78 90 AB CD EF) of the
 *   JSON {"data":"<order JSON as string>","sign":"<md5(timestamp +
 *   secret_key + JSON)>"} and Content-Type: application/json. The
 *   OMS pattern (getCustomerInfo) uses the same MD5 signature and
 *   query params but NO DES encryption and Content-Type: text/plain.
 * - Response envelope: {success, data, error:{message}}; in Express
 *   mode `data` is a DES-encrypted string to decrypt first.
 *   success===false means an error with error.message.
 * - Endpoints (all POST): create /open-api/express/order/createOrder,
 *   batch /open-api/express/order/batchCreateOrders, update
 *   /open-api/express/order/updateOrder, cancel
 *   /open-api/express/order/cancelOrder (body is an ARRAY of
 *   {customerCode, billCode, cancelReason, cancelBy, cancelTel}),
 *   track /open-api/express/track/query (body {mailNoList:
 *   [billCode]}, answer is a list whose first item is
 *   {mailNo, tracks: [{time, msgEng / message}]}), subscribe
 *   open-api/express/track/subscribe ({mailNo, customerCode,
 *   notifyUrl}), print /open-api/express/order/print
 *   ({waybillNoList, labelType}), customer info
 *   /open-api/plugin/getCustomerInfo (OMS pattern), OMS create
 *   /open-api/oms/express/order/createOrder.
 * - Parcel code field: `billCode` (success = success===true and a
 *   billCode is present). There is no cities endpoint (the city is
 *   free text) and no status-list endpoint.
 *
 * STRICT RESERVATIONS (from the plugin code itself):
 * - The meanings of the codes PA02 / PT01 / DE01 / TT01 are NOT
 *   explained anywhere in the plugin. Do NOT assume PA02 means COD;
 *   they are sent exactly as the plugin sends them and must be
 *   confirmed with Speedaf.
 * - The shared secret below is Speedaf's own key published in clear
 *   text inside its public WordPress plugin (sdk/Configuration.php:
 *   production secret_key '5oQpOLF7', appCode '880056'). It is NOT a
 *   merchant credential: every Speedaf merchant uses the same one,
 *   and the merchant is identified by the API key + customerCode.
 *   It is stored here as a constant only because the plugin itself
 *   publishes it, and the request cannot be signed without it.
 * - DES-CBC is provided by OpenSSL's legacy provider. This VM's
 *   PHP/OpenSSL 3 build does NOT offer des-cbc, so every Speedaf
 *   call here fails fast with a clear message instead of sending a
 *   request the server would reject. The WordPress plugin falls back
 *   to sending the signing JSON in clear text when DES is missing;
 *   we deliberately do not copy that fallback.
 *
 * The merchant API key is read from core_config on every call and is
 * never logged, never stored anywhere else, and never included in
 * any exception message. The shared secret is likewise never logged.
 */
class SpeedafExpressProvider implements DeliveryProviderInterface
{
    public const BASE_URL = 'https://apis.speedaf.com/';

    public const OMS_BASE_URL = 'https://apis.speedaf.com/';

    public const CONFIG_KEY = 'general.delivery_providers.speedaf_express';

    /** Speedaf application code, published in the public plugin. */
    public const APP_CODE = '880056';

    /**
     * Speedaf's shared signing secret, published in clear text in the
     * public WooCommerce plugin (sdk/Configuration.php, production
     * value). NOT a merchant credential — see the class docblock.
     */
    public const SHARED_SECRET = '5oQpOLF7';

    /** DES-CBC initialisation vector from the plugin (fixed bytes). */
    public const DES_IV = "\x12\x34\x56\x78\x90\xAB\xCD\xEF";

    /** API error codes from the plugin's sdk/ErrorCodes.php. */
    public const ERROR_CODES = [
        60001 => 'request timeout',
        60002 => 'api unauthorized',
        60003 => 'invalid appCode',
        60004 => 'signature error',
        60005 => 'md5 encryption failed',
        60006 => 'des initialize failed',
        60007 => 'des encryption failed',
        60008 => 'des decryption failed',
        60009 => 'method request error',
        60010 => 'request data error',
        60011 => 'ip address is not in the whitelist',
        10005 => 'ip address is not in the whitelist',
    ];

    public function getName(): string
    {
        return 'speedaf_express';
    }

    public function getLabel(): string
    {
        return 'Speedaf Express';
    }

    public function isEnabled(): bool
    {
        return (bool) core()->getConfigData(self::CONFIG_KEY.'.status');
    }

    public function hasCredentials(): bool
    {
        return ! empty($this->getApiKey());
    }

    public function getApiKey(): ?string
    {
        $key = core()->getConfigData(self::CONFIG_KEY.'.api_key');

        return $key ? (string) $key : null;
    }

    /**
     * Whether this PHP/OpenSSL build can do the DES-CBC encryption
     * Speedaf requires. OpenSSL 3 only offers des-cbc through the
     * legacy provider.
     */
    public function desAvailable(): bool
    {
        return in_array('des-cbc', openssl_get_cipher_methods(), true)
            && openssl_encrypt('test', 'des-cbc', self::SHARED_SECRET, 0, self::DES_IV) !== false;
    }

    public function createParcel(array $data): DeliveryResult
    {
        if (! $this->desAvailable()) {
            return DeliveryResult::failed('Speedaf Express: DES-CBC encryption is not available in this PHP/OpenSSL build (OpenSSL 3 needs the legacy provider). Speedaf requests cannot be signed here.');
        }

        if (! $this->hasCredentials()) {
            return DeliveryResult::failed('Speedaf Express API key is not configured.');
        }

        $customerCode = $this->getCustomerCode();

        if ($customerCode === null) {
            return DeliveryResult::failed('Speedaf Express: could not get the customer code for this API key (getCustomerInfo failed). Check the API key.');
        }

        $senderName = (string) (core()->getConfigData(self::CONFIG_KEY.'.sender_name') ?? '');
        $senderPhone = (string) (core()->getConfigData(self::CONFIG_KEY.'.sender_phone') ?? '');
        $senderCity = (string) (core()->getConfigData(self::CONFIG_KEY.'.sender_city') ?? '');
        $senderAddress = (string) (core()->getConfigData(self::CONFIG_KEY.'.sender_address') ?? '');

        if ($senderName === '' || $senderPhone === '' || $senderCity === '' || $senderAddress === '') {
            return DeliveryResult::failed('Speedaf Express: sender name, phone, city and address must all be set in the configuration (they are sent on every parcel).');
        }

        $acceptCountry = strtoupper((string) ($data['country'] ?? 'MA')) ?: 'MA';
        $sendCountry = 'MA';
        $domestic = $acceptCountry === $sendCountry;
        $city = (string) ($data['city'] ?? '');
        $state = (string) ($data['state'] ?? '');

        $itemList = [];
        $totalQty = 0;

        foreach ($data['products'] ?? [] as $item) {
            $totalQty += (int) ($item['qty'] ?? 0);

            // The plugin skips items without a SKU in direct mode
            // (and throws in OMS/warehouse mode); we only do direct
            // mode, so skip silently like the plugin does.
            if (empty($item['sku'])) {
                continue;
            }

            $itemList[] = [
                'sku' => (string) $item['sku'],
                'goodsName' => (string) ($item['name'] ?? ''),
                'goodsQTY' => (int) ($item['qty'] ?? 0),
                'goodsValue' => (float) ($item['total'] ?? 0),
                'goodsType' => 'IT01',
                'blInsure' => 0,
                'battery' => 0,
                'goodsUnitPrice' => (float) ($item['price'] ?? 0),
                'currencyType' => 'MAD',
            ];
        }

        // Codes copied verbatim from the plugin. Their meanings are
        // NOT documented in the plugin — see the class docblock.
        $payload = [
            'customerCode' => $customerCode,
            'platformSource' => 'woocommerce',
            'parcelType' => $domestic ? 'PT01' : 'PT02',
            'deliveryType' => 'DE01',
            'payMethod' => 'PA02',
            'acceptName' => $data['fullname'],
            'acceptMobile' => $data['phone'],
            'acceptCountryCode' => $acceptCountry,
            'acceptProvinceName' => $city,
            'acceptCityName' => $city,
            'acceptDistrictName' => $state !== '' ? $state : $city,
            'acceptAddress' => $data['address'] ?? '',
            'sendName' => $senderName,
            'sendAddress' => $senderAddress,
            'sendMobile' => $senderPhone,
            'sendCityName' => $senderCity,
            'sendCountryCode' => $sendCountry,
            'sendProvinceName' => $senderCity,
            'sendDistrictName' => $senderCity,
            'parcelWeight' => 0.05,
            'goodsQTY' => $totalQty,
            'pickUpAging' => 1,
            'piece' => 1,
            'customOrderNo' => '#'.($data['internal_id'] ?? ''),
            'transportType' => $domestic ? 'TT01' : 'TT02',
            'shipType' => $domestic ? 'ST01' : 'ST02',
            // COD orders only reach this provider (see
            // DeliveryProviderManager), so the COD fee is always the
            // order total, exactly as the plugin sets it for COD.
            'codFee' => (float) $data['price'],
            'currencyType' => 'MAD',
            'remark' => $data['note'] ?? '',
            'itemList' => $itemList,
        ];

        try {
            $result = $this->expressRequest('/open-api/express/order/createOrder', $payload, 60);
        } catch (ConnectionException $e) {
            return DeliveryResult::failed('Connection to Speedaf Express failed: '.$e->getMessage());
        }

        if (! $result['success']) {
            return DeliveryResult::failed('Speedaf Express: '.$result['error'], $result['raw']);
        }

        $billCode = $result['data']['billCode'] ?? null;

        if (! is_string($billCode) || $billCode === '') {
            return DeliveryResult::failed('Speedaf Express: the order was accepted but no billCode was returned.', $result['raw']);
        }

        Log::info('Speedaf Express: parcel created', [
            'internal_id' => $data['internal_id'] ?? null,
            'parcel_code' => $billCode,
        ]);

        return DeliveryResult::ok($billCode, 'sent', $result['raw']);
    }

    public function trackParcel(string $code): array
    {
        if (! $this->desAvailable()) {
            return ['success' => false, 'status' => null, 'events' => [], 'error' => 'Speedaf Express: DES-CBC encryption is not available in this PHP/OpenSSL build.'];
        }

        if (! $this->hasCredentials()) {
            return ['success' => false, 'status' => null, 'events' => [], 'error' => 'Speedaf Express API key is not configured.'];
        }

        try {
            $result = $this->expressRequest('/open-api/express/track/query', ['mailNoList' => [$code]], 30);
        } catch (ConnectionException $e) {
            return ['success' => false, 'status' => null, 'events' => [], 'error' => 'Connection to Speedaf Express failed: '.$e->getMessage()];
        }

        if (! $result['success']) {
            return ['success' => false, 'status' => null, 'events' => [], 'error' => 'Speedaf Express: '.$result['error']];
        }

        // The answer is a list; the plugin displays the first item:
        // {mailNo, tracks: [{time, msgEng / message}]}.
        $items = $result['data'];
        $first = is_array($items) ? ($items[0] ?? null) : null;

        if (! is_array($first)) {
            return ['success' => false, 'status' => null, 'events' => [], 'error' => 'Speedaf Express: unexpected tracking response shape.'];
        }

        $tracks = $first['tracks'] ?? [];
        $tracks = is_array($tracks) ? array_values($tracks) : [];
        $last = $tracks !== [] ? end($tracks) : null;

        // Speedaf tracking has no status code, only free-text events,
        // so the last event text is the status.
        $status = is_array($last) ? ($last['msgEng'] ?? $last['message'] ?? null) : null;

        return [
            'success' => true,
            'status' => is_string($status) && $status !== '' ? $status : null,
            'events' => $tracks,
            'error' => null,
        ];
    }

    public function listStatuses(): array
    {
        // No status-list endpoint exists in the plugin.
        return [];
    }

    public function listCities(): array
    {
        // No cities endpoint exists in the plugin; the city is free text.
        return [];
    }

    public function testConnection(): array
    {
        if (! $this->desAvailable()) {
            return ['success' => false, 'message' => 'Speedaf Express: DES-CBC encryption is not available in this PHP/OpenSSL build (OpenSSL 3 needs the legacy provider).'];
        }

        if (! $this->isEnabled()) {
            return ['success' => false, 'message' => 'Speedaf Express is disabled in the configuration.'];
        }

        if (! $this->hasCredentials()) {
            return ['success' => false, 'message' => 'Speedaf Express API key is missing.'];
        }

        // getCustomerInfo is the plugin's own credential check when an
        // API key is saved: it proves the key and returns the
        // customerCode. No parcel is created.
        $info = $this->getCustomerInfo(true);

        if ($info === null) {
            return ['success' => false, 'message' => 'Speedaf Express: getCustomerInfo failed — check the API key.'];
        }

        $customerCode = $info['customerCode'] ?? null;

        return [
            'success' => true,
            'message' => 'Connection to Speedaf Express works.'
                .($customerCode ? ' (customer code: '.$customerCode.')' : ''),
        ];
    }

    /**
     * The merchant customer code for the configured API key, from
     * getCustomerInfo, cached for a day. Returns null when the call
     * fails (wrong key, unreachable host, ...).
     */
    public function getCustomerCode(): ?string
    {
        $info = $this->getCustomerInfo();

        $code = $info['customerCode'] ?? null;

        return is_string($code) && $code !== '' ? $code : null;
    }

    /**
     * Call getCustomerInfo (OMS pattern: signed, NOT DES-encrypted).
     * When the admin set a customer code explicitly, it wins and no
     * call is made.
     */
    protected function getCustomerInfo(bool $fresh = false): ?array
    {
        $configured = (string) (core()->getConfigData(self::CONFIG_KEY.'.customer_code') ?? '');

        if ($configured !== '') {
            return ['customerCode' => $configured];
        }

        if (! $this->hasCredentials()) {
            return null;
        }

        $cacheKey = 'speedaf_customer_info';

        if (! $fresh && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        try {
            $result = $this->omsRequest('/open-api/plugin/getCustomerInfo', ['apiKey' => $this->getApiKey()], 30);
        } catch (ConnectionException) {
            return null;
        }

        if (! $result['success'] || ! is_array($result['data'])) {
            return null;
        }

        Cache::put($cacheKey, $result['data'], 86400);

        return $result['data'];
    }

    /**
     * One Express (DES-encrypted) call.
     *
     * @return array{success: bool, data: mixed, error: ?string, raw: array}
     */
    protected function expressRequest(string $path, array $payload, int $timeout): array
    {
        $timestamp = $this->timestampMs();
        $encrypted = $this->encrypt($payload, $timestamp);

        if ($encrypted === null) {
            return ['success' => false, 'data' => null, 'error' => 'DES-CBC encryption failed.', 'raw' => []];
        }

        $url = rtrim(self::BASE_URL, '/').$path.'?timestamp='.$timestamp.'&appCode='.self::APP_CODE;

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->timeout($timeout)
            ->retry(1, 500, fn ($exception) => $exception instanceof ConnectionException)
            ->withBody($encrypted, 'application/json')
            ->post($url);

        return $this->handleEnvelope($response, true);
    }

    /**
     * One OMS-pattern call (signed JSON, no DES encryption).
     *
     * @return array{success: bool, data: mixed, error: ?string, raw: array}
     */
    protected function omsRequest(string $path, array $payload, int $timeout): array
    {
        $timestamp = $this->timestampMs();
        $json = json_encode($payload, JSON_INVALID_UTF8_IGNORE);

        $body = json_encode([
            'data' => $json,
            'sign' => md5($timestamp.self::SHARED_SECRET.$json),
        ]);

        $url = rtrim(self::OMS_BASE_URL, '/').$path.'?timestamp='.$timestamp.'&appCode='.self::APP_CODE;

        $response = Http::withHeaders([
            'Content-Type' => 'text/plain',
        ])->timeout($timeout)
            ->retry(1, 500, fn ($exception) => $exception instanceof ConnectionException)
            ->withBody($body, 'text/plain')
            ->post($url);

        return $this->handleEnvelope($response, false);
    }

    /**
     * Read the {success, data, error} envelope. In Express mode the
     * data field is a DES-encrypted string to decrypt first.
     */
    protected function handleEnvelope($response, bool $encrypted): array
    {
        $body = $this->safeBody($response);

        if (! isset($body['success'])) {
            return [
                'success' => false,
                'data' => null,
                'error' => 'Request failed (HTTP '.$response->status().').',
                'raw' => $body,
            ];
        }

        if ($body['success'] === false) {
            $error = $body['error'] ?? [];
            $message = is_array($error) ? ($error['message'] ?? 'Unknown error') : (string) $error;
            $code = is_array($error) ? ($error['code'] ?? null) : null;

            if ($code !== null && isset(self::ERROR_CODES[(int) $code])) {
                $message .= ' ('.self::ERROR_CODES[(int) $code].')';
            }

            return ['success' => false, 'data' => null, 'error' => $message, 'raw' => $body];
        }

        $data = $body['data'] ?? null;

        if ($encrypted && is_string($data)) {
            $data = $this->decrypt($data);
        } elseif (is_string($data) && ($data !== '' && ($data[0] === '{' || $data[0] === '['))) {
            $data = json_decode($data, true);
        }

        return ['success' => true, 'data' => $data, 'error' => null, 'raw' => $body];
    }

    /**
     * DES-CBC encrypt the {"data","sign"} JSON, exactly as the
     * plugin's CryptoServices::encrypt does. Returns null when DES
     * is unavailable or encryption fails.
     */
    protected function encrypt(array $payload, int $timestamp): ?string
    {
        $json = json_encode($payload, JSON_INVALID_UTF8_IGNORE);

        $envelope = json_encode([
            'data' => $json,
            'sign' => md5($timestamp.self::SHARED_SECRET.$json),
        ]);

        if (! $this->desAvailable()) {
            return null;
        }

        $encrypted = openssl_encrypt($envelope, 'des-cbc', self::SHARED_SECRET, 0, self::DES_IV);

        return $encrypted === false ? null : $encrypted;
    }

    /**
     * DES-CBC decrypt a response data string, exactly as the plugin's
     * CryptoServices::decrypt does.
     */
    protected function decrypt(string $data)
    {
        $decrypted = openssl_decrypt($data, 'des-cbc', self::SHARED_SECRET, 0, self::DES_IV);

        if (! is_string($decrypted) || $decrypted === '') {
            return $data;
        }

        if ($decrypted[0] === '{' || $decrypted[0] === '[') {
            return json_decode($decrypted, true);
        }

        return $decrypted;
    }

    protected function timestampMs(): int
    {
        return (int) ceil(microtime(true) * 1000);
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
