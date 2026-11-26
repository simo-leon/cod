@php
    $channel = core()->getCurrentChannel();

    /*
        Jemla: default number of products per homepage carousel/grid
        section (Configuration → General → Homepage), used when the
        section itself has no limit saved. The value is used directly
        (clamped 1..48) — it must NOT be checked against the category
        toolbar's per-page list (12, 24, 36, 48), or a limit of 16
        would silently be replaced by 12 in the API.
    */
    $homepageDefaultLimit = min(48, max(1, (int) (core()->getConfigData('general.homepage.settings.carousel_products_limit') ?: 12)));

    /*
        Jemla: the daily-deals section used to be hard-coded at the
        top of the homepage. It still is — unless Simo created a
        deals-countdown topic, in which case that topic renders the
        section at its own sort position (with its own duration and
        limit) and the hard-coded one stays out of the way, so the
        countdown never appears twice.
    */
    $hasDealsTopic = $customizations->contains(fn ($customization) => $customization->type === 'deals_countdown');
@endphp

<!-- SEO Meta Content -->
@push ('meta')
    <meta
        name="title"
        content="{{ $channel->home_seo['meta_title'] ?? '' }}"
    />

    <meta
        name="description"
        content="{{ $channel->home_seo['meta_description'] ?? '' }}"
    />

    <meta
        name="keywords"
        content="{{ $channel->home_seo['meta_keywords'] ?? '' }}"
    />
@endPush

@push('scripts')
    @if(! empty($categories))
        <script>
            localStorage.setItem('categories', JSON.stringify(@json($categories)));
        </script>
    @endif
@endpush

<x-shop::layouts>
    {{--
        Jemla (round 2C, issue #8): the homepage's only <h1> is the
        store name, visually hidden — the promo slogan used to be the
        <h1> (home-offer banner, now a <p>). No visual change.
    --}}
    <h1 class="sr-only">Jemla En Ligne</h1>

    <!-- Page Title -->
    <x-slot:title>
        {{  $channel->home_seo['meta_title'] ?? '' }}
    </x-slot>

    <!-- Daily deals / flash sale (ends at midnight, Africa/Casablanca) -->
    @if (! $hasDealsTopic)
        @include('shop::home.daily-deals')
    @endif

    <!-- Loop over the theme customization -->
    @foreach ($customizations as $customization)
        @php ($data = $customization->options) @endphp

        <!-- Static Content -->
        @switch ($customization->type)
            @case ($customization::IMAGE_CAROUSEL)
                <!-- Image Carousel -->
                @php
                    /*
                        Jemla: per-slider autoplay + slide duration.
                        Empty in the topic = the global Homepage
                        setting (the carousel component falls back
                        to it when it receives null).
                    */
                    $sliderAutoplay = isset($data['display']['autoplay']) && $data['display']['autoplay'] !== ''
                        ? (bool) (int) $data['display']['autoplay']
                        : null;

                    $sliderInterval = isset($data['display']['interval']) && $data['display']['interval'] !== ''
                        ? (int) $data['display']['interval']
                        : null;
                @endphp

                <x-shop::carousel
                    :options="$data"
                    :autoplay="$sliderAutoplay"
                    :interval="$sliderInterval"
                    aria-label="{{ trans('shop::app.home.index.image-carousel') }}"
                />

                @break
            @case ($customization::STATIC_CONTENT)
                <!-- Push Style -->
                @if (! empty($data['css']))
                    @push ('styles')
                        <style>
                            {!! $data['css'] !!}
                        </style>
                    @endpush
                @endif

                <!-- Render HTML -->
                @if (! empty($data['html']))
                    {!! $data['html'] !!}
                @endif

                @break
            @case ($customization::CATEGORY_CAROUSEL)
                <!-- Categories carousel -->
                @php
                    /*
                        Jemla: an empty limit in the topic means "no
                        limit set" — drop it so the API uses its own
                        default instead of receiving limit= (which
                        paginate() would not treat as "default").
                    */
                    $categoryFilters = $data['filters'] ?? [];

                    if (empty($categoryFilters['limit'])) {
                        unset($categoryFilters['limit']);
                    }
                @endphp

                <x-shop::categories.carousel
                    :title="$data['title'] ?? ''"
                    :src="route('shop.api.categories.index', $categoryFilters)"
                    :navigation-link="route('shop.categories.index')"
                    aria-label="{{ trans('shop::app.home.index.categories-carousel') }}"
                />

                @break
            @case ($customization::PRODUCT_CAROUSEL)
                <!-- Product Carousel -->
                @php
                    $productFilters = $data['filters'] ?? [];

                    if (empty($productFilters['limit'])) {
                        $productFilters['limit'] = $homepageDefaultLimit;
                    }
                @endphp

                <x-shop::products.carousel
                    :title="$data['title'] ?? ''"
                    :src="route('shop.api.products.index', $productFilters)"
                    :navigation-link="route('shop.search.index', $productFilters)"
                    aria-label="{{ trans('shop::app.home.index.product-carousel') }}"
                />

                @break

            @case ($customization::PRODUCT_GRID)
                <!-- Product Grid with Infinite Scroll -->
                @php
                    $productFilters = $data['filters'] ?? [];

                    if (empty($productFilters['limit'])) {
                        $productFilters['limit'] = $homepageDefaultLimit;
                    }

                    /*
                        Jemla: per-grid columns. Empty in the topic =
                        the global Homepage grid-columns setting (the
                        grid component falls back to it on null).
                    */
                    $gridColumnsDesktop = isset($data['display']['columns_desktop']) && $data['display']['columns_desktop'] !== ''
                        ? (int) $data['display']['columns_desktop']
                        : null;

                    $gridColumnsMobile = isset($data['display']['columns_mobile']) && $data['display']['columns_mobile'] !== ''
                        ? (int) $data['display']['columns_mobile']
                        : null;
                @endphp

                <x-shop::products.grid
                    :title="$data['title'] ?? ''"
                    :src="route('shop.api.products.index', $productFilters)"
                    :navigation-link="route('shop.search.index', $productFilters)"
                    :columns-desktop="$gridColumnsDesktop"
                    :columns-mobile="$gridColumnsMobile"
                    aria-label="{{ trans('shop::app.home.index.product-carousel') }}"
                />

                @break

            @case ($customization::DEALS_COUNTDOWN)
                <!-- Daily-deals countdown (Jemla) -->
                @include('shop::home.daily-deals', [
                    'dealsTitleOverride' => $data['title'] ?? null,
                    'dealsDurationHours' => $data['duration_hours'] ?? null,
                    'dealsLimit' => $data['limit'] ?? null,
                ])

                @break

            @case ($customization::NOTIFICATION_BAR)
                <!-- Notification bar (Jemla) -->
                @include('shop::home.notification-bar', [
                    'message' => $data['message'] ?? '',
                    'link' => $data['link'] ?? '',
                    'linkTitle' => $data['link_title'] ?? '',
                ])

                @break

            @case ($customization::BEST_SELLERS)
                <!-- Best sellers (Jemla: Amazon Best Sellers / AliExpress Top Rankings) -->
                @php
                    $bestSellersLimit = ! empty($data['limit']) ? (int) $data['limit'] : $homepageDefaultLimit;
                @endphp

                <x-shop::products.carousel
                    :title="! empty($data['title']) ? $data['title'] : trans('shop::app.home.index.best-sellers')"
                    :src="route('shop.api.products.best-sellers.index', ['limit' => $bestSellersLimit])"
                    aria-label="{{ trans('shop::app.home.index.best-sellers') }}"
                />

                @break

            @case ($customization::SHOP_BY_CATEGORY)
                <!-- Shop by category (Jemla: Amazon Shop by Category / Shopify featured collections) -->
                @include('shop::home.shop-by-category', [
                    'title' => $data['title'] ?? null,
                    'limit' => $data['limit'] ?? null,
                ])

                @break

            @case ($customization::CUSTOMER_REVIEWS)
                <!-- Customer reviews (Jemla: Shopify social proof) -->
                @include('shop::home.customer-reviews', [
                    'title' => $data['title'] ?? null,
                    'limit' => $data['limit'] ?? null,
                ])

                @break

            @case ($customization::TRUST_BADGES)
                <!-- Trust badges (Jemla: Shopify trust bar / COD trust signals) -->
                @include('shop::home.trust-badges', [
                    'title' => $data['title'] ?? null,
                    'items' => $data['items'] ?? [],
                ])

                @break
        @endswitch
    @endforeach
</x-shop::layouts>
