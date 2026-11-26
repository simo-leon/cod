<x-shop::layouts>
    <!-- Page Title -->
    <x-slot:title>
        @lang('shop::app.components.layouts.header.desktop.bottom.categories')
    </x-slot>

    <div class="container px-[60px] max-lg:px-8 max-md:px-4">
        <h1 class="mt-10 text-2xl font-medium max-md:mt-5 max-sm:text-lg">
            @lang('shop::app.components.layouts.header.desktop.bottom.categories')
        </h1>

        @if ($categories->isNotEmpty())
            <div class="mb-16 mt-8 grid grid-cols-4 gap-8 max-lg:grid-cols-3 max-md:mt-5 max-md:grid-cols-2 max-md:gap-4">
                @foreach ($categories as $category)
                    <a
                        href="{{ $category->url }}"
                        class="group flex flex-col items-center gap-4 rounded-2xl border border-zinc-100 p-6 text-center transition hover:-translate-y-1 hover:shadow-lg max-md:gap-2.5 max-md:p-4"
                    >
                        <span class="flex h-[110px] w-[110px] items-center justify-center overflow-hidden rounded-full bg-zinc-100 max-md:h-20 max-md:w-20">
                            <img
                                src="{{ $category->logo_url ?: bagisto_asset('images/small-product-placeholder.webp') }}"
                                alt="{{ $category->name }}"
                                width="110"
                                height="110"
                                class="h-full w-full object-cover"
                                loading="lazy"
                                decoding="async"
                            />
                        </span>

                        <span class="text-lg font-medium text-zinc-900 group-hover:text-emerald-700 max-md:text-base">
                            {{ $category->name }}
                        </span>
                    </a>
                @endforeach
            </div>
        @else
            <div class="m-auto grid w-full place-content-center items-center justify-items-center py-32 text-center">
                <img
                    class="max-md:h-[100px] max-md:w-[100px]"
                    src="{{ bagisto_asset('images/thank-you.png') }}"
                    alt="@lang('shop::app.categories.view.empty')"
                    loading="lazy"
                    decoding="async"
                />

                <p
                    class="text-xl max-md:text-sm"
                    role="heading"
                >
                    @lang('shop::app.categories.view.empty')
                </p>
            </div>
        @endif
    </div>
</x-shop::layouts>
