{{--
    Public order tracking by phone number (idea 6). A customer types
    the phone number they ordered with and sees their orders' status
    with a timeline. No login, read-only. Products Simo hid from
    tracking are removed from the items list (the order itself still
    shows, so the customer can always track it).
--}}
<x-shop::layouts>
    <x-slot:title>
        @lang('shop::store_features.tracking_title')
    </x-slot>

    <div class="container mx-auto mt-10 max-w-2xl px-5 max-md:mt-6 max-md:px-4">
        <div class="rounded-xl border border-zinc-200 bg-white p-8 max-sm:p-5">
            <h1 class="text-2xl font-medium max-md:text-xl">
                @lang('shop::store_features.tracking_title')
            </h1>

            <p class="mt-2 text-sm text-zinc-600">
                @lang('shop::store_features.tracking_subtitle')
            </p>

            <form
                method="POST"
                action="{{ route('shop.tracking.lookup') }}"
                class="mt-6 space-y-5"
            >
                @csrf

                <div>
                    <label
                        for="phone"
                        class="mb-1.5 block text-sm font-medium text-zinc-900"
                    >
                        @lang('shop::store_features.tracking_phone')
                    </label>

                    <input
                        id="phone"
                        name="phone"
                        type="tel"
                        required
                        autocomplete="tel"
                        value="{{ old('phone', $phone) }}"
                        placeholder="@lang('shop::store_features.tracking_phone_placeholder')"
                        class="block w-full rounded-lg border border-zinc-200 px-4 py-3 text-sm focus:border-navyBlue focus:outline-none focus:ring-1 focus:ring-navyBlue"
                    >

                    @error('phone')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <button
                    type="submit"
                    class="primary-button w-full py-3 text-base"
                >
                    @lang('shop::store_features.tracking_button')
                </button>
            </form>
        </div>

        @if (! is_null($orders))
            <div class="mt-8">
                @if (count($orders) === 0)
                    <div class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                        <span class="icon-error mt-0.5 text-xl leading-none"></span>

                        <p>@lang('shop::store_features.tracking_not_found')</p>
                    </div>
                @else
                    <h2 class="mb-4 text-xl font-medium">
                        @lang('shop::store_features.tracking_your_orders')
                    </h2>

                    @foreach ($orders as $order)
                        <div class="mb-5 rounded-xl border border-zinc-200 bg-white p-6 max-sm:p-4">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <p class="text-base font-semibold">
                                        @lang('shop::store_features.tracking_order', ['id' => $order['increment_id']])
                                    </p>

                                    <p class="mt-1 text-sm text-zinc-500">
                                        @lang('shop::store_features.tracking_placed_on', ['date' => $order['formatted_created_at']])
                                    </p>
                                </div>

                                <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold
                                    @if ($order['step'] === 3) bg-emerald-50 text-emerald-700
                                    @elseif ($order['step'] === -1) bg-red-50 text-red-700
                                    @elseif ($order['step'] === 2) bg-violet-50 text-violet-700
                                    @elseif ($order['step'] === 1) bg-blue-50 text-blue-700
                                    @else bg-amber-50 text-amber-700 @endif
                                ">
                                    @lang('shop::store_features.'.$order['status_key'])
                                </span>
                            </div>

                            {{-- Timeline --}}
                            @if ($order['step'] >= 0)
                                <div class="mt-6 flex items-start">
                                    @foreach ($order['timeline'] as $index => $step)
                                        <div class="flex flex-1 flex-col items-center text-center">
                                            <span class="flex h-8 w-8 items-center justify-center rounded-full text-sm font-bold
                                                @if ($index < $order['step']) bg-emerald-500 text-white
                                                @elseif ($index === $order['step']) bg-navyBlue text-white
                                                @else bg-zinc-200 text-zinc-500 @endif
                                            ">
                                                @if ($index < $order['step'])
                                                    ✓
                                                @else
                                                    {{ $index + 1 }}
                                                @endif
                                            </span>

                                            <p class="mt-2 text-xs font-medium @if ($index <= $order['step']) text-zinc-900 @else text-zinc-400 @endif">
                                                @lang('shop::store_features.tracking_step_'.$step['key'])
                                            </p>

                                            <p class="mt-0.5 text-[11px] text-zinc-400">
                                                {{ $step['at'] ? $step['at']->format('d/m/Y') : '' }}
                                            </p>
                                        </div>

                                        @if (! $loop->last)
                                            <div class="mt-4 h-0.5 flex-1 @if ($index < $order['step']) bg-emerald-500 @else bg-zinc-200 @endif"></div>
                                        @endif
                                    @endforeach
                                </div>
                            @endif

                            {{-- Items --}}
                            @if (count($order['items']) > 0)
                                <div class="mt-6 border-t border-zinc-100 pt-4">
                                    <p class="mb-3 text-sm font-medium text-zinc-700">
                                        @lang('shop::store_features.tracking_items')
                                    </p>

                                    @foreach ($order['items'] as $item)
                                        <div class="mb-3 flex items-center gap-3 last:mb-0">
                                            @if ($item['image'])
                                                <img
                                                    src="{{ $item['image'] }}"
                                                    alt="{{ $item['name'] }}"
                                                    class="h-12 w-12 rounded-lg object-cover"
                                                >
                                            @else
                                                <div class="flex h-12 w-12 items-center justify-center rounded-lg bg-zinc-100 text-xs font-semibold text-zinc-500">
                                                    {{ mb_substr($item['name'], 0, 2) }}
                                                </div>
                                            @endif

                                            <div class="min-w-0 flex-1">
                                                <p class="truncate text-sm font-medium">{{ $item['name'] }}</p>

                                                <p class="text-xs text-zinc-500">x{{ $item['qty'] }}</p>
                                            </div>

                                            <p class="text-sm font-medium">{{ $item['formatted_total'] }}</p>
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            <div class="mt-4 flex items-center justify-between border-t border-zinc-100 pt-4">
                                <p class="text-sm font-medium text-zinc-700">
                                    @lang('shop::store_features.tracking_total')
                                </p>

                                <p class="text-base font-bold">{{ $order['formatted_grand_total'] }}</p>
                            </div>
                        </div>
                    @endforeach
                @endif
            </div>
        @endif
    </div>
</x-shop::layouts>
