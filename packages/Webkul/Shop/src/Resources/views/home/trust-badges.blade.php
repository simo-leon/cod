{{--
    Trust badges topic (Jemla: Shopify trust bar / COD landing-page
    trust signals — the promises Simo writes himself in the topic
    form, e.g. cash on delivery, phone confirmation, delivery
    across Morocco. Only points with a title render, so the section
    stays empty rather than showing promises Simo never wrote.
--}}
@php
    $trustItems = collect($items ?? [])
        ->filter(fn ($item) => trim((string) ($item['title'] ?? '')) !== '')
        ->values();
@endphp

@if ($trustItems->isNotEmpty())
    <section class="container mt-20 max-lg:px-8 max-md:mt-8 max-sm:mt-7 max-sm:!px-4">
        @if (! empty($title))
            <div class="flex items-center gap-3 border-b border-gray-100 pb-4 max-sm:pb-2.5">
                <span class="inline-block h-6 w-2.5 rounded-full bg-emerald-600 max-sm:h-5"></span>

                <h2 class="font-dmserif text-3xl font-bold text-gray-900 max-md:text-2xl max-sm:text-xl">
                    {{ $title }}
                </h2>
            </div>
        @endif

        <div class="mt-8 grid grid-cols-2 gap-4 lg:grid-cols-4">
            @foreach ($trustItems as $item)
                <div class="rounded-2xl border border-gray-100 bg-white p-5 text-center shadow-sm">
                    <p class="text-base font-bold text-gray-900 max-sm:text-sm">
                        {{ $item['title'] }}
                    </p>

                    @if (trim((string) ($item['text'] ?? '')) !== '')
                        <p class="mt-1 text-sm leading-6 text-gray-600 max-sm:text-xs">
                            {{ $item['text'] }}
                        </p>
                    @endif
                </div>
            @endforeach
        </div>
    </section>
@endif
