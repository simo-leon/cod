{{--
    COD stat card (shared by the dashboard, the confirmer page and
    analytics): one label, one big figure, one optional explainer line.
--}}
@props(['label', 'value', 'hint' => null])

<div class="min-w-0 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
    <p class="truncate text-sm text-gray-600 dark:text-gray-300">{{ $label }}</p>

    {{-- The value escapes the admin's global `p { line-height: 17px
        !important }` rule (app.css) with `!leading-snug`, otherwise a
        wrapped long value (order id, product name) renders its lines
        on top of each other; `break-words` lets a long unbroken value
        wrap inside the card instead of overflowing it. --}}
    <p class="mt-1 break-words text-3xl font-bold !leading-snug tabular-nums text-gray-800 dark:text-white">{{ $value }}</p>

    @if ($hint)
        <p class="mt-1 text-xs leading-relaxed text-gray-500 dark:text-gray-400">{{ $hint }}</p>
    @endif
</div>
