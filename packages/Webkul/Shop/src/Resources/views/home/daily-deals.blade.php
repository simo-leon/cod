{{--
    Daily deals / flash sale on the homepage.
    Server-rendered: products Simo marked for the daily deals whose
    special price (the deal price) is below their regular price,
    biggest discount first. The countdown ticks down to midnight
    tonight (Africa/Casablanca); tomorrow it is a new day with a
    new countdown. When the rendered end time is already past (a
    stale cached page), the countdown rolls forward a day at a time
    instead of showing 00:00:00.
--}}
@php
    $dealsService = app(\App\Services\Promotions\PromotionServiceInterface::class);
    $dealProducts = $dealsService->dealProducts();
    $dealsEndsAt = $dealsService->dealsEndsAt();
    $dealsTitle = $dealsService->dealsTitle() ?? trans('shop::promotions.deals_title');
@endphp

@if ($dealProducts)
    <section
        class="container mt-8 px-[60px] max-1180:px-0 max-lg:px-5 max-sm:px-4"
        id="daily-deals-section"
    >
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-2xl font-medium max-sm:text-xl">
                {{ $dealsTitle }}
            </h2>

            <div class="flex items-center gap-2 text-sm text-zinc-600">
                <span>@lang('shop::promotions.deals_ends_in')</span>

                <span
                    id="deals-countdown"
                    data-ends-at="{{ $dealsEndsAt->timestamp }}"
                    class="rounded-lg px-3 py-1.5 text-base font-bold tabular-nums"
                    style="background-color: #000000; color: #FACC15;"
                >--:--:--</span>
            </div>
        </div>

        <div class="mt-5 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            @foreach ($dealProducts as $deal)
                <a
                    href="{{ $deal['url'] }}"
                    class="rounded-xl border border-zinc-200 bg-white p-3 shadow-sm transition hover:-translate-y-0.5 hover:border-zinc-400 hover:shadow-md"
                >
                    <div class="relative">
                        @if ($deal['image'])
                            <img
                                src="{{ $deal['image'] }}"
                                alt="{{ $deal['name'] }}"
                                class="h-40 w-full rounded-lg object-cover"
                                loading="lazy"
                            >
                        @endif

                        <span
                            class="absolute left-2 top-2 rounded-full px-2.5 py-1 text-xs font-bold text-white"
                            style="background-color: #DC2626;"
                        >-{{ $deal['discount_percent'] }}%</span>
                    </div>

                    <p class="mt-2 line-clamp-2 min-h-[2.5rem] text-sm font-medium text-zinc-800">
                        {{ $deal['name'] }}
                    </p>

                    <p class="mt-1 flex flex-wrap items-baseline gap-2">
                        <span class="text-base font-bold text-zinc-900">
                            {{ $deal['formatted_deal_price'] }}
                        </span>

                        <s class="text-sm text-zinc-400">
                            {{ $deal['formatted_price'] }}
                        </s>
                    </p>
                </a>
            @endforeach
        </div>

        <script>
            (function () {
                var el = document.getElementById('deals-countdown');

                if (! el) {
                    return;
                }

                var endsAt = parseInt(el.dataset.endsAt, 10) * 1000;

                function tick() {
                    var now = Date.now();

                    // Stale cached page (yesterday's midnight): roll
                    // forward a day at a time instead of showing 00:00:00.
                    while (endsAt <= now) {
                        endsAt += 24 * 60 * 60 * 1000;
                    }

                    var diff = Math.max(0, endsAt - now);
                    var h = Math.floor(diff / 3600000);
                    var m = Math.floor(diff % 3600000 / 60000);
                    var s = Math.floor(diff % 60000 / 1000);

                    el.textContent =
                        String(h).padStart(2, '0') + ':' +
                        String(m).padStart(2, '0') + ':' +
                        String(s).padStart(2, '0');

                    if (diff <= 0) {
                        window.location.reload();
                    }
                }

                tick();
                setInterval(tick, 1000);
            })();
        </script>
    </section>
@endif
