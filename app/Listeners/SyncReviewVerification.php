<?php

namespace App\Listeners;

use App\Services\StoreFeatures\StoreFeatureServiceInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Webkul\Product\Models\ProductReview;

/**
 * Product reviews (idea 5): the core API controller creates every
 * review as pending and ignores the phone field, so this listener —
 * on `customer.review.create.after` — fills in what core does not:
 *
 * - the reviewer's phone (typed in the form by guests, or the
 *   logged-in customer's account phone), and
 * - whether it is a verified purchase (the phone / email / account
 *   bought this product in a non-cancelled order).
 *
 * When Simo switched moderation OFF in the admin (reviews do not
 * require approval), the review is published straight away instead
 * of waiting in the pending queue. A failure here must never break
 * the review submission itself.
 */
class SyncReviewVerification
{
    public function handle(ProductReview $review): void
    {
        try {
            $features = app(StoreFeatureServiceInterface::class);

            $phone = request()->input('phone');
            $customer = Auth::guard('customer')->user();

            if (! $phone && $customer) {
                $phone = $customer->phone;
            }

            $email = $customer?->email ?? request()->input('email');

            $updates = [
                'phone' => $phone ?: null,
                'is_verified_purchase' => $features->isVerifiedPurchase(
                    (int) $review->product_id,
                    $phone ?: null,
                    $email ?: null,
                    $customer?->id,
                ),
            ];

            if (! $features->reviewsRequireApproval()) {
                $updates['status'] = 'approved';
            }

            $review->update($updates);
        } catch (\Throwable $e) {
            Log::warning('SyncReviewVerification: failed', [
                'review_id' => $review->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
