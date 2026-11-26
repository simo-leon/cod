<v-products-carousel
    src="{{ $src }}"
    title="{{ $title }}"
    navigation-link="{{ $navigationLink ?? '' }}"
>
    <x-shop::shimmer.products.carousel :navigation-link="$navigationLink ?? false" />
</v-products-carousel>

@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-products-carousel-template"
    >
        <div
            class="container mt-20 max-lg:px-8 max-md:mt-8 max-sm:mt-7 max-sm:!px-4"
            v-if="! isLoading && products.length"
        >
            <div class="flex justify-between">
                <h2 class="font-dmserif text-3xl max-md:text-2xl max-sm:text-xl">
                    @{{ title }}
                </h2>

                <div class="flex items-center justify-between gap-8">
                    <a
                        :href="navigationLink"
                        class="hidden max-lg:flex"
                        v-if="navigationLink"
                    >
                        <p class="items-center text-xl max-md:text-base max-sm:text-sm">
                            @lang('shop::app.components.products.carousel.view-all')

                            <span class="icon-arrow-right text-2xl max-md:text-lg max-sm:text-sm"></span>
                        </p>
                    </a>

                    <template v-if="products.length > 3">
                        <span
                            v-if="products.length > 4 || (products.length > 3 && isScreenMax2xl)"
                            class="icon-arrow-left-stylish rtl:icon-arrow-right-stylish inline-block cursor-pointer text-2xl max-lg:hidden"
                            role="button"
                            aria-label="@lang('shop::app.components.products.carousel.previous')"
                            tabindex="0"
                            @click="swipeLeft"
                        >
                        </span>

                        <span
                            v-if="products.length > 4 || (products.length > 3 && isScreenMax2xl)"
                            class="icon-arrow-right-stylish rtl:icon-arrow-left-stylish inline-block cursor-pointer text-2xl max-lg:hidden"
                            role="button"
                            aria-label="@lang('shop::app.components.products.carousel.next')"
                            tabindex="0"
                            @click="swipeRight"
                        >
                        </span>
                    </template>
                </div>
            </div>

            <div
                ref="swiperContainer"
                class="flex gap-8 pb-2.5 [&>*]:flex-[0] mt-10 overflow-auto scroll-smooth scrollbar-hide max-md:gap-7 max-md:mt-5 max-sm:gap-4 max-md:pb-0 max-md:whitespace-nowrap"
            >
                <x-shop::products.card
                    class="min-w-[291px] max-md:h-fit max-md:min-w-56 max-sm:min-w-[192px]"
                    v-for="product in products"
                />
            </div>

            <a
                :href="navigationLink"
                class="secondary-button mx-auto mt-5 block w-max rounded-2xl px-11 py-3 text-center text-base max-lg:mt-0 max-lg:hidden max-lg:py-3.5 max-md:rounded-lg"
                :aria-label="title"
                v-if="navigationLink"
            >
                @lang('shop::app.components.products.carousel.view-all')
            </a>
        </div>

        <!-- Product Card Listing -->
        <template v-if="isLoading">
            <x-shop::shimmer.products.carousel :navigation-link="$navigationLink ?? false" />
        </template>
    </script>

    <script type="module">
        app.component('v-products-carousel', {
            template: '#v-products-carousel-template',

            props: [
                'src',
                'title',
                'navigationLink',
            ],

            data() {
                return {
                    isLoading: true,

                    products: [],

                    offset: 323,

                    isScreenMax2xl: window.innerWidth <= 1440,
                };
            },

            mounted() {
                this.getProducts();
            },

            created() {
                window.addEventListener('resize', this.updateScreenSize);
            },

            beforeDestroy() {
                window.removeEventListener('resize', this.updateScreenSize);
            },

            methods: {
                getProducts() {
                    /*
                        Jemla: never show the same product twice on one
                        homepage. Sections are separate components that
                        fetch their own lists, so keep a page-level
                        registry of product ids already displayed and
                        drop duplicates (e.g. "Nouveautés" vs "Nos Promos"
                        vs a category section showing the same products).
                        Because the API paginates (the `limit` filter
                        follows the storefront per-page values), keep
                        fetching following pages until this section is
                        full, so a section never ends up almost empty
                        just because its first page overlapped an
                        earlier section. Only ids actually displayed are
                        registered, so products skipped here can still
                        appear in a later section.
                    */
                    const seen = window.__jemlaShownProductIds || (window.__jemlaShownProductIds = new Set());

                    let desired = 12;

                    try {
                        const limit = new URL(this.src, window.location.origin).searchParams.get('limit');
                        if (limit) {
                            desired = Math.max(1, parseInt(limit, 10) || 12);
                        }
                    } catch (e) {}

                    const loadPage = (url, pagesLeft) => {
                        this.$axios.get(url)
                            .then(response => {
                                const fresh = (response.data.data || []).filter(product => ! seen.has(product.id));
                                const needed = desired - this.products.length;
                                const kept = fresh.slice(0, needed);

                                kept.forEach(product => seen.add(product.id));

                                this.products = [...this.products, ...kept];

                                const next = response.data.links && response.data.links.next;

                                if (this.products.length < desired && next && pagesLeft > 0) {
                                    loadPage(next, pagesLeft - 1);
                                } else {
                                    this.isLoading = false;
                                }
                            }).catch(error => {
                                this.isLoading = false;
                                console.log(error);
                            });
                    };

                    loadPage(this.src, 3);
                },

                updateScreenSize() {
                    this.isScreenMax2xl = window.innerWidth <= 1440;
                },

                swipeLeft() {
                    const container = this.$refs.swiperContainer;

                    /*
                        Jemla: in RTL (Arabic) scrollLeft starts at 0
                        and goes negative, so "previous" scrolls the
                        opposite way from LTR.
                    */
                    container.scrollLeft += (document.dir === 'rtl' ? 1 : -1) * this.offset;
                },

                swipeRight() {
                    const container = this.$refs.swiperContainer;

                    // Distance already scrolled from the start, either direction.
                    const scrolled = Math.abs(container.scrollLeft);

                    // Check if scroll reaches the end
                    if (scrolled + container.clientWidth >= container.scrollWidth - 1) {
                        // Reset scroll to the beginning
                        container.scrollLeft = 0;
                    } else {
                        // Scroll to the next products (negative in RTL)
                        container.scrollLeft += (document.dir === 'rtl' ? -1 : 1) * this.offset;
                    }
                },
            },
        });
    </script>
@endPushOnce
