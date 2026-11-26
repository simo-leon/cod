<?php

namespace App\Services\StoreFeatures;

use Webkul\Product\Contracts\Product as ProductContract;

interface StoreFeatureServiceInterface
{
    /**
     * Are product reviews enabled at all (admin switch)?
     */
    public function isReviewsEnabled(): bool;

    /**
     * Must a review be approved in the admin before it shows on the
     * storefront (default true — moderation on)?
     */
    public function reviewsRequireApproval(): bool;

    /**
     * May guests (not logged in) write reviews?
     */
    public function reviewsAllowGuests(): bool;

    /**
     * Should the reviews section show on this product's page?
     * Global switch AND the per-product toggle.
     */
    public function reviewsVisible(ProductContract $product): bool;

    /**
     * May this product accept reviews? A product without a setting
     * row may; Simo switches products off from the admin product
     * edit page.
     */
    public function allowReviews(int $productId): bool;

    /**
     * Set the per-product reviews flag (admin product edit page).
     */
    public function setAllowReviews(int $productId, bool $allow): void;

    /**
     * Is order tracking by phone number enabled (admin switch)?
     */
    public function isTrackingEnabled(): bool;

    /**
     * May this product show in order tracking? A product without a
     * setting row shows; Simo hides products from the admin product
     * edit page.
     */
    public function showInTracking(int $productId): bool;

    /**
     * Set the per-product tracking flag (admin product edit page).
     */
    public function setShowInTracking(int $productId, bool $show): void;

    /**
     * Is this a verified purchase — did the phone, email or customer
     * account buy this product in a non-cancelled order?
     */
    public function isVerifiedPurchase(int $productId, ?string $phone, ?string $email, ?int $customerId): bool;

    /**
     * Orders placed with this phone number, newest first, as plain
     * arrays ready for the tracking page (items already filtered by
     * the per-product tracking toggle).
     */
    public function findOrdersByPhone(string $phone): array;

    /**
     * Timeline step (0 = placed, 1 = confirmed, 2 = out for
     * delivery, 3 = delivered) for an order status, or -1 for a
     * terminal status (canceled / returned / fraud).
     */
    public function statusStep(string $status): int;

    /**
     * Translation key for the customer-facing label of an order status.
     */
    public function statusLabelKey(string $status): string;
}
