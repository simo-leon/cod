<?php

namespace App\Services\Delivery;

/**
 * Normalize raw delivery-provider tracking statuses to one unified
 * vocabulary, so the sync command, the manual refresh and the COD
 * status mapping all see the same values.
 *
 * Unified statuses:
 * - pending: parcel created at the carrier, not picked up yet.
 * - in_transit: picked up / moving between hubs.
 * - out_for_delivery: out with the courier for delivery today.
 * - delivered: handed to the customer.
 * - returned: refused by the customer / sent back.
 * - cancelled: cancelled at the carrier (by the merchant or the carrier).
 *
 * Status map, by provider (from the provider code / docs):
 * - Sendit: trackParcel() already normalizes through
 *   SenditProvider::STATUS_MAP (PENDING/TO_PREPARE/... => pending,
 *   TOPICKUP/PICKEDUP/WAREHOUSE/TRANSIT/DISTRIBUTED => in_transit,
 *   DELIVERING => out_for_delivery, DELIVERED => delivered,
 *   CANCELED/REJECTED => cancelled, RETURNED => returned).
 * - Vitis Express: GET track/{code} returns events whose `status`
 *   is a French label (list-status: 3 Livré, 31 Refusé, 5 Annulé —
 *   the numeric ids are also accepted, in case an event carries the
 *   id instead of the label). Ramassé = picked up, Expédié / En cours
 *   de livraison = out for delivery.
 * - Speedaf Express: track returns free-text English event messages
 *   only (no status code), so the last event text is matched on
 *   keywords; anything unrecognized but non-empty means the parcel
 *   exists and is moving, i.e. in_transit.
 *
 * Unknown or empty input returns null: the sync then leaves the
 * parcel and the COD order untouched instead of guessing.
 */
class TrackingStatusMapper
{
    public const PENDING = 'pending';

    public const IN_TRANSIT = 'in_transit';

    public const OUT_FOR_DELIVERY = 'out_for_delivery';

    public const DELIVERED = 'delivered';

    public const RETURNED = 'returned';

    public const CANCELLED = 'cancelled';

    public const UNIFIED_STATUSES = [
        self::PENDING,
        self::IN_TRANSIT,
        self::OUT_FOR_DELIVERY,
        self::DELIVERED,
        self::RETURNED,
        self::CANCELLED,
    ];

    /**
     * Parcel statuses after which no further sync is useful.
     */
    public const FINAL_STATUSES = [
        self::DELIVERED,
        self::RETURNED,
        self::CANCELLED,
    ];

    /**
     * Vitis Express numeric status ids (from GET list-status).
     */
    public const VITIS_STATUS_IDS = [
        '3' => self::DELIVERED, // Livré
        '31' => self::RETURNED, // Refusé
        '5' => self::CANCELLED, // Annulé
    ];

    public static function normalize(string $provider, ?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $clean = trim($raw);

        if ($clean === '') {
            return null;
        }

        // Already a unified status (e.g. Sendit's normalized value).
        if (in_array($clean, self::UNIFIED_STATUSES, true)) {
            return $clean;
        }

        // Sendit raw codes (in case a raw value ever reaches us).
        $upper = mb_strtoupper($clean);

        if ($provider === 'sendit' && isset(SenditProvider::STATUS_MAP[$upper])) {
            return SenditProvider::STATUS_MAP[$upper];
        }

        // Vitis numeric status ids.
        if ($provider === 'vitis_express' && isset(self::VITIS_STATUS_IDS[$clean])) {
            return self::VITIS_STATUS_IDS[$clean];
        }

        // Case- and accent-insensitive keyword matching for French
        // labels (Vitis) and English free text (Speedaf).
        $folded = self::fold($clean);

        // Delivered first, but "out for delivery" is not delivered.
        if (str_contains($folded, 'out for delivery') || str_contains($folded, 'out-for-delivery')) {
            return self::OUT_FOR_DELIVERY;
        }

        if (str_contains($folded, 'delivering')) {
            return self::OUT_FOR_DELIVERY;
        }

        if (
            str_contains($folded, 'delivered')
            || str_contains($folded, 'livre')
            || $folded === 'livre'
        ) {
            return self::DELIVERED;
        }

        if (
            str_contains($folded, 'returned')
            || str_contains($folded, 'return to sender')
            || str_contains($folded, 'refuse')
            || str_contains($folded, 'retour')
        ) {
            return self::RETURNED;
        }

        if (
            str_contains($folded, 'cancel')
            || str_contains($folded, 'annule')
            || str_contains($folded, 'rejected')
        ) {
            return self::CANCELLED;
        }

        if (
            str_contains($folded, 'en cours de livraison')
            || str_contains($folded, 'en livraison')
            || str_contains($folded, 'expedie')
            || str_contains($folded, 'expedition')
        ) {
            return self::OUT_FOR_DELIVERY;
        }

        if (
            str_contains($folded, 'in transit')
            || str_contains($folded, 'picked up')
            || str_contains($folded, 'ramasse')
            || str_contains($folded, 'warehouse')
            || str_contains($folded, 'departed')
            || str_contains($folded, 'arrived at')
            || str_contains($folded, 'en cours')
        ) {
            return self::IN_TRANSIT;
        }

        if (
            str_contains($folded, 'pending')
            || str_contains($folded, 'created')
            || str_contains($folded, 'cree')
            || str_contains($folded, 'en preparation')
            || str_contains($folded, 'en attente')
            || str_contains($folded, 'to prepare')
            || str_contains($folded, 'topickup')
        ) {
            return self::PENDING;
        }

        // Speedaf free text with no recognized keyword: the parcel
        // has tracking events, so it is moving. Any other provider's
        // unknown text is left unmapped instead of guessed.
        if ($provider === 'speedaf_express') {
            return self::IN_TRANSIT;
        }

        return null;
    }

    /**
     * Lowercase a status and strip French accents so 'Expédié',
     * 'expedie' and 'EXPÉDIÉ' all match.
     */
    protected static function fold(string $value): string
    {
        $lower = mb_strtolower($value);

        $from = ['à', 'â', 'ä', 'é', 'è', 'ê', 'ë', 'î', 'ï', 'ô', 'ö', 'ù', 'û', 'ü', 'ç'];
        $to = ['a', 'a', 'a', 'e', 'e', 'e', 'e', 'i', 'i', 'o', 'o', 'u', 'u', 'u', 'c'];

        return str_replace($from, $to, $lower);
    }
}
