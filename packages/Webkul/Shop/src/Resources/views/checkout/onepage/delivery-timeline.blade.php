@php
    $today = \Carbon\Carbon::today();
    $tomorrow = $today->copy()->addDay();
    $deliveryEnd = $today->copy()->addDays(3);

    $locale = app()->getLocale();

    $formatDay = fn ($date) => $date->copy()->locale($locale)->translatedFormat('j M');

    $deliveryTimelineSteps = [
        [
            'icon'  => 'cart',
            'date'  => $formatDay($today),
            'label' => trans('shop::app.checkout.onepage.summary.delivery-timeline-order'),
        ],
        [
            'icon'  => 'box',
            'date'  => $formatDay($today).' – '.$formatDay($tomorrow),
            'label' => trans('shop::app.checkout.onepage.summary.delivery-timeline-confirmation'),
        ],
        [
            'icon'  => 'truck',
            'date'  => $formatDay($tomorrow).' – '.$formatDay($deliveryEnd),
            'label' => trans('shop::app.checkout.onepage.summary.delivery-timeline-delivery'),
        ],
    ];
@endphp

<!-- Estimated Delivery Timeline -->
<div class="mt-6 rounded-xl border border-zinc-200 bg-white p-4">
    <h2 class="border-b border-zinc-200 pb-3 text-lg font-bold text-zinc-900">
        @lang('shop::app.checkout.onepage.summary.delivery-timeline-title')
    </h2>

    <div class="mt-4">
        @foreach ($deliveryTimelineSteps as $step)
            <div class="flex gap-3">
                <!-- Icon + connector line -->
                <div class="flex w-12 shrink-0 flex-col items-center">
                    <span
                        class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full"
                        style="background-color: #000000; color: #FACC15; box-shadow: 0 0 0 2px #FACC15;"
                    >
                        @if ($step['icon'] === 'cart')
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 00-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 00-16.536-1.84M7.5 14.25L5.106 5.272M6 20.25a.75.75 0 11-1.5 0 .75.75 0 011.5 0zm12.75 0a.75.75 0 11-1.5 0 .75.75 0 011.5 0z" />
                            </svg>
                        @elseif ($step['icon'] === 'box')
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
                            </svg>
                        @else
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 00-10.026 0 1.106 1.106 0 00-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12" />
                            </svg>
                        @endif
                    </span>

                    @if (! $loop->last)
                        <span class="flex-1" style="width: 2px; background-color: #FACC15;" aria-hidden="true"></span>
                    @endif
                </div>

                <!-- Date + label -->
                <div class="{{ $loop->last ? '' : 'pb-5' }}">
                    <p class="text-base font-bold leading-6 text-zinc-900">{{ $step['date'] }}</p>

                    <p class="text-sm text-zinc-500">{{ $step['label'] }}</p>
                </div>
            </div>
        @endforeach
    </div>
</div>
