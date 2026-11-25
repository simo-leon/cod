{{--
    "Frequently bought together" on the product page.
    Server-rendered: the main product + complementary products from
    the same categories, each with a checkbox (checked by default).
    One "Add selected together" button adds them all to the cart in
    one action; the complementary products get the bundle discount
    (admin-configurable percent) while the main product stays in the
    cart. The totals update live as checkboxes toggle.
--}}
@php
    $fbtService = app(\App\Services\Promotions\PromotionServiceInterface::class);
    $fbtProducts = $fbtService->fbtProducts($product);
    $fbtPercent = (int) $fbtService->fbtDiscountPercent();
    $fbtMainPrice = (float) $product->getTypeInstance()->getFinalPrice();
    $fbtMainImage = product_image()->getProductBaseImage($product);
    $fbtTotal = $fbtMainPrice + collect($fbtProducts)->sum('price');
    $fbtSave = collect($fbtProducts)->sum('price') * $fbtPercent / 100;
@endphp

@if ($fbtProducts)
    <div
        class="container mt-12 px-[60px] max-1180:px-0 max-lg:px-5 max-sm:px-4"
        id="fbt-section"
        data-main-price="{{ $fbtMainPrice }}"
        data-discount-percent="{{ $fbtPercent }}"
    >
        <h2 class="text-2xl font-medium max-sm:text-xl">
            @lang('shop::promotions.fbt_title')
        </h2>

        <p class="mt-1 text-sm text-zinc-500">
            @lang('shop::promotions.fbt_subtitle', ['percent' => $fbtPercent])
        </p>

        <div class="mt-5 flex flex-wrap items-stretch gap-3">
            <!-- This product -->
            <div class="w-[210px] rounded-xl border border-zinc-200 bg-white p-3 shadow-sm max-sm:w-[160px]">
                <a href="{{ route('shop.product_or_category.index', $product->url_key) }}">
                    <img
                        src="{{ $fbtMainImage['medium_image_url'] ?? ($fbtMainImage['original_image_url'] ?? '') }}"
                        alt="{{ $product->name }}"
                        class="h-32 w-full rounded-lg object-cover"
                        loading="lazy"
                    >
                </a>

                <p class="mt-2 line-clamp-2 min-h-[2.5rem] text-sm font-medium text-zinc-800">
                    {{ $product->name }}
                </p>

                <p class="mt-1 text-sm font-semibold text-zinc-900">
                    {{ core()->formatPrice($fbtMainPrice) }}
                </p>

                <p class="mt-1 text-xs font-semibold text-zinc-500">
                    @lang('shop::promotions.fbt_this_product')
                </p>
            </div>

            @foreach ($fbtProducts as $complement)
                <div class="flex items-center text-2xl font-bold text-zinc-300">+</div>

                <label class="w-[210px] cursor-pointer rounded-xl border border-zinc-200 bg-white p-3 shadow-sm transition hover:border-zinc-400 max-sm:w-[160px]">
                    <input
                        type="checkbox"
                        class="fbt-checkbox h-4 w-4 accent-yellow-500"
                        value="{{ $complement['id'] }}"
                        data-price="{{ $complement['price'] }}"
                        checked
                    >

                    <a href="{{ $complement['url'] }}">
                        <img
                            @if ($complement['image'])
                                src="{{ $complement['image'] }}"
                            @endif
                            alt="{{ $complement['name'] }}"
                            class="mt-2 h-32 w-full rounded-lg object-cover"
                            loading="lazy"
                        >
                    </a>

                    <p class="mt-2 line-clamp-2 min-h-[2.5rem] text-sm font-medium text-zinc-800">
                        {{ $complement['name'] }}
                    </p>

                    <p class="mt-1 text-sm font-semibold text-zinc-900">
                        {{ $complement['formatted_price'] }}
                    </p>
                </label>
            @endforeach
        </div>

        <div class="mt-5 flex flex-wrap items-center gap-x-6 gap-y-3">
            <p class="text-base text-zinc-700">
                @lang('shop::promotions.fbt_total')
                <span id="fbt-total" class="font-bold text-zinc-900">{{ core()->formatPrice($fbtTotal) }}</span>
            </p>

            <p class="text-base text-green-700">
                @lang('shop::promotions.fbt_you_save')
                <span id="fbt-save" class="font-bold">{{ core()->formatPrice($fbtSave) }}</span>
            </p>

            <button
                type="button"
                id="fbt-add-btn"
                class="primary-button rounded-xl px-6 py-3 max-sm:rounded-lg max-sm:px-4 max-sm:py-2"
            >
                @lang('shop::promotions.fbt_add_together')
            </button>

            <p id="fbt-error" class="hidden text-sm font-medium text-red-600"></p>
        </div>

        <script>
            /*
             * NOTE: this script runs at parse time, but the product
             * page is a Vue app: when Vue mounts it re-creates the
             * DOM nodes (and drops this <script> element), so any
             * listener attached directly to the checkboxes or the
             * button would be lost. Everything below is therefore
             * delegated to `document` (which Vue never replaces)
             * and re-queries the section elements on every event.
             */
            (function () {
                function section() {
                    return document.getElementById('fbt-section');
                }

                function fmt(amount) {
                    var formatter = new Intl.NumberFormat(document.documentElement.lang || 'en', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2,
                    });

                    return formatter.format(amount) + 'DH';
                }

                function checkedBoxes() {
                    var s = section();

                    return s ? Array.from(s.querySelectorAll('.fbt-checkbox:checked')) : [];
                }

                function refreshTotals() {
                    var s = section();

                    if (! s) {
                        return;
                    }

                    var mainPrice = parseFloat(s.dataset.mainPrice || '0');
                    var percent = parseFloat(s.dataset.discountPercent || '0');
                    var selected = checkedBoxes().reduce(function (sum, box) {
                        return sum + parseFloat(box.dataset.price || '0');
                    }, 0);
                    var totalEl = document.getElementById('fbt-total');
                    var saveEl = document.getElementById('fbt-save');
                    var btn = document.getElementById('fbt-add-btn');

                    if (totalEl) {
                        totalEl.textContent = fmt(mainPrice + selected);
                    }

                    if (saveEl) {
                        saveEl.textContent = fmt(selected * percent / 100);
                    }

                    if (btn) {
                        btn.disabled = checkedBoxes().length === 0;
                    }
                }

                document.addEventListener('change', function (event) {
                    if (event.target && event.target.classList && event.target.classList.contains('fbt-checkbox')) {
                        refreshTotals();
                    }
                });

                document.addEventListener('click', function (event) {
                    var btn = event.target && event.target.closest
                        ? event.target.closest('#fbt-add-btn')
                        : null;

                    if (! btn) {
                        return;
                    }

                    var ids = checkedBoxes().map(function (box) {
                        return parseInt(box.value, 10);
                    });

                    if (! ids.length) {
                        return;
                    }

                    var errorEl = document.getElementById('fbt-error');

                    if (errorEl) {
                        errorEl.classList.add('hidden');
                    }

                    btn.disabled = true;

                    var token = (document.cookie.split('; ').find(function (c) {
                        return c.indexOf('XSRF-TOKEN=') === 0;
                    }) || '').split('=')[1];

                    fetch('{{ route('shop.api.checkout.cart.fbt.store') }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-XSRF-TOKEN': token ? decodeURIComponent(token) : '',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify({
                            main_product_id: {{ $product->id }},
                            product_ids: ids,
                        }),
                    })
                        .then(function (response) {
                            return response.json().then(function (data) {
                                return { ok: response.ok, data: data };
                            });
                        })
                        .then(function (result) {
                            if (! result.ok) {
                                if (errorEl) {
                                    errorEl.textContent = result.data.message || 'Error';
                                    errorEl.classList.remove('hidden');
                                }

                                btn.disabled = false;

                                return;
                            }

                            window.location.href = '{{ route('shop.checkout.cart.index') }}';
                        })
                        .catch(function () {
                            if (errorEl) {
                                errorEl.textContent = 'Error';
                                errorEl.classList.remove('hidden');
                            }

                            btn.disabled = false;
                        });
                });

                refreshTotals();
            })();
        </script>
    </div>
@endif
