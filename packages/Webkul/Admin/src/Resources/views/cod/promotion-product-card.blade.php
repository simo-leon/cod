{{--
    Cart promotions card, rendered after the admin product edit form
    (view_render_event bagisto.admin.catalog.product.edit.after).
    It is a separate form: it must NOT sit inside the product form.

    Controls whether this product may be offered as a free gift,
    show the low-stock badge, and count towards the buy-2-get-3rd-half
    offer. A product without a setting row participates in all three
    (whenever the other conditions hold); unchecking here excludes
    it from that offer.
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

            <input type="hidden" name="eligible_as_gift" value="0">
            <input type="hidden" name="show_low_stock_badge" value="0">
            <input type="hidden" name="eligible_buy2_half" value="0">

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

            <div>
                <button type="submit" class="primary-button">
                    @lang('admin::app.cod.promotions.product-card.save')
                </button>
            </div>
        </form>
    @else
        <div class="mt-3 grid gap-1 text-sm text-gray-700 dark:text-gray-300">
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
        </div>
    @endif
</div>
