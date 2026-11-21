<?php

namespace App\Services\Delivery;

/**
 * Contract every delivery company must implement.
 *
 * Adding a new company means: implement this interface, add its config
 * section under general.delivery_providers in
 * packages/Webkul/Admin/src/Config/system.php, add its language keys,
 * and register it in DeliveryProviderManager::$providers. Nothing else
 * needs to change: orders, the admin order view and the test-connection
 * action all talk to this interface only.
 *
 * The normalized parcel data passed to createParcel() is:
 * [
 *   'fullname'    => (string) recipient full name,
 *   'phone'       => (string) recipient phone,
 *   'city'        => (string) recipient city (name as stored on the order),
 *   'address'     => (string) recipient street address,
 *   'price'       => (float)  cash-on-delivery amount to collect,
 *   'products'    => [['name' => (string), 'qty' => (int)], ...],
 *   'note'        => (string) free note for the carrier,
 *   'internal_id' => (string) our order reference (increment id),
 * ]
 */
interface DeliveryProviderInterface
{
    /**
     * Machine name used in config and in the delivery_parcels table.
     */
    public function getName(): string;

    /**
     * Human readable name shown in the admin.
     */
    public function getLabel(): string;

    /**
     * Whether the provider is switched on in the admin configuration.
     */
    public function isEnabled(): bool;

    /**
     * Whether the credentials needed to call the provider are set.
     */
    public function hasCredentials(): bool;

    /**
     * Create a parcel at the provider for one order.
     */
    public function createParcel(array $data): DeliveryResult;

    /**
     * Track a parcel by its code.
     *
     * @return array{success: bool, status: ?string, events: array, error: ?string}
     */
    public function trackParcel(string $code): array;

    /**
     * List the provider's parcel states.
     *
     * @return array
     */
    public function listStatuses(): array;

    /**
     * List the provider's served cities.
     *
     * @return array
     */
    public function listCities(): array;

    /**
     * Check the configured credentials with a cheap read call.
     *
     * @return array{success: bool, message: string}
     */
    public function testConnection(): array;
}
