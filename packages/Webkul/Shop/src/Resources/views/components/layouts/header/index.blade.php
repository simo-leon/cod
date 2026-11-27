{!! view_render_event('bagisto.shop.layout.header.before') !!}

@if(core()->getCurrentChannel()->locales()->count() > 1 || core()->getCurrentChannel()->currencies()->count() > 1 )
    <div class="max-lg:hidden">
        <x-shop::layouts.header.desktop.top />
    </div>
@endif

<header class="shadow-gray sticky top-0 z-10 bg-white shadow-sm max-lg:shadow-none">
    <v-header-switcher>
        <!-- Desktop Header Shimmer -->
        <div class="flex flex-wrap max-lg:hidden">
            <div class="flex min-h-[78px] w-full justify-between border border-b border-l-0 border-r-0 border-t-0 px-[60px] max-1180:px-8">
                <!-- Left Navigation Section -->
                <div class="flex items-center gap-x-10 max-[1180px]:gap-x-5">
                    <!-- Logo Shimmer -->
                    <span
                        class="shimmer block h-[29px] w-[131px] rounded"
                        role="presentation"
                    >
                    </span>

                    <!-- Categories Shimmer -->
                    <div class="flex items-center gap-5">
                        <span
                            class="shimmer h-6 w-20 rounded"
                            role="presentation"
                        >
                        </span>

                        <span
                            class="shimmer h-6 w-20 rounded"
                            role="presentation"
                        >
                        </span>

                        <span
                            class="shimmer h-6 w-20 rounded"
                            role="presentation"
                        >
                        </span>
                    </div>
                </div>

                <!-- Right Navigation Section -->
                <div class="flex items-center gap-x-9 max-[1100px]:gap-x-6 max-lg:gap-x-8">
                    <!-- Search Bar Shimmer -->
                    <div class="relative w-full max-w-[445px]">
                        <span
                            class="shimmer block h-[42px] w-[250px] rounded-lg px-11 py-3"
                            role="presentation"
                        >
                        </span>
                    </div>

                    <!-- Right Navigation Icons Shimmer -->
                    <div class="mt-1.5 flex gap-x-8 max-[1100px]:gap-x-6 max-lg:gap-x-8">
                        <!-- Compare Icon Shimmer -->
                        <span
                            class="shimmer h-6 w-6 rounded"
                            role="presentation"
                        >
                        </span>

                        <!-- Cart Icon Shimmer -->
                        <span
                            class="shimmer h-6 w-6 rounded"
                            role="presentation"
                        >
                        </span>

                        <!-- Profile Icon Shimmer -->
                        <span
                            class="shimmer h-6 w-6 rounded"
                            role="presentation"
                        >
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Mobile Header Shimmer -->
        <div class="flex flex-wrap gap-4 px-4 pb-4 pt-6 shadow-sm lg:hidden">
            <div class="flex w-full items-center justify-between">
                <!-- Left Navigation -->
                <div class="flex items-center gap-x-1.5">
                    <!-- Hamburger Menu Shimmer -->
                    <span 
                        class="shimmer block h-6 w-6 rounded" 
                        role="presentation"
                    >
                    </span>
                    
                    <!-- Logo Shimmer -->
                    <span 
                        class="shimmer block h-[29px] w-[131px] rounded" 
                        role="presentation"
                    >
                    </span>
                </div>

                <!-- Right Navigation Icons -->
                <div class="flex items-center gap-x-5 max-md:gap-x-4">
                    <!-- Compare Icon Shimmer -->
                    <span 
                        class="shimmer block h-6 w-6 rounded" 
                        role="presentation"
                    >
                    </span>
                    
                    <!-- Cart Icon Shimmer -->
                    <span 
                        class="shimmer block h-6 w-6 rounded" 
                        role="presentation"
                    >
                    </span>
                    
                    <!-- Profile Icon Shimmer -->
                    <span 
                        class="shimmer block h-6 w-6 rounded" 
                        role="presentation"
                    >
                    </span>
                </div>
            </div>

            <!-- Search Bar Shimmer -->
            <div class="flex w-full items-center">
                <div class="relative w-full">
                    <span
                        class="shimmer block h-[42px] w-full rounded-xl px-11 py-3.5 max-md:rounded-lg"
                        role="presentation"
                    >
                    </span>
                </div>
            </div>
        </div>
    </v-header-switcher>
</header>

{!! view_render_event('bagisto.shop.layout.header.after') !!}

@pushOnce('scripts')
    <script 
        type="text/x-template" 
        id="v-header-switcher-template"
    >
        <v-desktop-header v-if="isDesktop"></v-desktop-header>
        
        <v-mobile-header v-else></v-mobile-header>
    </script>

    <script type="module">
        app.component('v-header-switcher', {
            template: '#v-header-switcher-template',

            data() {
                return {
                    isDesktop: window.innerWidth >= 1024
                }
            },

            mounted() {
                this.media = window.matchMedia('(min-width: 1024px)');

                this.media.addEventListener('change', this.handleMedia);
            },

            beforeUnmount() {
                this.media.removeEventListener('change', this.handleMedia);
            },

            methods: {
                handleMedia(e) {
                    this.isDesktop = e.matches;
                }
            }
        });

        app.component('v-desktop-header', {
            template: '#v-desktop-header-template'
        });

        app.component('v-mobile-header', {
            template: '#v-mobile-header-template'
        });
    </script>

    <script 
        type="text/x-template" 
        id="v-desktop-header-template"
    >
        <x-shop::layouts.header.desktop />
    </script>

    <script 
        type="text/x-template" 
        id="v-mobile-header-template"
    >
        <x-shop::layouts.header.mobile />
    </script>
@endPushOnce

{{--
    Jemla: header search autocomplete (desktop + mobile inputs carry
    `data-jemla-autocomplete`). Plain CSS + vanilla JS on purpose: the
    search forms are plain HTML, not Vue, and this needs no Vite rebuild
    — the dropdown styles live here so they do not depend on which
    Tailwind classes happen to be in the built CSS. Uses the existing
    `GET /api/products?query=...` (limit 8); `suggest=1` only stops the
    controller recording half-typed queries as search terms. Debounce
    300ms + AbortController so requests never pile up on the slow API.
--}}
@pushOnce('scripts')
    <style>
        .jemla-suggest{position:absolute;top:calc(100% + 6px);left:0;right:0;z-index:60;display:none;max-height:360px;overflow-y:auto;background:#fff;border:1px solid #e4e4e7;border-radius:12px;box-shadow:0 12px 32px rgba(0,0,0,.16)}
        .jemla-suggest.jemla-open{display:block}
        .jemla-suggest-item{display:flex;align-items:center;gap:10px;padding:8px 12px;color:#18181b;text-decoration:none}
        .jemla-suggest-item:hover,.jemla-suggest-item.jemla-active{background:#f4f4f5}
        .jemla-suggest-item img{width:40px;height:40px;flex:none;object-fit:cover;border-radius:8px;background:#f4f4f5}
        .jemla-suggest-name{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13px;line-height:1.35}
        .jemla-suggest-price{flex:none;font-size:13px;font-weight:600;white-space:nowrap}
    </style>

    <script>
        (function () {
            'use strict';

            /* Same-origin relative path: the route URL is absolute, and
               APP_URL may differ from the host the shopper is on. */
            var SUGGEST_URL = "{{ route('shop.api.products.index') }}".replace(/^https?:\/\/[^/]+/, '');
            var MIN_CHARS = 2;
            var DEBOUNCE_MS = 300;
            var LIMIT = 8;
            var suggestCounter = 0;

            function attach(input) {
                if (input.dataset.jemlaSuggestBound) {
                    return;
                }

                input.dataset.jemlaSuggestBound = '1';

                var host = input.closest('.relative') || input.parentElement;

                if (window.getComputedStyle(host).position === 'static') {
                    host.style.position = 'relative';
                }

                suggestCounter += 1;

                var box = document.createElement('div');
                box.className = 'jemla-suggest';
                box.id = 'jemla-suggest-' + suggestCounter;
                box.setAttribute('role', 'listbox');
                host.appendChild(box);

                input.setAttribute('aria-controls', box.id);
                input.setAttribute('aria-expanded', 'false');
                input.setAttribute('autocomplete', 'off');

                var timer = null;
                var controller = null;
                var requestSeq = 0;
                var items = [];
                var activeIndex = -1;

                function hide() {
                    box.classList.remove('jemla-open');
                    box.innerHTML = '';
                    items = [];
                    activeIndex = -1;
                    input.setAttribute('aria-expanded', 'false');
                    input.removeAttribute('aria-activedescendant');
                }

                function setActive(index) {
                    var links = box.querySelectorAll('.jemla-suggest-item');

                    links.forEach(function (link) {
                        link.classList.remove('jemla-active');
                    });

                    activeIndex = index;

                    if (index >= 0 && links[index]) {
                        links[index].classList.add('jemla-active');
                        input.setAttribute('aria-activedescendant', links[index].id);
                    } else {
                        input.removeAttribute('aria-activedescendant');
                    }
                }

                function render(products) {
                    box.innerHTML = '';
                    items = [];
                    activeIndex = -1;
                    input.removeAttribute('aria-activedescendant');

                    if (! products.length) {
                        hide();

                        return;
                    }

                    products.forEach(function (product, index) {
                        if (! product.url_key) {
                            return;
                        }

                        var link = document.createElement('a');
                        link.className = 'jemla-suggest-item';
                        link.id = box.id + '-item-' + index;
                        link.setAttribute('role', 'option');
                        link.href = '/' + product.url_key;

                        var image = product.base_image || {};
                        var thumb = image.small_image_url || image.medium_image_url || '';

                        if (thumb) {
                            var img = document.createElement('img');
                            img.src = thumb;
                            img.alt = '';
                            img.width = 40;
                            img.height = 40;
                            img.loading = 'lazy';
                            img.onerror = function () {
                                img.remove();
                            };
                            link.appendChild(img);
                        }

                        var name = document.createElement('span');
                        name.className = 'jemla-suggest-name';
                        name.textContent = product.name || '';
                        link.appendChild(name);

                        if (product.min_price) {
                            var price = document.createElement('span');
                            price.className = 'jemla-suggest-price';
                            price.textContent = product.min_price;
                            link.appendChild(price);
                        }

                        /* Keep focus on the input so the dropdown is not
                           removed before the click on the link fires. */
                        link.addEventListener('mousedown', function (event) {
                            event.preventDefault();
                        });

                        box.appendChild(link);
                        items.push({ url: '/' + product.url_key });
                    });

                    if (! items.length) {
                        hide();

                        return;
                    }

                    box.classList.add('jemla-open');
                    input.setAttribute('aria-expanded', 'true');
                }

                function fetchSuggestions(query, seq) {
                    if (controller) {
                        controller.abort();
                    }

                    controller = new AbortController();

                    var url = SUGGEST_URL + '?query=' + encodeURIComponent(query)
                        + '&limit=' + LIMIT + '&suggest=1';

                    fetch(url, { signal: controller.signal })
                        .then(function (response) {
                            return response.json();
                        })
                        .then(function (payload) {
                            if (seq !== requestSeq) {
                                return;
                            }

                            var list = (payload && payload.data) || [];

                            if (input.value.trim() !== query) {
                                return;
                            }

                            render(list);
                        })
                        .catch(function (error) {
                            if (error && error.name === 'AbortError') {
                                return;
                            }

                            hide();
                        });
                }

                function schedule() {
                    if (timer) {
                        clearTimeout(timer);
                    }

                    var query = input.value.trim();

                    if (query.length < MIN_CHARS) {
                        if (controller) {
                            controller.abort();
                        }

                        hide();

                        return;
                    }

                    timer = setTimeout(function () {
                        requestSeq += 1;
                        fetchSuggestions(query, requestSeq);
                    }, DEBOUNCE_MS);
                }

                input.addEventListener('input', schedule);

                input.addEventListener('focus', function () {
                    if (! box.classList.contains('jemla-open') && input.value.trim().length >= MIN_CHARS) {
                        schedule();
                    }
                });

                input.addEventListener('keydown', function (event) {
                    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                        if (! items.length) {
                            return;
                        }

                        event.preventDefault();

                        var next = event.key === 'ArrowDown' ? activeIndex + 1 : activeIndex - 1;

                        if (next >= items.length) {
                            next = 0;
                        }

                        if (next < 0) {
                            next = items.length - 1;
                        }

                        setActive(next);
                    } else if (event.key === 'Enter') {
                        if (activeIndex >= 0 && items[activeIndex]) {
                            event.preventDefault();
                            window.location.href = items[activeIndex].url;
                        }
                    } else if (event.key === 'Escape') {
                        hide();
                    }
                });

                document.addEventListener('click', function (event) {
                    if (! host.contains(event.target)) {
                        hide();
                    }
                });

                var form = input.closest('form');

                if (form) {
                    form.addEventListener('submit', hide);
                }
            }

            function scan(root) {
                if (! root || ! root.querySelectorAll) {
                    return;
                }

                root.querySelectorAll('input[data-jemla-autocomplete]').forEach(attach);
            }

            function init() {
                scan(document);

                /* The header is rendered by Vue (v-if desktop/mobile), so
                   the input only exists after mount and is replaced on a
                   desktop/mobile resize — watch for it appearing. */
                if (window.MutationObserver) {
                    var observer = new MutationObserver(function (mutations) {
                        mutations.forEach(function (mutation) {
                            mutation.addedNodes.forEach(function (node) {
                                if (node.nodeType !== 1) {
                                    return;
                                }

                                if (node.matches && node.matches('input[data-jemla-autocomplete]')) {
                                    attach(node);
                                }

                                scan(node);
                            });
                        });
                    });

                    observer.observe(document.body || document.documentElement, {
                        childList: true,
                        subtree: true,
                    });
                }
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', init);
            } else {
                init();
            }
        })();
    </script>
@endPushOnce
