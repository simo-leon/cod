<?php
$searchTitle = $suggestion ?? $query;
$title = $searchTitle ? trans('shop::app.search.title', ['query' => $searchTitle]) : trans('shop::app.search.results');
$searchInstead = $suggestion ? $query : null;

/*
    Jemla: the product grid columns follow the homepage setting
    (Configuration → General → Homepage), same values as the
    homepage products grid — desktop / tablet / mobile.
*/
$gridDesktopColumns = min(6, max(2, (int) (core()->getConfigData('general.homepage.settings.grid_columns_desktop') ?? 4)));

$gridMobileColumns = min(3, max(1, (int) (core()->getConfigData('general.homepage.settings.grid_columns_mobile') ?? 2)));

$gridTabletColumns = min(3, $gridDesktopColumns);
?>
<!-- SEO Meta Content -->
@push('meta')
    <meta
        name="description"
        content="{{ $title }}"
    />

    <meta
        name="keywords"
        content="{{ $title }}"
    />
@endPush

@push('styles')
    <style>
        .jemla-listing-grid {
            display: grid;
            grid-template-columns: repeat({{ $gridDesktopColumns }}, minmax(0, 1fr));
        }

        @media (max-width: 1024px) and (min-width: 768px) {
            .jemla-listing-grid {
                grid-template-columns: repeat({{ $gridTabletColumns }}, minmax(0, 1fr));
            }
        }

        @media (max-width: 767px) {
            .jemla-listing-grid {
                grid-template-columns: repeat({{ $gridMobileColumns }}, minmax(0, 1fr));
            }
        }
    </style>
@endPush

<x-shop::layouts :has-feature="false">
    <!-- Page Title -->
    <x-slot:title>
        {{ $title }}
    </x-slot>

    <div class="container px-[60px] max-lg:px-8 max-sm:px-4">
        @if (request()->has('image-search'))
            @include('shop::search.images.results')
        @endif

        <div class="mt-8 flex items-center justify-between max-md:mt-5">
            <h2 class="break-all text-2xl font-medium max-sm:text-base">
                <span v-text="'{{ preg_replace('/[,\\"\\\']+/', '', $title) }}'" ></span>
            </h2>
        </div>

        @if ($searchInstead)
            <form
                action="{{ route('shop.search.index', ['suggest' => false]) }}"
                class="flex max-w-[445px] items-center"
                role="search"
            >
                <input
                    type="text"
                    name="query"
                    class="hidden"
                    value="{{ $searchInstead }}"
                >

                <input
                    type="text"
                    name="suggest"
                    class="hidden"
                    value="0"
                >

                <p
                    class="mt-1 text-sm text-gray-600"
                    v-pre
                >
                    {{ trans('shop::app.search.suggest') }}

                    <button
                        type="submit"
                        class="text-blue-600 hover:text-blue-800 hover:underline"
                        aria-label="{{ trans('shop::app.components.layouts.header.desktop.bottom.submit') }}"
                    >
                        {{ $searchInstead }}
                    </button>
                </p>
            </form>
        @endif
    </div>

    <!-- Product Listing -->
    <v-search>
        <x-shop::shimmer.categories.view />
    </v-search>

    @pushOnce('scripts')
        <script
            type="text/x-template"
            id="v-search-template"
        >
            <div class="container px-[60px] max-lg:px-8 max-sm:px-4">
                <div class="flex items-start gap-10 max-lg:gap-5 md:mt-10">
                    <!-- Product Listing Filters -->
                    @include('shop::categories.filters')

                    <!-- Product Listing Container -->
                    <div class="flex-1">
                        @if (core()->getConfigData('magic_ai.general.settings.enabled') && core()->getConfigData('magic_ai.storefront_features.ai_search_overview.enabled'))
                            @include('shop::search.ai-overview')
                        @endif

                        <!-- Desktop Product Listing Toolbar -->
                        <div class="max-md:hidden">
                            @include('shop::categories.toolbar')
                        </div>

                        <!-- Product List Card Container -->
                        <div
                            class="mt-8 grid grid-cols-1 gap-6"
                            v-if="(filters.toolbar.applied.mode ?? filters.toolbar.default.mode) === 'list'"
                        >
                            <!-- Product Card Shimmer Effect -->
                            <template v-if="isLoading">
                                <x-shop::shimmer.products.cards.list count="3" />
                            </template>

                            <!-- Product Card Listing -->
                            <template v-else>
                                <template v-if="products.length">
                                    <x-shop::products.card
                                        ::mode="'list'"
                                        v-for="product in products"
                                    />
                                </template>

                                <!-- Empty Products Container -->
                                <template v-else>
                                    <div class="m-auto grid w-full place-content-center items-center justify-items-center py-32 text-center">
                                        <img
                                            class="max-sm:h-[100px] max-sm:w-[100px]"
                                            src="{{ bagisto_asset('images/thank-you.png') }}"
                                            alt="Empty result"
                                            loading="lazy"
                                            decoding="async"
                                        />

                                        <p
                                            class="text-xl max-sm:text-sm"
                                            role="heading"
                                        >
                                            @lang('shop::app.categories.view.empty')
                                        </p>
                                    </div>
                                </template>
                            </template>
                        </div>

                        <!-- Product Grid Card Container -->
                        <div v-else>
                            <!-- Product Card Shimmer Effect -->
                            <template v-if="isLoading">
                                <div class="jemla-listing-grid mt-8 grid gap-8 max-md:gap-x-4 max-sm:mt-5 max-sm:justify-items-center max-sm:gap-y-5">
                                    <x-shop::shimmer.products.cards.grid count="3" />
                                </div>
                            </template>

                            <!-- Product Card Listing -->
                            <template v-else>
                                <template v-if="products.length">
                                    <div class="jemla-listing-grid mt-8 grid gap-8 max-md:mt-5 max-md:justify-items-center max-md:gap-x-4 max-md:gap-y-5">
                                        <x-shop::products.card
                                            ::mode="'grid'"
                                            v-for="product in products"
                                            :navigation-link="route('shop.search.index')"
                                        />
                                    </div>
                                </template>

                                <!-- Empty Products Container -->
                                <template v-else>
                                    <div class="m-auto grid w-full place-content-center items-center justify-items-center py-32 text-center">
                                        <img
                                            class="max-sm:h-[100px] max-sm:w-[100px]"
                                            src="{{ bagisto_asset('images/thank-you.png') }}"
                                            alt="Empty result"
                                            loading="lazy"
                                            decoding="async"
                                        />

                                        <p
                                            class="text-xl max-sm:text-sm"
                                            role="heading"
                                        >
                                            @lang('shop::app.categories.view.empty')
                                        </p>
                                    </div>
                                </template>
                            </template>
                        </div>

                        <!-- Load More Button -->
                        <button
                            class="secondary-button mx-auto mt-[60px] block w-max rounded-2xl px-11 py-3 text-center text-base max-md:rounded-lg max-md:text-sm max-sm:mt-7 max-sm:px-7 max-sm:py-2"
                            @click="loadMoreProducts"
                            v-if="links.next && ! loader"
                        >
                            @lang('shop::app.categories.view.load-more')
                        </button>

                        <button
                            v-else-if="links.next"
                            class="secondary-button mx-auto mt-[60px] block w-max rounded-2xl px-[74.5px] py-3.5 text-center text-base max-md:rounded-lg max-md:py-3 max-sm:mt-7 max-sm:px-[50.8px] max-sm:py-1.5"
                        >
                            <!-- Spinner -->
                            <img
                                class="h-5 w-5 animate-spin text-navyBlue"
                                src="{{ bagisto_asset('images/spinner.svg') }}"
                                alt="Loading"
                            />
                        </button>
                    </div>
                </div>
            </div>
    </script>

        <script type="module">
            app.component('v-search', {
                template: '#v-search-template',

                data() {
                    return {
                        isMobile: window.innerWidth <= 767,

                        isLoading: true,

                        isDrawerActive: {
                            toolbar: false,

                            filter: false,
                        },

                        filters: {
                            toolbar: {
                                default: {},

                                applied: {},
                            },

                            filter: {},
                        },

                        products: [],

                        links: {},

                        loader: false,

                        /*
                            Jemla: listingRequestId invalidates a load
                            superseded by a filter/sort/limit change.
                        */
                        listingRequestId: 0,
                    }
                },

                computed: {
                    queryParams() {
                        let queryParams = Object.assign({}, this.filters.filter, this.filters.toolbar.applied);

                        return this.removeJsonEmptyValues(queryParams);
                    },

                    queryString() {
                        return this.jsonToQueryString(this.queryParams);
                    },
                },

                watch: {
                    queryParams() {
                        this.getProducts();
                    },

                    queryString() {
                        window.history.pushState({}, '', '?' + this.queryString);
                    },
                },

                methods: {
                    setFilters(type, filters) {
                        this.filters[type] = filters;
                    },

                    clearFilters(type, filters) {
                        this.filters[type] = {};
                    },

                    /*
                        Batch target: the toolbar limit, capped at 48 —
                        the server caps a listing batch at 48 products.
                        queryParams only carries a limit when the
                        customer picked one different from the default
                        (the toolbar emits non-default filters only), so
                        fall back to the toolbar default limit — the
                        first value of the admin's per-page list — and
                        never to a hard-coded 48, or the admin value
                        would be ignored and the batch would always
                        stream up to 48.
                    */
                    batchTarget() {
                        let limit = parseInt(this.queryParams.limit ?? this.filters.toolbar.default.limit ?? 12, 10);

                        if (isNaN(limit) || limit < 1) {
                            limit = 12;
                        }

                        return Math.min(limit, 48);
                    },

                    getProducts() {
                        this.isDrawerActive = {
                            toolbar: false,

                            filter: false,
                        };

                        /*
                            Jemla: show the shimmer (instead of the empty
                            state) while a new filter/sort/limit reloads —
                            getProducts now resets products to [] first.
                        */
                        this.isLoading = true;

                        this.products = [];

                        this.links = {};

                        this.loader = false;

                        const requestId = ++this.listingRequestId;

                        /*
                            Jemla: one request per batch — the toolbar
                            limit (admin per-page, default 12) in a
                            single call. Streaming 3-product chunks
                            turned every batch into 4 sequential API
                            requests and made listings open very slowly
                            on phones (measured 2026-11-26: a cold
                            listing request takes ~3s on live).
                        */
                        this.$axios.get(("{{ route('shop.api.products.index') }}"), {
                            params: { ...this.queryParams, limit: this.batchTarget() }
                        })
                            .then(response => {
                                if (requestId !== this.listingRequestId) {
                                    return;
                                }

                                this.isLoading = false;

                                this.products = response.data.data;

                                this.links = response.data.links;

                                this.loader = false;
                            }).catch(error => {
                                console.log(error);
                            });
                    },

                    /*
                        Jemla: "see more" loads exactly one page (the
                        next page of the same batch) — no streaming
                        chain after the click.
                    */
                    loadMoreProducts() {
                        if (! this.links.next || this.loader) {
                            return;
                        }

                        this.loader = true;

                        const requestId = this.listingRequestId;

                        this.$axios.get(this.links.next).then(response => {
                            if (requestId !== this.listingRequestId) {
                                return;
                            }

                            this.products = [...this.products, ...response.data.data];

                            this.links = response.data.links;

                            this.loader = false;
                        }).catch(error => {
                            this.loader = false;

                            console.log(error);
                        });
                    },

                    removeJsonEmptyValues(params) {
                        Object.keys(params).forEach(function (key) {
                            if ((! params[key] && params[key] !== undefined)) {
                                delete params[key];
                            }

                            if (Array.isArray(params[key])) {
                                params[key] = params[key].join(',');
                            }
                        });

                        return params;
                    },

                    jsonToQueryString(params) {
                        let parameters = new URLSearchParams();

                        for (const key in params) {
                            parameters.append(key, params[key]);
                        }

                        return parameters.toString();
                    }
                },
            });
        </script>
    @endPushOnce
</x-shop::layouts>
