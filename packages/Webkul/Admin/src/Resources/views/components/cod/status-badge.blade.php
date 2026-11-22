{{--
    COD order status badge (shared by every COD page).

    Colours reuse the status hues already used in the codebase
    (Webkul\Admin\DataGrids\Sales\OrderDataGrid::$statusColors):
    pending = yellow, whatsapp unconfirmed = amber (its own colour:
    neither pending yellow nor canceled gray — the order is stuck,
    not dead), confirmed = blue, sent to delivery = purple,
    delivered = teal, returned = rose, canceled = neutral gray.

    Conversation states reuse the same pills (collecting = sky,
    awaiting confirmation = yellow, escalated = rose: it needs a
    human now). Pass a `label` to show another text than the order
    status translation (e.g. the conversation state name).
    Light pills match the badge style already used on the COD pages.
--}}
@props(['status', 'label' => null])

@php
    $badgeClasses = [
        'pending_confirmation' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
        'whatsapp_unconfirmed' => 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200',
        'confirmed' => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
        'canceled' => 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200',
        'sent_to_delivery' => 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200',
        'delivered' => 'bg-teal-100 text-teal-800 dark:bg-teal-900 dark:text-teal-200',
        'returned' => 'bg-rose-100 text-rose-800 dark:bg-rose-900 dark:text-rose-200',
        'collecting_info' => 'bg-sky-100 text-sky-800 dark:bg-sky-900 dark:text-sky-200',
        'awaiting_confirmation' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
        'escalated' => 'bg-rose-100 text-rose-800 dark:bg-rose-900 dark:text-rose-200',
        'assigned' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
        'claimed' => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
        'purchased' => 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200',
        'received' => 'bg-sky-100 text-sky-800 dark:bg-sky-900 dark:text-sky-200',
        'verified' => 'bg-teal-100 text-teal-800 dark:bg-teal-900 dark:text-teal-200',
        'rejected' => 'bg-rose-100 text-rose-800 dark:bg-rose-900 dark:text-rose-200',
        'paid' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
        'expired' => 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200',
    ];

    $badgeClass = $badgeClasses[$status] ?? 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200';
@endphp

<span class="whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold {{ $badgeClass }}">
    {{ $label ?? trans('admin::app.cod.orders.statuses.'.$status) }}
</span>
