<?php

namespace App\Services\AbandonedCart;

use Illuminate\Support\Collection;
use Webkul\Checkout\Models\Cart;

/**
 * Abandoned-cart WhatsApp reminders (idea 7).
 *
 * A cart is "abandoned" when it is still active, still has items,
 * was last touched at least `delay_minutes` ago, never became an
 * order, and never got a reminder. The reminder goes to the phone
 * found on the cart (logged-in customer's phone, else the billing
 * then shipping address phone the guest typed at checkout), in the
 * channel's default language, listing only the products Simo did
 * not exclude from reminders. At most one reminder per cart, ever:
 * the send is recorded in `abandoned_cart_reminders` only on
 * success, and a failed send leaves no row, so the next run
 * retries it.
 */
interface AbandonedCartServiceInterface
{
    /** Whether the reminders are switched on in the admin. */
    public function isEnabled(): bool;

    /** Minutes of inactivity before a cart counts as abandoned. */
    public function delayMinutes(): int;

    /** The admin message template for one locale (fr | ar | en). */
    public function messageTemplate(string $locale): string;

    /** Whether this product may be listed in a reminder (default on). */
    public function includeInReminder(int $productId): bool;

    public function setIncludeInReminder(int $productId, bool $include): void;

    /** The cart owner's phone, normalized to bare international digits, or null. */
    public function cartPhone(Cart $cart): ?string;

    /** The language of the reminder for this cart (channel default locale). */
    public function localeForCart(Cart $cart): string;

    /** The cart's items that may be listed in a reminder (gifts and excluded products removed). */
    public function includedItems(Cart $cart): Collection;

    /** Carts that are due for a reminder right now. */
    public function dueCarts(): Collection;

    /** The rendered message text for one cart in one locale. */
    public function renderMessage(Cart $cart, string $locale): string;

    /** The public cart URL the message links back to, in one locale. */
    public function cartUrl(string $locale): string;

    /**
     * Send the reminder for one cart (or skip it with a reason).
     *
     * @return array{status: 'sent'|'skipped'|'failed', message: string}
     */
    public function processCart(Cart $cart): array;
}
