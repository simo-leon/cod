{{--
    Customer reviews topic (Jemla: Shopify social proof — the
    latest approved product reviews, straight from the reviews
    system the store already has. Renders nothing until real
    approved reviews exist; nothing here is invented.
--}}
@php
    $shownReviews = ($latestReviews ?? collect())->take(! empty($limit) ? (int) $limit : 6);
@endphp

@if ($shownReviews->isNotEmpty())
    <section class="container mt-20 max-lg:px-8 max-md:mt-8 max-sm:mt-7 max-sm:!px-4">
        <div class="flex items-center gap-3 border-b border-gray-100 pb-4 max-sm:pb-2.5">
            <span class="inline-block h-6 w-2.5 rounded-full bg-emerald-600 max-sm:h-5"></span>

            <h2 class="font-dmserif text-3xl font-bold text-gray-900 max-md:text-2xl max-sm:text-xl">
                {{ ! empty($title) ? $title : trans('shop::app.home.index.customer-reviews') }}
            </h2>
        </div>

        <div class="mt-8 grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
            @foreach ($shownReviews as $review)
                <figure class="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm">
                    <div
                        class="text-lg tracking-widest text-amber-500"
                        aria-label="{{ $review->rating }}/5"
                    >
                        {{ str_repeat('★', (int) $review->rating) }}<span class="text-gray-300">{{ str_repeat('★', max(0, 5 - (int) $review->rating)) }}</span>
                    </div>

                    @if ($review->title)
                        <figcaption class="mt-2 text-base font-bold text-gray-900">
                            {{ $review->title }}
                        </figcaption>
                    @endif

                    @if ($review->comment)
                        <blockquote class="mt-1 line-clamp-3 text-sm leading-6 text-gray-600">
                            {{ $review->comment }}
                        </blockquote>
                    @endif

                    <div class="mt-3 text-sm text-gray-500">
                        <span class="font-semibold text-gray-800">{{ $review->name }}</span>

                        @if ($review->product)
                            <span> · </span>

                            <a
                                href="{{ route('shop.product_or_category.index', $review->product->url_key) }}"
                                class="text-emerald-700 hover:underline"
                            >
                                {{ $review->product->name }}
                            </a>
                        @endif
                    </div>
                </figure>
            @endforeach
        </div>
    </section>
@endif
