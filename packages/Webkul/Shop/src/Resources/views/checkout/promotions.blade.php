{{--
    Cart promotions (free delivery + tiered free gifts), fouprix-style.
    Bound to cart.promotions from the cart API (CartResource). Shared
    by the cart page and the checkout summary. The delivery timeline
    lives in its own partial and is not touched here.
--}}
<div
    v-if="cart.promotions && cart.promotions.enabled"
    class="grid gap-4"
>
    <!-- Free delivery progress -->
    <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-sm font-semibold text-zinc-800">
                @lang('shop::promotions.free_delivery_title')
            </p>

            <p
                v-if="cart.promotions.free_delivery.unlocked"
                class="text-sm font-semibold text-green-600"
            >
                @lang('shop::promotions.free_delivery_unlocked')
            </p>

            <p
                v-else
                class="text-sm text-zinc-600"
            >
                @{{ "@lang('shop::promotions.free_delivery_remaining')".replace(':amount', cart.promotions.free_delivery.formatted_remaining) }}
            </p>
        </div>

        <div class="mt-2.5 h-2.5 w-full overflow-hidden rounded-full bg-zinc-200">
            <div
                class="h-full rounded-full transition-all duration-300"
                :class="cart.promotions.free_delivery.unlocked ? 'bg-green-600' : 'bg-amber-500'"
                :style="{ width: cart.promotions.free_delivery.progress + '%' }"
            ></div>
        </div>
    </div>

    <!-- Gifts already chosen (one per unlocked tier) -->
    <div
        v-for="gifted in cart.promotions.gifted_tiers"
        :key="'gifted-' + gifted.tier"
        class="flex items-center gap-3 rounded-xl border border-green-200 bg-green-50 p-4 shadow-sm"
    >
        <img
            v-if="gifted.gift.image"
            :src="gifted.gift.image"
            :alt="gifted.gift.name"
            class="h-12 w-12 rounded-lg object-cover"
            loading="lazy"
        >

        <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold text-green-700">
                @lang('shop::promotions.gift_unlocked')
            </p>

            <p class="truncate text-sm text-zinc-700">
                @{{ gifted.gift.name }}
            </p>

            <p class="text-xs font-semibold text-green-700">
                @lang('shop::promotions.gift_free')
            </p>
        </div>

        <span
            class="cursor-pointer text-sm text-blue-700"
            role="button"
            tabindex="0"
            @click="removeItem(gifted.gift.item_id)"
        >
            @lang('shop::app.checkout.cart.index.remove')
        </span>
    </div>

    <!-- Coupon of the unlocked coupon tier (automatic discount,
         no picker): green confirmation line with the amount. -->
    <div
        v-if="cart.promotions.coupon && cart.promotions.coupon.applied"
        class="rounded-xl border border-green-200 bg-green-50 p-4 shadow-sm"
    >
        <p class="text-sm font-semibold text-green-700">
            @{{ "@lang('shop::promotions.coupon_applied')".replace(':amount', cart.promotions.coupon.formatted_amount) }}
        </p>
    </div>

    <!-- Gift picker for the current unlocked tier -->
    <div
        v-if="cart.promotions.current_tier"
        class="rounded-xl border border-amber-300 bg-amber-50 p-4 shadow-sm"
    >
        <p class="text-sm font-semibold text-zinc-800">
            @lang('shop::promotions.gift_choose')
        </p>

        <p class="mt-0.5 text-xs text-zinc-600">
            <span v-if="cart.promotions.current_tier.gift_source === 'range'">
                @{{ "@lang('shop::promotions.gift_price_range')".replace(':min', cart.promotions.current_tier.formatted_min_price).replace(':max', cart.promotions.current_tier.formatted_max_price) }}
            </span>

            <span v-else>
                @{{ "@lang('shop::promotions.gift_max_price')".replace(':amount', cart.promotions.current_tier.formatted_max_price) }}
            </span>
        </p>

        <div
            v-if="cart.promotions.eligible_gifts.length"
            class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3"
        >
            <div
                v-for="gift in cart.promotions.eligible_gifts"
                :key="gift.id"
                class="grid content-between gap-2 rounded-lg border border-zinc-200 bg-white p-2.5"
            >
                <a :href="gift.url">
                    <img
                        v-if="gift.image"
                        :src="gift.image"
                        :alt="gift.name"
                        class="h-20 w-full rounded-lg object-cover"
                        loading="lazy"
                    >
                </a>

                <p class="line-clamp-2 min-h-[2.5rem] text-xs font-medium text-zinc-800">
                    @{{ gift.name }}
                </p>

                <p class="text-xs">
                    <s class="text-zinc-400">@{{ gift.formatted_price }}</s>

                    <span class="ml-1 rounded bg-green-600 px-1.5 py-0.5 text-[10px] font-bold text-white">
                        @lang('shop::promotions.gift_free')
                    </span>
                </p>

                <button
                    type="button"
                    class="primary-button w-full rounded-lg px-3 py-2 text-xs"
                    @click="addGift(gift.id)"
                >
                    @lang('shop::promotions.gift_add_and_win')
                </button>
            </div>
        </div>

        <p
            v-else
            class="mt-2 text-sm text-zinc-600"
        >
            @lang('shop::promotions.gift_empty')
        </p>
    </div>

    <!-- Next gift tier progress (or next coupon tier: same bar,
         coupon wording when the tier's reward is a coupon) -->
    <div
        v-if="cart.promotions.next_tier"
        class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm"
    >
        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-sm font-semibold text-zinc-800">
                @{{ cart.promotions.next_tier.reward_type === 'coupon' ? "@lang('shop::promotions.coupon_title')" : "@lang('shop::promotions.gift_title')" }}
            </p>

            <p class="text-sm text-zinc-600">
                @{{ cart.promotions.next_tier.reward_type === 'coupon' ? "@lang('shop::promotions.coupon_remaining')".replace(':amount', cart.promotions.next_tier.formatted_remaining).replace(':coupon', cart.promotions.next_tier.formatted_coupon_amount) : "@lang('shop::promotions.gift_remaining')".replace(':amount', cart.promotions.next_tier.formatted_remaining) }}
            </p>
        </div>

        <div class="mt-2.5 h-2.5 w-full overflow-hidden rounded-full bg-zinc-200">
            <div
                class="h-full rounded-full transition-all duration-300"
                :class="cart.promotions.next_tier.progress >= 100 ? 'bg-green-600' : 'bg-amber-500'"
                :style="{ width: cart.promotions.next_tier.progress + '%' }"
            ></div>
        </div>

        <p class="mt-1.5 text-xs text-zinc-500">
            @{{ cart.promotions.next_tier.reward_type === 'coupon' ? "@lang('shop::promotions.coupon_next')".replace(':amount', cart.promotions.next_tier.formatted_threshold) : "@lang('shop::promotions.next_tier')".replace(':amount', cart.promotions.next_tier.formatted_threshold) }}
        </p>
    </div>
</div>

{{--
    Buy-2-get-3rd-half: progress toward the next discounted item, or
    the applied discount. Bound to cart.buy2 from the cart API
    (CartResource). Its own enabled flag: it shows even when the
    free-delivery/gift module above is disabled.
--}}
<div
    v-if="cart.buy2 && cart.buy2.enabled"
    class="mt-4 rounded-xl border border-amber-300 bg-amber-50 p-4 shadow-sm"
>
    <div class="flex flex-wrap items-center justify-between gap-2">
        <p class="text-sm font-semibold text-zinc-800">
            @lang('shop::promotions.buy2_title')
        </p>

        <p
            v-if="cart.buy2.applied"
            class="text-sm font-semibold text-green-600"
        >
            @{{ "@lang('shop::promotions.buy2_applied')".replace(':amount', cart.buy2.formatted_discount_amount) }}
        </p>

        <p
            v-else
            class="text-sm text-zinc-600"
        >
            @{{ "@lang('shop::promotions.buy2_remaining')".replace(':count', cart.buy2.units_needed) }}
        </p>
    </div>

    <div
        v-if="! cart.buy2.applied"
        class="mt-2.5 h-2.5 w-full overflow-hidden rounded-full bg-zinc-200"
    >
        <div
            class="h-full rounded-full bg-amber-500 transition-all duration-300"
            :style="{ width: cart.buy2.progress + '%' }"
        ></div>
    </div>

    <p
        v-if="cart.buy2.applied"
        class="mt-1.5 text-xs text-zinc-500"
    >
        @lang('shop::promotions.buy2_unlocked')
    </p>
</div>
