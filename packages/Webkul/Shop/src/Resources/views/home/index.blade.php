@php
    $channel = core()->getCurrentChannel();

    /*
        Jemla: default number of products per homepage carousel/grid
        section (Configuration → General → Homepage), used when the
        section itself has no limit saved. Only a value the storefront
        toolbar accepts is used, so the API never silently replaces
        it with 12.
    */
    $homepageDefaultLimit = (int) (core()->getConfigData('general.homepage.settings.carousel_products_limit') ?: 12);

    if (! in_array($homepageDefaultLimit, product_toolbar()->getAvailableLimits()->toArray())) {
        $homepageDefaultLimit = product_toolbar()->getDefaultLimit();
    }
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
    <!-- Page Title -->
    <x-slot:title>
        {{  $channel->home_seo['meta_title'] ?? '' }}
    </x-slot>

    <!-- Daily deals / flash sale (ends at midnight, Africa/Casablanca) -->
    @include('shop::home.daily-deals')

    <!-- Loop over the theme customization -->
    @foreach ($customizations as $customization)
        @php ($data = $customization->options) @endphp

        <!-- Static Content -->
        @switch ($customization->type)
            @case ($customization::IMAGE_CAROUSEL)
                <!-- Image Carousel -->
                <x-shop::carousel
                    :options="$data"
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
                <x-shop::categories.carousel
                    :title="$data['title'] ?? ''"
                    :src="route('shop.api.categories.index', $data['filters'] ?? [])"
                    :navigation-link="route('shop.home.index')"
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
                @endphp

                <x-shop::products.grid
                    :title="$data['title'] ?? ''"
                    :src="route('shop.api.products.index', $productFilters)"
                    :navigation-link="route('shop.search.index', $productFilters)"
                    aria-label="{{ trans('shop::app.home.index.product-carousel') }}"
                />

                @break
        @endswitch
    @endforeach
</x-shop::layouts>
