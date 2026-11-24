{{--
    Souk sourcing card, rendered after the admin product edit form
    (view_render_event bagisto.admin.catalog.product.edit.after).
    It is a separate form: it must NOT sit inside the product form.

    Shows the current stock, the effective sourcing mode, and — for
    admins with the `cod.souk` permission — the mode / price cap /
    minimum margin form. A product without a sourcing row behaves
    like "auto".
--}}
<div class="mt-4 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
    <p class="text-base font-bold text-gray-800 dark:text-white">
        @lang('admin::app.cod.souk.product-card.title')
    </p>

    <div class="mt-3 grid gap-2 text-sm text-gray-700 dark:text-gray-300">
        <p>
            <span class="font-semibold text-gray-800 dark:text-white">@lang('admin::app.cod.souk.product-card.stock'):</span>
            <span class="tabular-nums">{{ $stockQty }}</span>
        </p>

        <p>
            <span class="font-semibold text-gray-800 dark:text-white">@lang('admin::app.cod.souk.product-card.effective-mode'):</span>
            <x-admin::cod.status-badge status="{{ $effectiveMode === 'souk' ? 'assigned' : 'verified' }}" :label="trans('admin::app.cod.souk.sourcing-modes.'.$effectiveMode)" />
        </p>

        @if ($openTask)
            <p>
                <span class="font-semibold text-gray-800 dark:text-white">@lang('admin::app.cod.souk.product-card.open-task'):</span>
                <x-admin::cod.status-badge :status="$openTask->status" :label="trans('admin::app.cod.souk.statuses.'.$openTask->status)" />
                <a href="{{ route('admin.cod.souk.index') }}" class="text-blue-600 hover:underline dark:text-blue-400">
                    @lang('admin::app.cod.souk.product-card.view-board')
                </a>
            </p>
        @endif
    </div>

    @if ($canEditSourcing)
        <form
            method="POST"
            action="{{ route('admin.cod.souk.sourcing.update', $product) }}"
            class="mt-4 grid gap-3"
            onsubmit="return this.mode.value !== 'souk' || confirm(@js(trans('admin::app.cod.souk.product-card.souk-confirm')))"
        >
            @csrf

            <div class="grid gap-1.5">
                <label for="souk_mode" class="text-xs font-semibold text-gray-600 dark:text-gray-300">
                    @lang('admin::app.cod.souk.product-card.mode')
                </label>

                <select
                    id="souk_mode"
                    name="mode"
                    class="w-full max-w-md rounded-lg border border-gray-200 bg-white px-2.5 py-2 text-sm text-gray-800 dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                >
                    @foreach (['auto', 'own_stock', 'souk'] as $mode)
                        <option value="{{ $mode }}" @selected(($sourcing?->mode ?? 'auto') === $mode)>
                            @lang('admin::app.cod.souk.sourcing-modes.'.$mode)
                        </option>
                    @endforeach
                </select>

                <p class="text-xs leading-relaxed text-gray-500 dark:text-gray-400">
                    @lang('admin::app.cod.souk.product-card.mode-info')
                </p>
            </div>

            <div class="flex flex-wrap gap-3">
                <div class="grid gap-1.5">
                    <label for="souk_price_cap" class="text-xs font-semibold text-gray-600 dark:text-gray-300">
                        @lang('admin::app.cod.souk.product-card.price-cap')
                    </label>

                    <input
                        id="souk_price_cap"
                        type="number"
                        name="price_cap"
                        step="0.01"
                        min="0"
                        value="{{ $sourcing?->price_cap !== null ? (float) $sourcing->price_cap : '' }}"
                        placeholder="@lang('admin::app.cod.souk.product-card.price-cap-placeholder')"
                        class="w-48 rounded-lg border border-gray-200 bg-white px-2.5 py-2 text-sm text-gray-800 dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                    >
                </div>

                <div class="grid gap-1.5">
                    <label for="souk_min_margin" class="text-xs font-semibold text-gray-600 dark:text-gray-300">
                        @lang('admin::app.cod.souk.product-card.min-margin')
                    </label>

                    <input
                        id="souk_min_margin"
                        type="number"
                        name="min_margin_percent"
                        step="0.01"
                        min="0"
                        max="100"
                        value="{{ $sourcing?->min_margin_percent !== null ? (float) $sourcing->min_margin_percent : '' }}"
                        placeholder="@lang('admin::app.cod.souk.product-card.min-margin-placeholder')"
                        class="w-48 rounded-lg border border-gray-200 bg-white px-2.5 py-2 text-sm text-gray-800 dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                    >
                </div>
            </div>

            <div>
                <button type="submit" class="primary-button">
                    @lang('admin::app.cod.souk.product-card.save')
                </button>
            </div>
        </form>
    @endif
</div>
