{{--
    COD order status badge (shared by every COD page).

    Colours reuse the status hues already used in the codebase
    (Webkul\Admin\DataGrids\Sales\OrderDataGrid::$statusColors):
    pending = yellow, confirmed = blue, sent to delivery = purple,
    delivered = teal, returned = rose, canceled = neutral gray.
    Light pills match the badge style already used on the COD pages.
--}}
@props(['status'])

@php
    $badgeClasses = [
        'pending_confirmation' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
        'confirmed' => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
        'canceled' => 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200',
        'sent_to_delivery' => 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200',
        'delivered' => 'bg-teal-100 text-teal-800 dark:bg-teal-900 dark:text-teal-200',
        'returned' => 'bg-rose-100 text-rose-800 dark:bg-rose-900 dark:text-rose-200',
    ];

    $badgeClass = $badgeClasses[$status] ?? 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200';
@endphp

<span class="whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold {{ $badgeClass }}">
    @lang('admin::app.cod.orders.statuses.'.$status)
</span>
