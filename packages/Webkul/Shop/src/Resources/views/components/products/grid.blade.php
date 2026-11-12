<v-products-grid
    src="{{ $src }}"
    title="{{ $title }}"
    navigation-link="{{ $navigationLink ?? '' }}"
>
    <div class="container mt-20 max-lg:px-8 max-md:mt-8 max-sm:mt-7 max-sm:!px-4">
        <div class="wa-products-grid-container">
            <x-shop::shimmer.products.cards.grid count="8" />
        </div>
    </div>
</v-products-grid>

<style>
    /* PC (Desktop: 4 products per row) */
    .wa-products-grid-container {
        display: grid !important;
        grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
        gap: 20px !important;
        margin-top: 1.5rem !important;
    }

    /* Tablet (Tab: 3 products per row) */
    @media (max-width: 1024px) and (min-width: 768px) {
        .wa-products-grid-container {
            grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
            gap: 16px !important;
        }
    }

    /* Phone (Mobile: 2 products per row) */
    @media (max-width: 767px) {
        .wa-products-grid-container {
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 12px !important;
            margin-top: 1rem !important;
        }
    }
</style>

@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-products-grid-template"
    >
        <div
            class="container mt-20 max-lg:px-8 max-md:mt-8 max-sm:mt-7 max-sm:!px-4"
            v-if="! isLoading && products.length"
        >
            <!-- Header (Title & Navigation) -->
            <div class="flex items-center justify-between pb-4 border-b border-gray-100 max-sm:pb-2.5">
                <div class="flex items-center gap-3">
                    <span class="inline-block w-2.5 h-6 rounded-full bg-emerald-600 max-sm:h-5"></span>
                    <h2 class="font-dmserif text-3xl max-md:text-2xl max-sm:text-xl font-bold text-gray-900">
                        @{{ title }}
                    </h2>
                </div>

                <a
                    :href="navigationLink"
                    class="flex items-center gap-1.5 text-base font-semibold text-emerald-700 hover:text-emerald-800 transition max-sm:text-xs"
                    v-if="navigationLink"
                >
                    <span>عرض الكل</span>
                    <span class="icon-arrow-right rtl:icon-arrow-left text-lg max-sm:text-sm"></span>
                </a>
            </div>

            <!-- Products Grid -->
            <div class="wa-products-grid-container">
                <div
                    v-for="product in products"
                    :key="product.id"
                    class="group relative flex flex-col justify-between overflow-hidden rounded-2xl border border-gray-100 bg-white p-3 shadow-sm transition-all duration-300 hover:-translate-y-1 hover:shadow-lg max-sm:p-2.5"
                >
                    <!-- Product Image & Badges -->
                    <div class="relative overflow-hidden rounded-xl bg-gray-50 aspect-square">
                        <a
                            :href="'{{ route('shop.product_or_category.index', ':slug') }}'.replace(':slug', product.url_key)"
                            class="block w-full h-full"
                            :aria-label="product.name"
                        >
                            <img
                                :src="product.base_image.medium_image_url"
                                :alt="product.name"
                                class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-105"
                                loading="lazy"
                            />
                        </a>

                        <!-- Top Badges -->
                        <div class="absolute top-2 start-2 end-2 flex items-center justify-between pointer-events-none gap-1">
                            <!-- Sale Badge -->
                            <span
                                v-if="product.on_sale"
                                class="rounded-md bg-rose-600 px-2 py-0.5 text-[11px] font-bold text-white shadow-sm max-sm:text-[10px]"
                            >
                                تخفيض
                            </span>
                            <span v-else></span>

                            <!-- Moroccan Trust Badge: COD -->
                            <span
                                class="rounded-md bg-emerald-700/90 backdrop-blur-sm px-2 py-0.5 text-[10px] font-bold text-white shadow-sm max-sm:text-[9px]"
                            >
                                🚚 الدفع عند الاستلام
                            </span>
                        </div>
                    </div>

                    <!-- Product Details -->
                    <div class="mt-3 flex flex-1 flex-col justify-between max-sm:mt-2">
                        <div>
                            <a
                                :href="'{{ route('shop.product_or_category.index', ':slug') }}'.replace(':slug', product.url_key)"
                                class="block font-medium text-gray-800 hover:text-emerald-700 transition line-clamp-2 text-sm leading-5 max-sm:text-xs max-sm:leading-4"
                            >
                                @{{ product.name }}
                            </a>

                            <!-- Price HTML -->
                            <div
                                class="mt-2 flex flex-wrap items-baseline gap-x-2 text-base font-bold text-gray-900 max-sm:text-sm"
                                v-html="product.price_html"
                            >
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="mt-3 flex flex-col gap-2 max-sm:mt-2">
                            <!-- WhatsApp Quick Order Button -->
                            <a
                                v-if="waEnabled && waPhone"
                                :href="getWhatsAppUrl(product)"
                                target="_blank"
                                rel="noopener noreferrer"
                                style="background-color: #25D366; color: #ffffff !important; text-decoration: none;"
                                class="flex w-full items-center justify-center gap-1.5 rounded-xl py-2 px-3 text-xs font-bold shadow-sm transition hover:opacity-90 active:scale-[0.98] max-sm:py-1.5 max-sm:text-[11px]"
                            >
                                <svg style="width: 16px; height: 16px; min-width: 16px; fill: #ffffff;" viewBox="0 0 24 24">
                                    <path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/>
                                </svg>
                                <span>طلب عبر واتساب</span>
                            </a>

                            <!-- View Details Button -->
                            <a
                                :href="'{{ route('shop.product_or_category.index', ':slug') }}'.replace(':slug', product.url_key)"
                                class="flex w-full items-center justify-center rounded-xl border border-gray-200 bg-gray-50 py-1.5 px-3 text-xs font-semibold text-gray-700 transition hover:bg-gray-100 max-sm:py-1 max-sm:text-[11px]"
                            >
                                تفاصيل المنتج
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Scroll Sentinel (Target for IntersectionObserver) -->
            <div ref="scrollSentinel" class="h-6 w-full"></div>

            <!-- Loading indicator for next page -->
            <div v-if="isLoadingMore" class="mt-8 flex justify-center items-center gap-3 text-sm font-semibold text-emerald-800">
                <svg class="animate-spin h-5 w-5 text-emerald-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span>جاري تحميل المزيد من المنتجات...</span>
            </div>

            <!-- "Voir plus / عرض المزيد" button after max automatic loads reached -->
            <div
                v-if="links && links.next && autoLoadCount >= maxAutoLoads && ! isLoadingMore"
                class="mt-10 flex justify-center"
            >
                <button
                    type="button"
                    @click="loadMoreProducts(true)"
                    class="inline-flex items-center gap-2 rounded-2xl bg-navyBlue px-10 py-3.5 text-center text-base font-bold text-white shadow-md transition hover:bg-black active:scale-[0.98] max-md:rounded-lg max-md:px-6 max-md:py-2.5 max-sm:text-sm"
                >
                    <span>عرض المزيد من المنتجات</span>
                    <span class="icon-arrow-down text-lg"></span>
                </button>
            </div>
        </div>

        <!-- Initial Shimmer loader -->
        <template v-if="isLoading">
            <div class="container mt-20 max-lg:px-8 max-md:mt-8 max-sm:mt-7 max-sm:!px-4">
                <div class="wa-products-grid-container">
                    <x-shop::shimmer.products.cards.grid count="8" />
                </div>
            </div>
        </template>
    </script>

    <script type="module">
        app.component('v-products-grid', {
            template: '#v-products-grid-template',

            props: [
                'src',
                'title',
                'navigationLink',
            ],

            data() {
                return {
                    isLoading: true,
                    isLoadingMore: false,
                    products: [],
                    links: null,
                    autoLoadCount: 0,
                    maxAutoLoads: 2, // Auto-scroll loads up to 2 additional pages, then requires button click
                    observer: null,
                    waEnabled: {{ core()->getConfigData('general.general.whatsapp.status') ? 'true' : 'false' }},
                    waPhone: '{{ preg_replace('/[^0-9]/', '', (string)core()->getConfigData('general.general.whatsapp.phone_number')) }}',
                    waProductMsg: '{{ addslashes(core()->getConfigData('general.general.whatsapp.product_message') ?: 'السلام عليكم، أود طلب / الاستفسار عن هذا المنتج:') }}',
                };
            },

            mounted() {
                this.getProducts();
            },

            beforeDestroy() {
                if (this.observer) {
                    this.observer.disconnect();
                }
            },

            methods: {
                getProducts() {
                    this.$axios.get(this.src)
                        .then(response => {
                            this.isLoading = false;
                            this.products = response.data.data || [];
                            this.links = response.data.links || null;

                            this.$nextTick(() => {
                                this.initObserver();
                            });
                        })
                        .catch(error => {
                            this.isLoading = false;
                            console.error(error);
                        });
                },

                initObserver() {
                    if (this.observer) {
                        this.observer.disconnect();
                    }

                    if (! this.$refs.scrollSentinel) {
                        return;
                    }

                    this.observer = new IntersectionObserver((entries) => {
                        const entry = entries[0];
                        if (entry && entry.isIntersecting) {
                            if (this.links && this.links.next && ! this.isLoadingMore && this.autoLoadCount < this.maxAutoLoads) {
                                this.loadMoreProducts(false);
                            }
                        }
                    }, {
                        rootMargin: '200px 0px',
                        threshold: 0.1,
                    });

                    this.observer.observe(this.$refs.scrollSentinel);
                },

                loadMoreProducts(isManualClick = false) {
                    if (! this.links || ! this.links.next || this.isLoadingMore) {
                        return;
                    }

                    this.isLoadingMore = true;

                    this.$axios.get(this.links.next)
                        .then(response => {
                            this.isLoadingMore = false;
                            const newProducts = response.data.data || [];
                            this.products = [...this.products, ...newProducts];
                            this.links = response.data.links || null;

                            if (! isManualClick) {
                                this.autoLoadCount++;
                            }

                            this.$nextTick(() => {
                                if (this.$refs.scrollSentinel && this.observer) {
                                    this.observer.observe(this.$refs.scrollSentinel);
                                }
                            });
                        })
                        .catch(error => {
                            this.isLoadingMore = false;
                            console.error(error);
                        });
                },

                getWhatsAppUrl(product) {
                    if (! this.waPhone) {
                        return '#';
                    }

                    const productUrl = window.location.origin + '/' + product.url_key;
                    const price = product.min_price || '';
                    const message = this.waProductMsg + '\n📦 ' + product.name + (price ? '\n💰 ' + price : '') + '\n🔗 ' + productUrl;

                    return 'https://api.whatsapp.com/send?phone=' + this.waPhone + '&text=' + encodeURIComponent(message);
                },
            },
        });
    </script>
@endPushOnce
