{{--
    Jemla store 404 page (Laravel renders resources/views/errors/404.blade.php
    for every web 404). The shop package's errors/index.blade.php is never
    rendered by anything, which is why visitors saw Laravel's bare
    "404 | NOT FOUND" page. This page uses the normal store layout so a lost
    visitor still gets the header (with search), the footer, a search box and
    a way back home — without touching the theme.
    NOTE: use only Tailwind classes already present in the compiled shop CSS
    (public/themes/shop/default/build/assets/app-*.css) — arbitrary values
    like text-[120px] are NOT compiled and silently do nothing.
--}}
<x-shop::layouts
    :has-header="true"
    :has-feature="false"
    :has-footer="true"
>
    <!-- Page Title -->
    <x-slot:title>
        @lang('shop::app.errors.404.title')
    </x-slot>

    <!-- Page content -->
    <div class="container px-[60px] py-16 max-lg:px-8 max-sm:px-4 max-sm:py-10">
        <div class="mx-auto grid max-w-xl place-items-center gap-y-5 text-center max-md:gap-y-2.5">
            <p class="text-6xl font-bold leading-none text-navyBlue">
                404
            </p>

            <h1 class="text-3xl font-semibold max-md:text-xl">
                @lang('shop::app.errors.404.title')
            </h1>

            <p class="text-lg text-zinc-500 max-md:text-sm">
                @lang('shop::app.errors.404.description')
            </p>

            <!-- Search -->
            <form
                action="{{ route('shop.search.index') }}"
                method="GET"
                role="search"
                class="mt-2 flex w-full items-center"
            >
                <label
                    for="error-404-search"
                    class="sr-only"
                >
                    @lang('shop::app.components.layouts.header.desktop.bottom.search')
                </label>

                <input
                    id="error-404-search"
                    type="text"
                    name="query"
                    class="block w-full rounded-lg border border-zinc-300 bg-white px-4 py-3 text-base text-zinc-700 placeholder:text-zinc-400 focus:border-blue-600 focus:ring-0"
                    placeholder="@lang('shop::app.components.layouts.header.desktop.bottom.search-text')"
                    aria-label="@lang('shop::app.components.layouts.header.desktop.bottom.search-text')"
                    required
                >

                <button
                    type="submit"
                    class="ml-2 shrink-0 cursor-pointer rounded-lg bg-navyBlue px-6 py-3 text-base font-medium text-white max-sm:px-4 max-sm:text-sm"
                >
                    @lang('shop::app.components.layouts.header.desktop.bottom.search')
                </button>
            </form>

            <a
                href="{{ route('shop.home.index') }}"
                class="mt-4 block w-max cursor-pointer rounded-full bg-navyBlue px-10 py-4 text-center text-base font-medium text-white max-sm:px-6 max-sm:text-sm"
            >
                @lang('shop::app.errors.go-to-home')
            </a>
        </div>
    </div>
</x-shop::layouts>
