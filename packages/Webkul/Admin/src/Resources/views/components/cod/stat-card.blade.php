{{--
    COD stat card (shared by the dashboard, the confirmer page and
    analytics): one label, one big figure, one optional explainer line.
--}}
@props(['label', 'value', 'hint' => null])

<div class="min-w-0 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
    <p class="truncate text-sm text-gray-600 dark:text-gray-300">{{ $label }}</p>

    <p class="mt-1 text-2xl font-bold tabular-nums text-gray-800 dark:text-white">{{ $value }}</p>

    @if ($hint)
        <p class="mt-1 text-xs leading-relaxed text-gray-500 dark:text-gray-400">{{ $hint }}</p>
    @endif
</div>
