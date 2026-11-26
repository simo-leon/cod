{{--
    Shop by category topic (Jemla: Amazon "Shop by Category" /
    Shopify featured collections — image tiles for the top-level
    categories, so a visitor picks a department instead of scrolling
    the whole catalogue). Renders nothing when the store has no
    visible top-level categories.
--}}
@php
    $categoryTiles = ($shopCategories ?? collect())->take(! empty($limit) ? (int) $limit : 8);
@endphp

@if ($categoryTiles->isNotEmpty())
    <section class="container mt-20 max-lg:px-8 max-md:mt-8 max-sm:mt-7 max-sm:!px-4">
        <div class="flex items-center justify-between border-b border-gray-100 pb-4 max-sm:pb-2.5">
            <div class="flex items-center gap-3">
                <span class="inline-block h-6 w-2.5 rounded-full bg-emerald-600 max-sm:h-5"></span>

                <h2 class="font-dmserif text-3xl font-bold text-gray-900 max-md:text-2xl max-sm:text-xl">
                    {{ ! empty($title) ? $title : trans('shop::app.home.index.shop-by-category') }}
                </h2>
            </div>

            <a
                href="{{ route('shop.categories.index') }}"
                class="flex items-center gap-1.5 text-base font-semibold text-emerald-700 transition hover:text-emerald-800 max-sm:text-xs"
            >
                @lang('shop::app.components.products.carousel.view-all')

                <span class="icon-arrow-right rtl:icon-arrow-left text-lg max-sm:text-sm"></span>
            </a>
        </div>

        <div class="mt-8 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            @foreach ($categoryTiles as $category)
                <a
                    href="{{ $category->url }}"
                    class="group overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm transition-all duration-300 hover:-translate-y-1 hover:shadow-lg"
                >
                    @if ($category->logo_url)
                        <img
                            src="{{ $category->logo_url }}"
                            alt="{{ $category->name }}"
                            class="h-40 w-full object-cover transition-transform duration-300 group-hover:scale-105 max-sm:h-28"
                            loading="lazy"
                        />
                    @else
                        <div class="flex h-40 w-full items-center justify-center bg-zinc-100 text-3xl font-bold text-zinc-400 max-sm:h-28">
                            {{ mb_substr($category->name, 0, 1) }}
                        </div>
                    @endif

                    <p class="p-4 text-center text-base font-semibold text-gray-800 transition group-hover:text-emerald-700 max-sm:p-3 max-sm:text-sm">
                        {{ $category->name }}
                    </p>
                </a>
            @endforeach
        </div>
    </section>
@endif
