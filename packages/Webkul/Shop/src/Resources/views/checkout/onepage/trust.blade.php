{{--
    Jemla: trust badges (COD / fast delivery / guarantee) + estimated
    delivery timeline. Shown LAST on the onepage checkout, after the
    offers and the continue-shopping button. Moved out of
    summary.blade.php so the summary is only items + totals.
--}}
<div class="mt-4">
    <!-- Moroccan E-Commerce Trust Badges -->
    <div class="rounded-xl border border-emerald-100 bg-emerald-50/70 p-4 text-xs text-zinc-700">
        <div class="space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-sm text-emerald-700">💵</span>
                <div>
                    <p class="font-bold text-zinc-900">@lang('shop::app.checkout.onepage.summary.cod-title')</p>
                    <p class="text-[11px] text-zinc-500">@lang('shop::app.checkout.onepage.summary.cod-desc')</p>
                </div>
            </div>

            <div class="flex items-center gap-2.5">
                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-sm text-emerald-700">🚚</span>
                <div>
                    <p class="font-bold text-zinc-900">@lang('shop::app.checkout.onepage.summary.delivery-title')</p>
                    <p class="text-[11px] text-zinc-500">@lang('shop::app.checkout.onepage.summary.delivery-desc')</p>
                </div>
            </div>

            <div class="flex items-center gap-2.5">
                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-sm text-emerald-700">🔄</span>
                <div>
                    <p class="font-bold text-zinc-900">@lang('shop::app.checkout.onepage.summary.guarantee-title')</p>
                    <p class="text-[11px] text-zinc-500">@lang('shop::app.checkout.onepage.summary.guarantee-desc')</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Estimated Delivery Timeline -->
    @include('shop::checkout.onepage.delivery-timeline')
</div>
