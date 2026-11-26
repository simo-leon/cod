<v-categories-carousel
    src="{{ $src }}"
    title="{{ $title }}"
    navigation-link="{{ $navigationLink ?? '' }}"
>
    <x-shop::shimmer.categories.carousel
        :count="8"
        :navigation-link="$navigationLink ?? false"
    />
</v-categories-carousel>

@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-categories-carousel-template"
    >
        <div
            class="container mt-14 max-lg:px-8 max-md:mt-7 max-md:!px-0 max-sm:mt-5"
            v-if="! isLoading && categories?.length"
        >
            <!--
                Jemla: the section title used to be dropped — the
                homepage passes it, but this template never rendered
                it (and the admin form had no title field at all).
            -->
            <div
                class="mb-8 flex justify-between max-md:mb-5"
                v-if="title || navigationLink"
            >
                <h2
                    class="font-dmserif text-3xl max-md:text-2xl max-sm:text-xl"
                    v-if="title"
                >
                    @{{ title }}
                </h2>

                <a
                    :href="navigationLink"
                    class="flex items-center gap-1 text-xl max-md:text-base max-sm:text-sm"
                    v-if="navigationLink"
                >
                    @lang('shop::app.components.products.carousel.view-all')

                    <span class="icon-arrow-right rtl:icon-arrow-left text-2xl max-md:text-lg max-sm:text-sm"></span>
                </a>
            </div>

            <div class="relative">
                <div
                    ref="swiperContainer"
                    class="scrollbar-hide flex gap-10 overflow-auto scroll-smooth max-lg:gap-4"
                >
                    <div
                        class="grid min-w-[120px] max-w-[120px] grid-cols-1 justify-items-center gap-4 font-medium max-md:min-w-20 max-md:max-w-20 max-md:gap-2.5 max-md:first:ml-4 max-sm:min-w-[60px] max-sm:max-w-[60px] max-sm:gap-1.5"
                        v-for="category in categories"
                    >
                        <a
                            :href="category.slug"
                            class="h-[110px] w-[110px] rounded-full bg-zinc-100 max-md:h-20 max-md:w-20 max-sm:h-[60px] max-sm:w-[60px]"
                            :aria-label="category.name"
                        >
                            <x-shop::media.images.lazy
                                ::src="category.logo?.small_image_url || fallback"
                                ::srcset="`
                                    ${(category.logo?.small_image_url || fallback)} 60w,
                                    ${(category.logo?.medium_image_url || fallback)} 110w,
                                    ${(category.logo?.large_image_url || fallback)} 300w
                                `"
                                sizes="(max-width: 640px) 60px, 110px"
                                width="110"
                                height="110"
                                class="w-full rounded-full max-sm:h-[60px] max-sm:w-[60px]"
                                ::alt="category.name"
                            />
                        </a>

                        <a
                            :href="category.slug"
                            class=""
                        >
                            <p
                                class="text-center text-lg text-black max-md:text-base max-md:font-normal max-sm:text-sm"
                                v-text="category.name"
                            >
                            </p>
                        </a>
                    </div>
                </div>

                <span
                    class="icon-arrow-left-stylish absolute -left-10 top-9 flex h-[50px] w-[50px] cursor-pointer items-center justify-center rounded-full border border-black bg-white text-2xl transition hover:bg-black hover:text-white max-lg:-left-7 max-md:hidden"
                    role="button"
                    aria-label="@lang('shop::components.carousel.previous')"
                    tabindex="0"
                    @click="swipeLeft"
                >
                </span>

                <span
                    class="icon-arrow-right-stylish absolute -right-6 top-9 flex h-[50px] w-[50px] cursor-pointer items-center justify-center rounded-full border border-black bg-white text-2xl transition hover:bg-black hover:text-white max-lg:-right-7 max-md:hidden"
                    role="button"
                    aria-label="@lang('shop::components.carousel.next')"
                    tabindex="0"
                    @click="swipeRight"
                >
                </span>
            </div>
        </div>

        <!-- Category Carousel Shimmer -->
        <template v-if="isLoading">
            <x-shop::shimmer.categories.carousel
                :count="8"
                :navigation-link="$navigationLink ?? false"
            />
        </template>
    </script>

    <script type="module">
        app.component('v-categories-carousel', {
            template: '#v-categories-carousel-template',

            props: [
                'src',
                'title',
                'navigationLink',
            ],

            data() {
                return {
                    isLoading: true,

                    categories: [],

                    offset: 323,

                    fallback: "{{ bagisto_asset('images/small-product-placeholder.webp') }}"
                };
            },

            mounted() {
                this.getCategories();
            },

            methods: {
                getCategories() {
                    this.$axios.get(this.src)
                        .then(response => {
                            this.isLoading = false;

                            this.categories = response.data.data;
                        }).catch(error => {
                            console.log(error);
                        });
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

                    // Scroll to the next categories (negative in RTL).
                    container.scrollLeft += (document.dir === 'rtl' ? -1 : 1) * this.offset;
                },
            },
        });
    </script>
@endPushOnce
