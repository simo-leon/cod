@props([
    'hasHeader'  => true,
    'hasFeature' => true,
    'hasFooter'  => true,
])

<!DOCTYPE html>

<html
    lang="{{ app()->getLocale() }}"
    dir="{{ core()->getCurrentLocale()->direction }}"
>
    <head>

        {!! view_render_event('bagisto.shop.layout.head.before') !!}

        <title>{{ $title ?? '' }}</title>

        <meta charset="UTF-8">

        <meta
            http-equiv="X-UA-Compatible"
            content="IE=edge"
        >
        <meta
            http-equiv="content-language"
            content="{{ app()->getLocale() }}"
        >

        <meta
            name="viewport"
            content="width=device-width, initial-scale=1"
        >
        <meta
            name="base-url"
            content="{{ url()->to('/') }}"
        >
        <meta
            name="currency"
            content="{{ core()->getCurrentCurrency()->toJson() }}"
        >
        <meta 
            name="generator" 
            content="Bagisto"
        >

        @stack('meta')

        <link
            rel="icon"
            sizes="16x16"
            href="{{ core()->getCurrentChannel()->favicon_url ?? bagisto_asset('images/favicon.ico') }}"
        />

        @bagistoVite(['src/Resources/assets/css/app.css', 'src/Resources/assets/js/app.js'])

        <link
            rel="preconnect"
            href="https://fonts.googleapis.com"
            crossorigin
        />

        <link
            rel="preconnect"
            href="https://fonts.gstatic.com"
            crossorigin
        />

        <link
            rel="preload" as="style"
            href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=DM+Serif+Display&display=swap"
        />

        <link
            rel="stylesheet"
            href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=DM+Serif+Display&display=swap"
        />

        @stack('styles')

        <style>
            {!! core()->getConfigData('general.content.custom_scripts.custom_css') !!}
        </style>

        @if(core()->getConfigData('general.content.speculation_rules.enabled'))
            <script type="speculationrules">
                @json(core()->getSpeculationRules(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            </script>
        @endif

        {!! view_render_event('bagisto.shop.layout.head.after') !!}

    </head>

    <body>
        {!! view_render_event('bagisto.shop.layout.body.before') !!}

        <a
            href="#main"
            class="skip-to-main-content-link"
        >
            Skip to main content
        </a>

        <!-- Built With Bagisto -->
        <div id="app">
            <!-- Flash Message Blade Component -->
            <x-shop::flash-group />

            <!-- Confirm Modal Blade Component -->
            <x-shop::modal.confirm />

            <!-- Page Header Blade Component -->
            @if ($hasHeader)
                <x-shop::layouts.header />
            @endif

            @if(
                core()->getConfigData('general.gdpr.settings.enabled')
                && core()->getConfigData('general.gdpr.cookie.enabled')
            )
                <x-shop::layouts.cookie />
            @endif

            {!! view_render_event('bagisto.shop.layout.content.before') !!}

            <!-- Page Content Blade Component -->
            <main id="main" class="bg-white">
                {{ $slot }}
            </main>

            {!! view_render_event('bagisto.shop.layout.content.after') !!}


            <!-- Page Services Blade Component -->
            @if ($hasFeature)
                <x-shop::layouts.services />
            @endif

            <!-- Page Footer Blade Component -->
            @if ($hasFooter)
                <x-shop::layouts.footer />
            @endif
        </div>

        {!! view_render_event('bagisto.shop.layout.body.after') !!}

        @if (core()->getConfigData('general.general.whatsapp.status') && core()->getConfigData('general.general.whatsapp.enable_store_widget'))
            @php
                $waWidgetPhone = preg_replace('/[^0-9]/', '', (string)core()->getConfigData('general.general.whatsapp.phone_number'));
                $waProductMsg = core()->getConfigData('general.general.whatsapp.product_message') ?: __('shop::app.products.view.whatsapp-default-message');
            @endphp

            @if ($waWidgetPhone)
                <!-- Floating WhatsApp Widget -->
                <div
                    id="wa-floating-widget"
                    style="position:fixed;bottom:24px;{{ core()->getCurrentLocale()->direction === 'rtl' ? 'left' : 'right' }}:24px;z-index:9999;font-family:inherit;"
                >
                    <!-- Chat Popup -->
                    <div
                        id="wa-popup"
                        style="display:none;position:absolute;bottom:72px;{{ core()->getCurrentLocale()->direction === 'rtl' ? 'left' : 'right' }}:0;width:340px;max-width:calc(100vw - 48px);background:#fff;border-radius:16px;box-shadow:0 8px 32px rgba(0,0,0,0.18);overflow:hidden;animation:waSlideUp 0.3s ease-out;"
                    >
                        <div style="background:#25D366;padding:16px 20px;display:flex;align-items:center;justify-content:space-between;">
                            <div style="display:flex;align-items:center;gap:10px;">
                                <svg width="28" height="28" viewBox="0 0 24 24" fill="#fff"><path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/></svg>
                                <div>
                                    <div style="color:#fff;font-weight:700;font-size:15px;">@lang('shop::app.components.layouts.whatsapp.title')</div>
                                    <div style="color:rgba(255,255,255,0.85);font-size:12px;">@lang('shop::app.components.layouts.whatsapp.subtitle')</div>
                                </div>
                            </div>
                            <button onclick="document.getElementById('wa-popup').style.display='none'" style="background:none;border:none;color:#fff;font-size:22px;cursor:pointer;padding:0;line-height:1;">&times;</button>
                        </div>
                        <div style="padding:20px;">
                            <p style="color:#555;font-size:13px;margin:0 0 10px 0;line-height:1.5;">
                                @lang('shop::app.components.layouts.whatsapp.prompt')
                            </p>
                            <textarea
                                id="wa-msg-input"
                                rows="3"
                                placeholder="@lang('shop::app.components.layouts.whatsapp.placeholder')"
                                style="width:100%;border:1px solid #e0e0e0;border-radius:10px;padding:10px 12px;font-size:13px;resize:none;outline:none;font-family:inherit;box-sizing:border-box;direction:rtl;"
                                onfocus="this.style.borderColor='#25D366'"
                                onblur="this.style.borderColor='#e0e0e0'"
                            ></textarea>

                            <div style="margin-top:8px;padding:6px 10px;background:#f0fdf4;border:1px dashed #86efac;border-radius:8px;font-size:11px;color:#166534;display:flex;align-items:center;gap:6px;">
                                <span>🔗</span>
                                <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;direction:ltr;flex:1;" id="wa-page-preview"></span>
                            </div>

                            <a
                                id="wa-send-btn"
                                href="#"
                                onclick="window.sendWaWidgetMessage && window.sendWaWidgetMessage(); return false;"
                                style="display:flex;align-items:center;justify-content:center;gap:8px;width:100%;margin-top:12px;padding:12px;background:#25D366;color:#fff;border-radius:10px;font-weight:700;font-size:14px;text-decoration:none;text-align:center;box-sizing:border-box;transition:background 0.2s;"
                                onmouseover="this.style.background='#1da851'"
                                onmouseout="this.style.background='#25D366'"
                            >
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="#fff"><path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/></svg>
                                @lang('shop::app.components.layouts.whatsapp.send')
                            </a>
                        </div>
                    </div>

                    <!-- Floating Button -->
                    <button
                        onclick="var p=document.getElementById('wa-popup');var isOpening=p.style.display==='none'||!p.style.display;p.style.display=isOpening?'block':'none';if(isOpening){var pr=document.getElementById('wa-page-preview');if(pr){pr.innerText=window.location.href;}}"
                        style="width:60px;height:60px;border-radius:50%;background:#25D366;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 16px rgba(37,211,102,0.4);transition:transform 0.2s,box-shadow 0.2s;"
                        onmouseover="this.style.transform='scale(1.1)';this.style.boxShadow='0 6px 24px rgba(37,211,102,0.5)'"
                        onmouseout="this.style.transform='scale(1)';this.style.boxShadow='0 4px 16px rgba(37,211,102,0.4)'"
                        aria-label="WhatsApp"
                    >
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="#fff"><path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/></svg>
                    </button>
                </div>

                <script>
                    window.sendWaWidgetMessage = function () {
                        var input = (document.getElementById('wa-msg-input') ? document.getElementById('wa-msg-input').value : '').trim();
                        var base = @json($waProductMsg);
                        var pageUrl = window.location.href;
                        var pageTitle = document.title;
                        var finalMsg = (input ? input : base) + '\n\n📌 ' + pageTitle + '\n🔗 ' + pageUrl;
                        window.open('https://api.whatsapp.com/send?phone={{ $waWidgetPhone }}&text=' + encodeURIComponent(finalMsg), '_blank');
                    };
                </script>

                <style>
                    @keyframes waSlideUp {
                        from { opacity: 0; transform: translateY(16px); }
                        to { opacity: 1; transform: translateY(0); }
                    }

                    /* Jemla: on phone format, lift the WhatsApp button
                       above the sticky "Passer la commande" bar on the
                       checkout page so it never covers the order button.
                       (!important wins over the inline bottom:24px.) */
                    @media (max-width: 768px) {
                        #wa-floating-widget {
                            bottom: 96px !important;
                        }
                    }
                </style>
            @endif
        @endif

        @stack('scripts')

        {!! view_render_event('bagisto.shop.layout.vue-app-mount.before') !!}
        <script>
            /**
             * Mount the application as soon as the DOM is ready instead of waiting
             * for the `load` event. All `Vue` components are registered through
             * deferred `type="module"` scripts, which always finish executing
             * before `DOMContentLoaded` fires, so every component is available
             * by the time `app.mount()` runs. Mounting on `DOMContentLoaded`
             * avoids blocking the storefront behind every image/font download.
             */
            function mountApp() {
                app.mount("#app");
            }

            if (document.readyState === "loading") {
                document.addEventListener("DOMContentLoaded", mountApp);
            } else {
                mountApp();
            }
        </script>

        {!! view_render_event('bagisto.shop.layout.vue-app-mount.after') !!}

        <script type="text/javascript">
            {!! core()->getConfigData('general.content.custom_scripts.custom_javascript') !!}
        </script>
    </body>
</html>
