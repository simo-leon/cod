<x-admin::layouts>
    <x-slot:title>
        @lang('admin::app.cod.orders.title')
    </x-slot>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="grid gap-1.5">
            <p class="text-xl font-bold !leading-normal text-gray-800 dark:text-white">
                @lang('admin::app.cod.orders.title')
            </p>

            <p class="!leading-normal text-gray-600 dark:text-gray-300">
                @lang('admin::app.cod.orders.subtitle')
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            @if ($canManageAssignment && $unassignedCount > 0)
                <form method="POST" action="{{ route('admin.cod.assignment.distribute') }}">
                    @csrf

                    <button type="submit" class="primary-button whitespace-nowrap">
                        @lang('admin::app.cod.orders.distribute', ['count' => $unassignedCount])
                    </button>
                </form>
            @endif

            <a href="{{ $dashboardUrl }}" class="secondary-button">
                @lang('admin::app.cod.orders.back-to-dashboard')
            </a>
        </div>
    </div>

    @if (($statusCounts['whatsapp_unconfirmed'] ?? 0) > 0 && $status !== 'whatsapp_unconfirmed')
        <div class="mt-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm dark:border-amber-900 dark:bg-amber-900/30">
            <a href="{{ route('admin.cod.orders.index', ['status' => 'whatsapp_unconfirmed']) }}" class="font-semibold text-amber-800 hover:underline dark:text-amber-200">
                @lang('admin::app.cod.orders.whatsapp-worklist', ['count' => $statusCounts['whatsapp_unconfirmed']])
            </a>
        </div>
    @endif

    <!-- Filter by COD status -->
    <div class="mt-8 flex flex-wrap gap-2">
        <a
            href="{{ route('admin.cod.orders.index', array_filter(['status' => $status, 'unassigned' => $unassignedOnly ? 1 : null])) }}"
            class="whitespace-nowrap rounded-full px-3 py-1.5 text-xs font-semibold {{ is_null($status) && ! $unassignedOnly ? 'bg-blue-600 text-white' : 'bg-white text-gray-700 ring-1 ring-gray-200 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-800' }}"
        >
            @lang('admin::app.cod.orders.all') ({{ $orders->total() }})
        </a>

        @if ($canManageAssignment)
            <a
                href="{{ route('admin.cod.orders.index', array_filter(['status' => $status, 'unassigned' => $unassignedOnly ? null : 1])) }}"
                class="whitespace-nowrap rounded-full px-3 py-1.5 text-xs font-semibold {{ $unassignedOnly ? 'bg-blue-600 text-white' : 'bg-white text-gray-700 ring-1 ring-gray-200 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-800' }}"
            >
                @lang('admin::app.cod.orders.unassigned') ({{ $unassignedCount }})
            </a>
        @endif

        @foreach ($statuses as $filterStatus)
            <a
                href="{{ route('admin.cod.orders.index', array_filter(['status' => $filterStatus, 'unassigned' => $unassignedOnly ? 1 : null])) }}"
                class="whitespace-nowrap rounded-full px-3 py-1.5 text-xs font-semibold {{ $status === $filterStatus ? 'bg-blue-600 text-white' : 'bg-white text-gray-700 ring-1 ring-gray-200 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-800' }}"
            >
                @lang('admin::app.cod.orders.statuses.'.$filterStatus) ({{ $statusCounts[$filterStatus] ?? 0 }})
            </a>
        @endforeach
    </div>

    <!-- Bulk assignment bar: appears once at least one row is selected -->
    @if ($canManageAssignment)
        <form id="bulk-assign-form" method="POST" action="{{ route('admin.cod.orders.bulk-assign') }}" class="mt-4 hidden flex flex-wrap items-center gap-3 rounded-lg border border-blue-200 bg-blue-50 p-3 dark:border-blue-900 dark:bg-blue-950/40">
            @csrf

            <span id="bulk-selected-count" data-template="@lang('admin::app.cod.orders.selected-count', ['count' => ':count'])" class="text-sm font-semibold text-gray-800 dark:text-white">
                @lang('admin::app.cod.orders.selected-count', ['count' => 0])
            </span>

            <select name="confirmer_id" required class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 dark:border-gray-800 dark:bg-gray-900 dark:text-white">
                <option value="" disabled selected>@lang('admin::app.cod.orders.choose-confirmer')</option>

                @foreach ($confirmers as $confirmer)
                    <option value="{{ $confirmer->id }}">{{ $confirmer->name }}</option>
                @endforeach
            </select>

            <button type="submit" class="primary-button whitespace-nowrap">
                @lang('admin::app.cod.orders.bulk-assign')
            </button>
        </form>
    @endif

    <!-- Imported COD orders -->
    <div class="mt-4 overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
        @if ($orders->isEmpty())
            <p class="p-8 text-center text-sm text-gray-600 dark:text-gray-300">
                @lang('admin::app.cod.orders.empty')
            </p>
        @else
            <table class="w-full min-w-[1120px] text-start text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-gray-600 dark:border-gray-800 dark:text-gray-300">
                        @if ($canManageAssignment)
                            <th class="w-10 px-3 py-3 text-start font-semibold">
                                <input type="checkbox" id="bulk-select-all" title="@lang('admin::app.cod.orders.bulk-assign')" class="h-4 w-4 accent-blue-600">
                            </th>
                        @endif

                        <th class="whitespace-nowrap px-3 py-3 text-start font-semibold">@lang('admin::app.cod.orders.order')</th>
                        <th class="whitespace-nowrap px-3 py-3 text-start font-semibold">@lang('admin::app.cod.orders.customer')</th>
                        <th class="whitespace-nowrap px-3 py-3 text-start font-semibold">@lang('admin::app.cod.orders.phone')</th>
                        <th class="whitespace-nowrap px-3 py-3 text-start font-semibold">@lang('admin::app.cod.orders.city')</th>
                        <th class="whitespace-nowrap px-3 py-3 text-start font-semibold">@lang('admin::app.cod.orders.total')</th>
                        <th class="whitespace-nowrap px-3 py-3 text-start font-semibold">@lang('admin::app.cod.orders.status')</th>
                        <th class="whitespace-nowrap px-3 py-3 text-start font-semibold">@lang('admin::app.cod.orders.souk')</th>
                        <th class="whitespace-nowrap px-3 py-3 text-start font-semibold">@lang('admin::app.cod.orders.assigned-to')</th>
                        <th class="whitespace-nowrap px-3 py-3 text-start font-semibold">@lang('admin::app.cod.orders.source')</th>
                        <th class="whitespace-nowrap px-3 py-3 text-start font-semibold">@lang('admin::app.cod.orders.date')</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($orders as $codOrder)
                        <tr class="border-b border-gray-100 align-top last:border-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-800/50">
                            @if ($canManageAssignment)
                                <td class="px-3 py-3">
                                    @if ($codOrder->status === 'pending_confirmation')
                                        <input type="checkbox" name="order_ids[]" value="{{ $codOrder->id }}" form="bulk-assign-form" class="bulk-select h-4 w-4 accent-blue-600">
                                    @endif
                                </td>
                            @endif

                            <td class="px-3 py-3">
                                <a
                                    href="{{ route('admin.cod.orders.show', $codOrder) }}"
                                    class="whitespace-nowrap font-semibold text-blue-600 hover:underline dark:text-blue-400"
                                >
                                    #{{ $codOrder->external_id }}
                                </a>

                                <span class="block whitespace-nowrap text-xs text-gray-500 dark:text-gray-400">COD #{{ $codOrder->id }}</span>
                            </td>

                            <td class="px-3 py-3 text-gray-800 dark:text-white">
                                <span class="block">{{ $codOrder->customer_name ?? '—' }}</span>

                                @if ($codOrder->customer_email)
                                    <span class="block truncate text-xs text-gray-500 dark:text-gray-400">{{ $codOrder->customer_email }}</span>
                                @endif

                                @php($lastCall = $lastCalls[$codOrder->id] ?? null)

                                @if ($lastCall)
                                    <span class="mt-1 block whitespace-nowrap text-xs text-gray-500 dark:text-gray-400">
                                        @lang('admin::app.cod.orders.last-call'): @lang('admin::app.cod.calls.results.'.$lastCall->result) · {{ $lastCall->called_at?->format('Y-m-d H:i') }}
                                    </span>

                                    @if ($lastCall->note)
                                        <span class="block max-w-56 truncate text-xs text-gray-500 dark:text-gray-400" title="{{ $lastCall->note }}">{{ $lastCall->note }}</span>
                                    @endif
                                @else
                                    <span class="mt-1 block whitespace-nowrap text-xs text-gray-400 dark:text-gray-500">@lang('admin::app.cod.orders.last-call'): —</span>
                                @endif
                            </td>

                            <td class="whitespace-nowrap px-3 py-3 text-gray-800 dark:text-white" dir="ltr">
                                {{ $codOrder->customer_phone ?? '—' }}
                            </td>

                            <td class="whitespace-nowrap px-3 py-3 text-gray-800 dark:text-white">
                                {{ $codOrder->city ?? '—' }}
                            </td>

                            <td class="whitespace-nowrap px-3 py-3 tabular-nums text-gray-800 dark:text-white">
                                {{ number_format((float) $codOrder->grand_total, 2) }} {{ $codOrder->currency_code ?? '' }}
                            </td>

                            <td class="px-3 py-3">
                                <x-admin::cod.status-badge :status="$codOrder->status" />
                            </td>

                            <td class="px-3 py-3">
                                @if ($canViewSouk && ($soukTasks[$codOrder->id] ?? collect())->isNotEmpty())
                                    @foreach ($soukTasks[$codOrder->id] as $soukTask)
                                        <span class="block text-gray-800 dark:text-white">
                                            {{ $soukTask->product?->name ?? $soukTask->product?->sku ?? '—' }}
                                        </span>

                                        <span class="block text-xs text-gray-500 dark:text-gray-400">
                                            @lang('admin::app.cod.orders.souk-line', [
                                                'buyer' => $soukTask->buyer?->name ?? '—',
                                                'price' => $soukTask->purchase_price !== null ? number_format((float) $soukTask->purchase_price, 2).' DH' : '—',
                                                'status' => trans('admin::app.cod.souk.statuses.'.$soukTask->status),
                                            ])
                                        </span>
                                    @endforeach
                                @else
                                    —
                                @endif
                            </td>

                            <td class="px-3 py-3 text-gray-800 dark:text-white">
                                @if ($canManageAssignment && $codOrder->status === 'pending_confirmation')
                                    <form method="POST" action="{{ route('admin.cod.orders.assign', $codOrder) }}" class="grid w-44 gap-1.5">
                                        @csrf

                                        <select name="confirmer_id" required class="w-full rounded-lg border border-gray-200 bg-white px-2 py-1.5 text-xs text-gray-800 dark:border-gray-800 dark:bg-gray-900 dark:text-white">
                                            <option value="" disabled @selected(! $codOrder->assigned_to)>@lang('admin::app.cod.orders.choose-confirmer')</option>

                                            @foreach ($confirmers as $confirmer)
                                                <option value="{{ $confirmer->id }}" @selected($codOrder->assigned_to === $confirmer->id)>
                                                    {{ $confirmer->name }}
                                                </option>
                                            @endforeach
                                        </select>

                                        <button type="submit" class="whitespace-nowrap rounded-lg bg-blue-600 px-2.5 py-1.5 text-xs font-semibold text-white hover:bg-blue-700">
                                            @lang('admin::app.cod.orders.assign')
                                        </button>
                                    </form>
                                @else
                                    {{ $codOrder->assignedTo?->name ?? '—' }}
                                @endif
                            </td>

                            <td class="whitespace-nowrap px-3 py-3 text-gray-800 dark:text-white">
                                {{ \Illuminate\Support\Facades\Lang::has('admin::app.cod.orders.sources.'.$codOrder->source) ? trans('admin::app.cod.orders.sources.'.$codOrder->source) : $codOrder->source }}
                            </td>

                            <td class="whitespace-nowrap px-3 py-3 tabular-nums text-gray-600 dark:text-gray-300">
                                {{ $codOrder->created_at?->format('Y-m-d H:i') }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    @if ($orders->hasPages())
        <div class="mt-4">
            {{ $orders->links() }}
        </div>
    @endif

    @pushOnce('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                // Everything is re-queried on every update: Vue mounts
                // #app on window load and replaces the nodes this script
                // first saw, so listeners held on the old nodes (and
                // references to them) would go stale. Delegating the
                // change events on `document` survives the re-mount.
                function bar() {
                    return document.getElementById('bulk-assign-form');
                }

                function countEl() {
                    return document.getElementById('bulk-selected-count');
                }

                function boxes() {
                    return Array.prototype.slice.call(document.querySelectorAll('.bulk-select'));
                }

                function updateBulkBar() {
                    var barEl = bar();
                    var count = countEl();

                    if (! barEl || ! count) {
                        return;
                    }

                    var all = boxes();
                    var checked = all.filter(function (box) { return box.checked; }).length;

                    count.textContent = count.dataset.template.replace(':count', checked);
                    barEl.classList.toggle('hidden', checked === 0);

                    var selectAll = document.getElementById('bulk-select-all');

                    if (selectAll) {
                        selectAll.checked = checked > 0 && checked === all.length;
                    }
                }

                document.addEventListener('change', function (event) {
                    if (! event.target || ! event.target.classList) {
                        return;
                    }

                    if (event.target.classList.contains('bulk-select')) {
                        updateBulkBar();
                    }

                    if (event.target.id === 'bulk-select-all') {
                        boxes().forEach(function (box) { box.checked = event.target.checked; });
                        updateBulkBar();
                    }
                });

                updateBulkBar();

                window.addEventListener('load', function () {
                    setTimeout(updateBulkBar, 0);
                });
            });
        </script>
    @endPushOnce
</x-admin::layouts>
