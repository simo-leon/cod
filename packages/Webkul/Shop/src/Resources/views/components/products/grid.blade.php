@php
    /*
        Jemla: how many products per row the homepage grid shows
        (Configuration → General → Homepage). The static <style>
        below, the Vue grid style and the injected global style all
        read the same values, so the columns never disagree between
        the first paint and the hydrated grid. A product-grid topic
        may pass its own columns ($columnsDesktop / $columnsMobile);
        empty means "use the global setting".

        $infiniteScroll (default false): when true — the related
        products on the product page — the grid auto-loads the next
        page every time the user scrolls to the bottom, with no cap
        and no button, until the endpoint runs out of products.
        Homepage sections keep the fixed count + "view all" link.
    */
    $gridDesktopColumns = min(6, max(2, (int) (($columnsDesktop ?? null) ?: (core()->getConfigData('general.homepage.settings.grid_columns_desktop') ?? 4))));

    $gridMobileColumns = min(3, max(1, (int) (($columnsMobile ?? null) ?: (core()->getConfigData('general.homepage.settings.grid_columns_mobile') ?? 2))));

    $gridTabletColumns = min(3, $gridDesktopColumns);
@endphp

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
    /* Direct global styles fallback */
    .wa-products-grid-container {
        display: grid !important;
        grid-template-columns: repeat({{ $gridDesktopColumns }}, minmax(0, 1fr)) !important;
        gap: 20px !important;
        width: 100% !important;
        margin-top: 1.5rem !important;
    }

    @media (max-width: 1024px) and (min-width: 768px) {
        .wa-products-grid-container {
            grid-template-columns: repeat({{ $gridTabletColumns }}, minmax(0, 1fr)) !important;
            gap: 16px !important;
        }
    }

    @media (max-width: 767px) {
        .wa-products-grid-container {
            grid-template-columns: repeat({{ $gridMobileColumns }}, minmax(0, 1fr)) !important;
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
        <!-- Jemla: lazy-start trigger — the first load only starts when
             the section is near the viewport, like the related products
             below the fold: they never compete with the first paint.
             The fixed count + "view all" behaviour is unchanged, and
             the infinite-scroll grid keeps its own sentinel below. -->
        <div
            ref="loadTrigger"
            class="h-px w-full"
            aria-hidden="true"
        ></div>

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
                    @lang('shop::app.components.products.grid.view-all')
                    <span class="icon-arrow-right rtl:icon-arrow-left text-lg max-sm:text-sm"></span>
                </a>
            </div>

            <!-- Products Grid with Dynamic Reactive Columns -->
            <div
                class="wa-products-grid-container"
                :style="gridStyle"
            >
                <div
                    v-for="product in products"
                    :key="product.id"
                    class="group relative overflow-hidden rounded-2xl border border-gray-100 p-3 shadow-sm transition-all duration-300 hover:-translate-y-1 hover:shadow-lg max-sm:p-2.5"
                    style="display: flex; flex-direction: column; justify-content: space-between; height: 100%; background: #ffffff;"
                >
                    <!-- Product Image & Badges -->
                    <div
                        class="relative overflow-hidden rounded-xl bg-gray-50"
                        style="aspect-ratio: 1 / 1; width: 100%; position: relative;"
                    >
                        <a
                            :href="'{{ route('shop.product_or_category.index', ':slug') }}'.replace(':slug', product.url_key)"
                            class="block w-full h-full"
                            :aria-label="product.name"
                        >
                            <img
                                :src="product.base_image.medium_image_url"
                                :alt="product.name"
                                style="width: 100%; height: 100%; object-fit: cover; color: transparent;"
                                class="transition-transform duration-300 group-hover:scale-105"
                                loading="lazy"
                                v-on:error="onImageError($event)"
                            />
                        </a>

                        <!-- Top Badges -->
                        <div class="absolute top-2 start-2 end-2 flex items-center justify-between pointer-events-none gap-1">
                            <!-- Sale Badge -->
                            <span
                                v-if="product.on_sale"
                                class="rounded-md bg-rose-600 px-2 py-0.5 text-[11px] font-bold text-white shadow-sm max-sm:text-[10px]"
                            >
                                @lang('shop::app.components.products.grid.sale')
                            </span>
                            <span v-else></span>

                            <!-- Moroccan Trust Badge: COD -->
                            <span
                                class="rounded-md bg-emerald-700/90 backdrop-blur-sm px-2 py-0.5 text-[10px] font-bold text-white shadow-sm max-sm:text-[9px]"
                            >
                                @lang('shop::app.components.products.grid.cod-badge')
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
                                <span>@lang('shop::app.components.products.grid.whatsapp-order')</span>
                            </a>

                            <!-- View Details Button -->
                            <a
                                :href="'{{ route('shop.product_or_category.index', ':slug') }}'.replace(':slug', product.url_key)"
                                class="flex w-full items-center justify-center rounded-xl border border-gray-200 bg-gray-50 py-1.5 px-3 text-xs font-semibold text-gray-700 transition hover:bg-gray-100 max-sm:py-1 max-sm:text-[11px]"
                            >
                                @lang('shop::app.components.products.grid.view-details')
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Scroll Sentinel (Jemla: only for the infinite-scroll grid —
                 related products — auto-loads the next page on scroll,
                 no cap, no button, until the endpoint runs out) -->
            <div
                v-if="infiniteScroll"
                ref="scrollSentinel"
                class="h-6 w-full"
            ></div>

            <!-- Loading indicator for the next page -->
            <div
                v-if="infiniteScroll && isLoadingMore"
                class="mt-8 flex justify-center items-center gap-3 text-sm font-semibold text-emerald-800"
            >
                <svg class="animate-spin h-5 w-5 text-emerald-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                @lang('shop::app.components.products.grid.loading-more')
            </div>

            <!-- "View all" button (Jemla: no infinite grid / auto-load —
                 one fixed set of products per section, then a single
                 link to the full listing; never shown for the
                 infinite-scroll grid) -->
            <div
                v-if="navigationLink && ! infiniteScroll"
                class="mt-10 flex justify-center"
            >
                <a
                    :href="navigationLink"
                    class="inline-flex items-center gap-2 rounded-2xl bg-navyBlue px-10 py-3.5 text-center text-base font-bold text-white shadow-md transition hover:bg-black active:scale-[0.98] max-md:rounded-lg max-md:px-6 max-md:py-2.5 max-sm:text-sm"
                >
                    @lang('shop::app.components.products.grid.view-all')
                    <span class="icon-arrow-right rtl:icon-arrow-left text-lg"></span>
                </a>
            </div>
        </div>

        <!-- Initial Shimmer loader -->
        <template v-if="isLoading">
            <div class="container mt-20 max-lg:px-8 max-md:mt-8 max-sm:mt-7 max-sm:!px-4">
                <div
                    class="wa-products-grid-container"
                    :style="gridStyle"
                >
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
                    observer: null,
                    loadObserver: null,
                    hasLoaded: false,
                    emptyPages: 0,
                    infiniteScroll: {{ ($infiniteScroll ?? false) ? 'true' : 'false' }},
                    windowWidth: typeof window !== 'undefined' ? window.innerWidth : 1200,
                    desktopColumns: {{ $gridDesktopColumns }},
                    tabletColumns: {{ $gridTabletColumns }},
                    mobileColumns: {{ $gridMobileColumns }},
                    waEnabled: {{ core()->getConfigData('general.general.whatsapp.status') ? 'true' : 'false' }},
                    waPhone: '{{ preg_replace('/[^0-9]/', '', (string)core()->getConfigData('general.general.whatsapp.phone_number')) }}',
                    waProductMsg: '{{ addslashes(core()->getConfigData('general.general.whatsapp.product_message') ?: __('shop::app.components.products.grid.whatsapp-default-msg')) }}',
                    placeholderImage: "{{ bagisto_asset('images/medium-product-placeholder.webp', 'shop') }}",
                };
            },

            computed: {
                columnsCount() {
                    if (this.windowWidth <= 767) {
                        return this.mobileColumns;
                    }

                    if (this.windowWidth <= 1024) {
                        return this.tabletColumns;
                    }

                    return this.desktopColumns;
                },

                gridStyle() {
                    const gap = this.windowWidth <= 767 ? '12px' : (this.windowWidth <= 1024 ? '16px' : '20px');

                    return {
                        display: 'grid',
                        gridTemplateColumns: `repeat(${this.columnsCount}, minmax(0, 1fr))`,
                        gap: gap,
                        marginTop: this.windowWidth <= 767 ? '1rem' : '1.5rem',
                        width: '100%',
                    };
                },
            },

            created() {
                if (typeof window !== 'undefined') {
                    window.addEventListener('resize', this.updateWindowWidth);
                }
            },

            mounted() {
                this.injectGridStyles();
                this.initLazyLoad();

                if (typeof window !== 'undefined') {
                    window.addEventListener('scroll', this.onPageScroll, { passive: true });
                }
            },

            beforeUnmount() {
                if (typeof window !== 'undefined') {
                    window.removeEventListener('resize', this.updateWindowWidth);
                    window.removeEventListener('scroll', this.onPageScroll);
                }

                if (this.observer) {
                    this.observer.disconnect();
                }

                if (this.loadObserver) {
                    this.loadObserver.disconnect();
                }
            },

            methods: {
                updateWindowWidth() {
                    this.windowWidth = window.innerWidth;
                },

                injectGridStyles() {
                    if (typeof document === 'undefined') {
                        return;
                    }

                    if (! document.getElementById('wa-products-grid-global-style')) {
                        const style = document.createElement('style');
                        style.id = 'wa-products-grid-global-style';
                        style.innerHTML = `
                            .wa-products-grid-container {
                                display: grid !important;
                                grid-template-columns: repeat(${this.desktopColumns}, minmax(0, 1fr)) !important;
                                gap: 20px !important;
                                width: 100% !important;
                            }
                            @media (max-width: 1024px) and (min-width: 768px) {
                                .wa-products-grid-container {
                                    grid-template-columns: repeat(${this.tabletColumns}, minmax(0, 1fr)) !important;
                                    gap: 16px !important;
                                }
                            }
                            @media (max-width: 767px) {
                                .wa-products-grid-container {
                                    grid-template-columns: repeat(${this.mobileColumns}, minmax(0, 1fr)) !important;
                                    gap: 12px !important;
                                }
                            }
                        `;
                        document.head.appendChild(style);
                    }
                },

                initLazyLoad() {
                    /*
                        Jemla: like the related products, a section only
                        starts loading when it is near the viewport
                        (800px ahead, so it is filled in before the user
                        reaches it). The homepage used to fire one
                        listing request per section all at once on mount;
                        on shared hosting those queue behind each other
                        and the user waits for the slowest one before
                        seeing any product. Sections already in view
                        load immediately; without IntersectionObserver
                        the section loads at once, as before.
                    */
                    if (this.hasLoaded) {
                        return;
                    }

                    if (typeof IntersectionObserver === 'undefined' || ! this.$refs.loadTrigger) {
                        this.getProducts();

                        return;
                    }

                    this.loadObserver = new IntersectionObserver((entries) => {
                        if (entries[0] && entries[0].isIntersecting) {
                            this.loadObserver.disconnect();
                            this.loadObserver = null;

                            this.getProducts();
                        }
                    }, {
                        rootMargin: '800px 0px',
                        threshold: 0,
                    });

                    this.loadObserver.observe(this.$refs.loadTrigger);
                },

                getProducts() {
                    if (this.hasLoaded) {
                        return;
                    }

                    this.hasLoaded = true;

                    /*
                        Jemla: the infinite-scroll grid (related products)
                        loads its first page here and lets the scroll
                        sentinel load the rest — no cap, no button — until
                        the endpoint runs out of products. The fixed-count
                        grid (homepage sections) keeps the logic below.
                    */
                    const seen = window.__jemlaShownProductIds || (window.__jemlaShownProductIds = new Set());

                    if (this.infiniteScroll) {
                        this.$axios.get(this.src)
                            .then(response => {
                                this.isLoading = false;

                                this.products = (response.data.data || []).filter(product => {
                                    if (seen.has(product.id)) {
                                        return false;
                                    }

                                    seen.add(product.id);

                                    return true;
                                });

                                this.links = response.data.links || null;

                                this.$nextTick(() => {
                                    this.initObserver();
                                });
                            })
                            .catch(error => {
                                this.isLoading = false;
                                console.error(error);
                            });

                        return;
                    }

                    /*
                        Jemla: no infinite grid / auto-load — one fixed
                        set of products per section, in ONE request.
                        The count comes from the `limit` of the
                        section's own src URL (the admin homepage
                        limit, default 12). A section never shows a
                        product already shown by an earlier section,
                        so it may end up slightly short when its first
                        page overlaps one — that is accepted: chasing
                        further pages to fill the gap turned every
                        section into up to 4 sequential API requests
                        and made the homepage open very slowly on
                        phones (measured 2026-11-26: a cold listing
                        request takes ~3s on live).
                    */
                    let desired = 12;

                    try {
                        const limit = new URL(this.src, window.location.origin).searchParams.get('limit');

                        if (limit) {
                            desired = Math.min(48, Math.max(1, parseInt(limit, 10) || 12));
                        }
                    } catch (e) {}

                    this.$axios.get(this.src)
                        .then(response => {
                            const fresh = (response.data.data || []).filter(product => ! seen.has(product.id));
                            const kept = fresh.slice(0, desired);

                            kept.forEach(product => seen.add(product.id));

                            this.products = kept;
                            this.links = response.data.links || null;
                            this.isLoading = false;
                        })
                        .catch(error => {
                            this.isLoading = false;
                            console.error(error);
                        });
                },

                initObserver() {
                    /*
                        Jemla: infinite-scroll grid only (related products).
                        Restored from the pre-b7793a3 grid — b7793a3 removed
                        this observer along with the "load more" button and
                        capped every grid at a fixed count (12 for related,
                        whose src carries no `limit`), which is why related
                        products stopped growing on scroll. Unlike the old
                        version there is no auto-load cap and no button:
                        scrolling keeps loading until the endpoint ends.
                    */
                    if (this.observer) {
                        this.observer.disconnect();
                    }

                    if (! this.$refs.scrollSentinel) {
                        return;
                    }

                    this.observer = new IntersectionObserver((entries) => {
                        const entry = entries[0];

                        if (entry && entry.isIntersecting) {
                            if (this.links && this.links.next && ! this.isLoadingMore) {
                                this.loadMoreProducts();
                            }
                        }
                    }, {
                        rootMargin: '200px 0px',
                        threshold: 0.1,
                    });

                    this.observer.observe(this.$refs.scrollSentinel);
                },

                loadMoreProducts() {
                    if (! this.links || ! this.links.next || this.isLoadingMore) {
                        return;
                    }

                    this.isLoadingMore = true;

                    this.$axios.get(this.links.next)
                        .then(response => {
                            this.isLoadingMore = false;

                            /* Jemla: skip products already shown on this page (see getProducts). */
                            const seen = window.__jemlaShownProductIds || (window.__jemlaShownProductIds = new Set());

                            const fresh = (response.data.data || []).filter(product => {
                                if (seen.has(product.id)) {
                                    return false;
                                }

                                seen.add(product.id);

                                return true;
                            });

                            if (! fresh.length) {
                                /*
                                    The related endpoint shuffles
                                    (inRandomOrder), so a page can repeat
                                    products already shown. A few empty
                                    pages in a row means the pool is
                                    exhausted — stop instead of paging
                                    forever through repeats.
                                */
                                this.emptyPages++;

                                if (this.emptyPages >= 3) {
                                    this.links = null;

                                    if (this.observer) {
                                        this.observer.disconnect();
                                    }

                                    return;
                                }
                            } else {
                                this.emptyPages = 0;
                            }

                            this.products = [...this.products, ...fresh];
                            this.links = response.data.links || null;

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

                onPageScroll() {
                    /*
                        Jemla: infinite-scroll grid only. The sentinel
                        never intersects when the user jumps straight to
                        the bottom of the page (End key, fast flick):
                        the grid ends above the FAQ/footer, so the
                        sentinel sits above the viewport and no more
                        pages would ever load. Near the bottom of the
                        page, load the next page directly.
                    */
                    if (! this.infiniteScroll || this.isLoading || this.isLoadingMore) {
                        return;
                    }

                    if (! this.links || ! this.links.next) {
                        return;
                    }

                    const nearBottom = (document.documentElement.scrollHeight - window.scrollY - window.innerHeight) < 100;

                    if (nearBottom) {
                        this.loadMoreProducts();
                    }
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

                /*
                    Jemla: when a product image file is missing (e.g. demo
                    products whose files were never uploaded), the browser
                    renders the <img> alt text (the long product name) on top
                    of the image box, colliding with the badges. Swap the
                    failed image for the placeholder instead.
                */
                onImageError(event) {
                    const target = event.target;

                    if (target.src !== this.placeholderImage) {
                        target.src = this.placeholderImage;
                    }
                },
            },
        });
    </script>
@endPushOnce
