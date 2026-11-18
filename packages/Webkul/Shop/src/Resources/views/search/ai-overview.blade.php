<v-ai-search-overview query="{{ $searchTitle ?? '' }}"></v-ai-search-overview>

@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-ai-search-overview-template"
    >
        <div
            id="ai-overview"
            class="mb-8 overflow-hidden rounded-2xl border border-indigo-100 bg-gradient-to-br from-indigo-50/60 via-purple-50/40 to-white p-6 shadow-sm transition-all duration-300"
            v-if="isLoading || hasOverview"
        >
            <!-- Header -->
            <div class="flex items-center justify-between border-b border-indigo-100/60 pb-3">
                <div class="flex items-center gap-2.5">
                    <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-600 text-white shadow-sm">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456zM16.894 20.567L16.5 21.75l-.394-1.183a2.25 2.25 0 00-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 001.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 001.423 1.423l1.183.394-1.183.394a2.25 2.25 0 00-1.423 1.423z" />
                        </svg>
                    </span>

                    <h3 class="text-lg font-semibold text-gray-900">
                        @lang('shop::app.search.ai-overview.title')
                    </h3>
                </div>

                <span class="inline-flex items-center gap-1.5 rounded-full bg-indigo-100/70 px-3 py-1 text-xs font-medium text-indigo-700">
                    <span class="h-1.5 w-1.5 rounded-full bg-indigo-600 animate-pulse"></span>
                    @lang('shop::app.search.ai-overview.powered-by')
                </span>
            </div>

            <!-- Skeleton Loader -->
            <div
                class="mt-4 space-y-4 animate-pulse"
                v-if="isLoading"
            >
                <div class="flex items-center gap-2 text-xs text-indigo-600 font-medium">
                    <svg class="h-4 w-4 animate-spin text-indigo-600" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span>@lang('shop::app.search.ai-overview.generating')</span>
                </div>

                <div class="space-y-2.5">
                    <div class="h-3.5 w-full rounded-md bg-indigo-100/80"></div>
                    <div class="h-3.5 w-11/12 rounded-md bg-indigo-100/70"></div>
                    <div class="h-3.5 w-4/5 rounded-md bg-indigo-100/60"></div>
                </div>

                <!-- Skeleton Cards -->
                <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
                    <div
                        v-for="n in 4"
                        :key="n"
                        class="rounded-xl border border-gray-100 bg-white/70 p-3 shadow-xs"
                    >
                        <div class="h-24 w-full rounded-lg bg-gray-200/70"></div>
                        <div class="mt-2.5 h-3 w-3/4 rounded bg-gray-200/70"></div>
                        <div class="mt-1.5 h-3 w-1/2 rounded bg-indigo-200/60"></div>
                    </div>
                </div>
            </div>

            <!-- Summary Content -->
            <div
                class="mt-4"
                v-else-if="hasOverview"
            >
                <div class="text-sm md:text-base leading-relaxed text-gray-800 whitespace-pre-line font-normal">
                    @{{ summary }}
                </div>

                <!-- Recommended Products List -->
                <div
                    class="mt-6 border-t border-indigo-100/60 pt-4"
                    v-if="products && products.length"
                >
                    <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-500 mb-3">
                        @lang('shop::app.search.ai-overview.recommended-products')
                    </h4>

                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
                        <div
                            v-for="product in products"
                            :key="product.id"
                            class="group relative flex flex-col justify-between overflow-hidden rounded-xl border border-indigo-100/70 bg-white p-3 shadow-xs transition hover:shadow-md hover:border-indigo-300"
                        >
                            <a
                                :href="product.url"
                                class="block"
                            >
                                <div class="aspect-square w-full overflow-hidden rounded-lg bg-gray-100">
                                    <img
                                        :src="product.image"
                                        :alt="product.name"
                                        class="h-full w-full object-cover object-center transition duration-300 group-hover:scale-105"
                                        loading="lazy"
                                    />
                                </div>

                                <h5 class="mt-2 text-xs md:text-sm font-medium text-gray-900 line-clamp-2 leading-tight">
                                    @{{ product.name }}
                                </h5>

                                <p class="mt-1.5 text-xs md:text-sm font-bold text-indigo-700">
                                    @{{ product.price }}
                                </p>
                            </a>

                            <a
                                :href="product.url"
                                class="mt-3 flex items-center justify-center gap-1 rounded-lg bg-indigo-50 px-2.5 py-1.5 text-xs font-semibold text-indigo-700 transition hover:bg-indigo-600 hover:text-white"
                            >
                                <span>@lang('shop::app.search.ai-overview.view-product')</span>
                                <svg class="h-3 w-3 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                                </svg>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </script>

    <script type="module">
        app.component('v-ai-search-overview', {
            template: '#v-ai-search-overview-template',

            props: {
                query: {
                    type: String,
                    default: '',
                },
            },

            data() {
                return {
                    isLoading: true,
                    hasOverview: false,
                    summary: '',
                    products: [],
                };
            },

            mounted() {
                this.fetchAiOverview();
            },

            methods: {
                fetchAiOverview() {
                    const searchParam = this.query || new URLSearchParams(window.location.search).get('query');

                    if (! searchParam || ! searchParam.trim()) {
                        this.isLoading = false;
                        return;
                    }

                    this.$axios.post("{{ route('shop.ai.search.overview') }}", {
                        query: searchParam.trim(),
                    })
                    .then(response => {
                        this.isLoading = false;

                        if (response.data && response.data.has_overview) {
                            this.hasOverview = true;
                            this.summary = response.data.summary;
                            this.products = response.data.products || [];
                        } else {
                            this.hasOverview = false;
                        }
                    })
                    .catch(error => {
                        this.isLoading = false;
                        this.hasOverview = false;
                        console.error('AI Overview failed:', error);
                    });
                },
            },
        });
    </script>
@endPushOnce
