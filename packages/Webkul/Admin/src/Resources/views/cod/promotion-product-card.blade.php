{{--
    Cart promotions card, rendered after the admin product edit form
    (view_render_event bagisto.admin.catalog.product.edit.after).
    It is a separate form: it must NOT sit inside the product form.

    Controls whether this product may be offered as a free gift,
    show the low-stock badge, count towards the buy-2-get-3rd-half
    offer, show the "Frequently bought together" section on its
    page, be suggested as a complementary product, appear in the
    daily deals, accept customer reviews, show in order tracking,
    and be listed in the abandoned-cart WhatsApp reminders. A
    product without a setting row participates in all of these
    (whenever the other conditions hold), except the daily deals,
    which are opt-in; unchecking here excludes it from that offer.
--}}
<div class="mt-4 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
    <p class="text-base font-bold text-gray-800 dark:text-white">
        @lang('admin::app.cod.promotions.product-card.title')
    </p>

    @if ($canEditPromotions)
        <form
            method="POST"
            action="{{ route('admin.promotions.product.update', $product) }}"
            class="mt-3 grid gap-3"
        >
            @csrf

            <input type="hidden" name="offers_enabled" value="0">
            <input type="hidden" name="eligible_as_gift" value="0">
            <input type="hidden" name="show_low_stock_badge" value="0">
            <input type="hidden" name="eligible_buy2_half" value="0">
            <input type="hidden" name="show_fbt" value="0">
            <input type="hidden" name="fbt_complementary" value="0">
            <input type="hidden" name="in_daily_deal" value="0">
            <input type="hidden" name="allow_reviews" value="0">
            <input type="hidden" name="show_in_tracking" value="0">
            <input type="hidden" name="include_in_abandoned_cart_reminder" value="0">

            {{-- Master toggle: one switch for all of this product's
                 offers. It only gates them — the individual toggles
                 and the buy-2 terms keep their values, so switching
                 it back on restores everything as it was. --}}
            <label class="flex cursor-pointer items-start gap-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-gray-700 dark:border-amber-700 dark:bg-amber-900/30 dark:text-gray-300">
                <input
                    type="checkbox"
                    name="offers_enabled"
                    id="offers-master"
                    value="1"
                    @checked($offersEnabled)
                    onchange="document.getElementById('offer-controls').classList.toggle('opacity-40', ! this.checked); document.getElementById('offer-controls').classList.toggle('pointer-events-none', ! this.checked);"
                    class="mt-0.5 h-4 w-4 accent-yellow-500"
                >

                <span>
                    <span class="font-semibold text-gray-800 dark:text-white">
                        @lang('admin::app.cod.promotions.product-card.master')
                    </span>

                    <span class="block text-xs leading-relaxed text-gray-500 dark:text-gray-400">
                        @lang('admin::app.cod.promotions.product-card.master-info')
                    </span>
                </span>
            </label>

            {{-- Individual offer controls: dimmed (not disabled — the
                 values must still submit unchanged) while the master
                 toggle is off. --}}
            <div id="offer-controls" class="grid gap-3 @if (! $offersEnabled) opacity-40 pointer-events-none @endif">

            <label class="flex cursor-pointer items-start gap-2 text-sm text-gray-700 dark:text-gray-300">
                <input
                    type="checkbox"
                    name="eligible_as_gift"
                    value="1"
                    @checked($eligible)
                    class="mt-0.5 h-4 w-4 accent-yellow-500"
                >

                <span>
                    <span class="font-semibold text-gray-800 dark:text-white">
                        @lang('admin::app.cod.promotions.product-card.eligible')
                    </span>

                    <span class="block text-xs leading-relaxed text-gray-500 dark:text-gray-400">
                        @lang('admin::app.cod.promotions.product-card.eligible-info')
                    </span>
                </span>
            </label>

            <label class="flex cursor-pointer items-start gap-2 text-sm text-gray-700 dark:text-gray-300">
                <input
                    type="checkbox"
                    name="show_low_stock_badge"
                    value="1"
                    @checked($showLowStockBadge)
                    class="mt-0.5 h-4 w-4 accent-yellow-500"
                >

                <span>
                    <span class="font-semibold text-gray-800 dark:text-white">
                        @lang('admin::app.cod.promotions.product-card.low-stock')
                    </span>

                    <span class="block text-xs leading-relaxed text-gray-500 dark:text-gray-400">
                        @lang('admin::app.cod.promotions.product-card.low-stock-info')
                    </span>
                </span>
            </label>

            <label class="flex cursor-pointer items-start gap-2 text-sm text-gray-700 dark:text-gray-300">
                <input
                    type="checkbox"
                    name="eligible_buy2_half"
                    value="1"
                    @checked($eligibleBuy2)
                    class="mt-0.5 h-4 w-4 accent-yellow-500"
                >

                <span>
                    <span class="font-semibold text-gray-800 dark:text-white">
                        @lang('admin::app.cod.promotions.product-card.buy2')
                    </span>

                    <span class="block text-xs leading-relaxed text-gray-500 dark:text-gray-400">
                        @lang('admin::app.cod.promotions.product-card.buy2-info')
                    </span>
                </span>
            </label>

            {{-- Per-product buy-2 terms: empty = use the global
                 settings (buy N = global group size - 1, percent =
                 global percent). --}}
            <div class="grid grid-cols-2 gap-3">
                <label class="block text-sm text-gray-700 dark:text-gray-300">
                    <span class="font-semibold text-gray-800 dark:text-white">
                        @lang('admin::app.cod.promotions.product-card.buy2-min-qty')
                    </span>

                    <input
                        type="number"
                        name="buy2_min_qty"
                        value="{{ $buy2MinQty }}"
                        min="1"
                        max="99"
                        placeholder="{{ $buy2GlobalMinQty }}"
                        class="mt-1 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                    >

                    <span class="mt-1 block text-xs leading-relaxed text-gray-500 dark:text-gray-400">
                        @lang('admin::app.cod.promotions.product-card.buy2-min-qty-info', ['default' => $buy2GlobalMinQty])
                    </span>
                </label>

                <label class="block text-sm text-gray-700 dark:text-gray-300">
                    <span class="font-semibold text-gray-800 dark:text-white">
                        @lang('admin::app.cod.promotions.product-card.buy2-percent')
                    </span>

                    <input
                        type="number"
                        name="buy2_discount_percent"
                        value="{{ $buy2DiscountPercent }}"
                        min="0"
                        max="100"
                        step="1"
                        placeholder="{{ $buy2GlobalPercent }}"
                        class="mt-1 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                    >

                    <span class="mt-1 block text-xs leading-relaxed text-gray-500 dark:text-gray-400">
                        @lang('admin::app.cod.promotions.product-card.buy2-percent-info', ['default' => $buy2GlobalPercent])
                    </span>
                </label>
            </div>

            <label class="flex cursor-pointer items-start gap-2 text-sm text-gray-700 dark:text-gray-300">
                <input
                    type="checkbox"
                    name="show_fbt"
                    value="1"
                    @checked($showFbt)
                    class="mt-0.5 h-4 w-4 accent-yellow-500"
                >

                <span>
                    <span class="font-semibold text-gray-800 dark:text-white">
                        @lang('admin::app.cod.promotions.product-card.fbt')
                    </span>

                    <span class="block text-xs leading-relaxed text-gray-500 dark:text-gray-400">
                        @lang('admin::app.cod.promotions.product-card.fbt-info')
                    </span>
                </span>
            </label>

            <label class="flex cursor-pointer items-start gap-2 text-sm text-gray-700 dark:text-gray-300">
                <input
                    type="checkbox"
                    name="fbt_complementary"
                    value="1"
                    @checked($fbtComplementary)
                    class="mt-0.5 h-4 w-4 accent-yellow-500"
                >

                <span>
                    <span class="font-semibold text-gray-800 dark:text-white">
                        @lang('admin::app.cod.promotions.product-card.fbt-complementary')
                    </span>

                    <span class="block text-xs leading-relaxed text-gray-500 dark:text-gray-400">
                        @lang('admin::app.cod.promotions.product-card.fbt-complementary-info')
                    </span>
                </span>
            </label>

            <label class="flex cursor-pointer items-start gap-2 text-sm text-gray-700 dark:text-gray-300">
                <input
                    type="checkbox"
                    name="in_daily_deal"
                    value="1"
                    @checked($inDailyDeal)
                    class="mt-0.5 h-4 w-4 accent-yellow-500"
                >

                <span>
                    <span class="font-semibold text-gray-800 dark:text-white">
                        @lang('admin::app.cod.promotions.product-card.daily-deal')
                    </span>

                    <span class="block text-xs leading-relaxed text-gray-500 dark:text-gray-400">
                        @lang('admin::app.cod.promotions.product-card.daily-deal-info')
                    </span>
                </span>
            </label>

            <label class="flex cursor-pointer items-start gap-2 text-sm text-gray-700 dark:text-gray-300">
                <input
                    type="checkbox"
                    name="allow_reviews"
                    value="1"
                    @checked($allowReviews)
                    class="mt-0.5 h-4 w-4 accent-yellow-500"
                >

                <span>
                    <span class="font-semibold text-gray-800 dark:text-white">
                        @lang('admin::app.cod.promotions.product-card.reviews')
                    </span>

                    <span class="block text-xs leading-relaxed text-gray-500 dark:text-gray-400">
                        @lang('admin::app.cod.promotions.product-card.reviews-info')
                    </span>
                </span>
            </label>

            <label class="flex cursor-pointer items-start gap-2 text-sm text-gray-700 dark:text-gray-300">
                <input
                    type="checkbox"
                    name="show_in_tracking"
                    value="1"
                    @checked($showInTracking)
                    class="mt-0.5 h-4 w-4 accent-yellow-500"
                >

                <span>
                    <span class="font-semibold text-gray-800 dark:text-white">
                        @lang('admin::app.cod.promotions.product-card.tracking')
                    </span>

                    <span class="block text-xs leading-relaxed text-gray-500 dark:text-gray-400">
                        @lang('admin::app.cod.promotions.product-card.tracking-info')
                    </span>
                </span>
            </label>

            <label class="flex cursor-pointer items-start gap-2 text-sm text-gray-700 dark:text-gray-300">
                <input
                    type="checkbox"
                    name="include_in_abandoned_cart_reminder"
                    value="1"
                    @checked($includeInAbandonedCartReminder)
                    class="mt-0.5 h-4 w-4 accent-yellow-500"
                >

                <span>
                    <span class="font-semibold text-gray-800 dark:text-white">
                        @lang('admin::app.cod.promotions.product-card.abandoned-cart')
                    </span>

                    <span class="block text-xs leading-relaxed text-gray-500 dark:text-gray-400">
                        @lang('admin::app.cod.promotions.product-card.abandoned-cart-info')
                    </span>
                </span>
            </label>

            </div>

            <div>
                <button type="submit" class="primary-button">
                    @lang('admin::app.cod.promotions.product-card.save')
                </button>
            </div>
        </form>
    @else
        <div class="mt-3 grid gap-1 text-sm text-gray-700 dark:text-gray-300">
            <p>
                @if ($offersEnabled)
                    @lang('admin::app.cod.promotions.product-card.master-yes')
                @else
                    @lang('admin::app.cod.promotions.product-card.master-no')
                @endif
            </p>

            <p>
                @lang('admin::app.cod.promotions.product-card.buy2-terms', ['count' => $buy2MinQty ?? $buy2GlobalMinQty, 'percent' => $buy2DiscountPercent ?? $buy2GlobalPercent])
            </p>

            <p>
                @if ($eligible)
                    @lang('admin::app.cod.promotions.product-card.eligible-yes')
                @else
                    @lang('admin::app.cod.promotions.product-card.eligible-no')
                @endif
            </p>

            <p>
                @if ($showLowStockBadge)
                    @lang('admin::app.cod.promotions.product-card.low-stock-yes')
                @else
                    @lang('admin::app.cod.promotions.product-card.low-stock-no')
                @endif
            </p>

            <p>
                @if ($eligibleBuy2)
                    @lang('admin::app.cod.promotions.product-card.buy2-yes')
                @else
                    @lang('admin::app.cod.promotions.product-card.buy2-no')
                @endif
            </p>

            <p>
                @if ($showFbt)
                    @lang('admin::app.cod.promotions.product-card.fbt-yes')
                @else
                    @lang('admin::app.cod.promotions.product-card.fbt-no')
                @endif
            </p>

            <p>
                @if ($fbtComplementary)
                    @lang('admin::app.cod.promotions.product-card.fbt-complementary-yes')
                @else
                    @lang('admin::app.cod.promotions.product-card.fbt-complementary-no')
                @endif
            </p>

            <p>
                @if ($inDailyDeal)
                    @lang('admin::app.cod.promotions.product-card.daily-deal-yes')
                @else
                    @lang('admin::app.cod.promotions.product-card.daily-deal-no')
                @endif
            </p>

            <p>
                @if ($allowReviews)
                    @lang('admin::app.cod.promotions.product-card.reviews-yes')
                @else
                    @lang('admin::app.cod.promotions.product-card.reviews-no')
                @endif
            </p>

            <p>
                @if ($showInTracking)
                    @lang('admin::app.cod.promotions.product-card.tracking-yes')
                @else
                    @lang('admin::app.cod.promotions.product-card.tracking-no')
                @endif
            </p>

            <p>
                @if ($includeInAbandonedCartReminder)
                    @lang('admin::app.cod.promotions.product-card.abandoned-cart-yes')
                @else
                    @lang('admin::app.cod.promotions.product-card.abandoned-cart-no')
                @endif
            </p>
        </div>
    @endif
</div>
