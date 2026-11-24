<x-admin::layouts>
    <x-slot:title>
        @lang('admin::app.cod.conversations.title')
    </x-slot>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="grid gap-1.5">
            <p class="text-xl font-bold !leading-normal text-gray-800 dark:text-white">
                @lang('admin::app.cod.conversations.title')
            </p>

            <p class="!leading-normal text-gray-600 dark:text-gray-300">
                @lang('admin::app.cod.conversations.subtitle')
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            <a href="{{ $dashboardUrl }}" class="secondary-button">
                @lang('admin::app.cod.conversations.back-to-dashboard')
            </a>
        </div>
    </div>

    <!-- Counts per state -->
    <div class="mt-8 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-admin::cod.stat-card :label="trans('admin::app.cod.conversations.all')" :value="$stateCounts['all']" />

        <x-admin::cod.stat-card
            :label="trans('admin::app.cod.conversations.states.collecting_info')"
            :value="$stateCounts[\App\Models\CodConversation::STATE_COLLECTING_INFO]"
        />

        <x-admin::cod.stat-card
            :label="trans('admin::app.cod.conversations.states.awaiting_confirmation')"
            :value="$stateCounts[\App\Models\CodConversation::STATE_AWAITING_CONFIRMATION]"
        />

        <x-admin::cod.stat-card
            :label="trans('admin::app.cod.conversations.states.escalated')"
            :value="$stateCounts[\App\Models\CodConversation::STATE_ESCALATED]"
        />
    </div>

    <!-- Filter by conversation state -->
    <div class="mt-8 flex flex-wrap gap-2">
        <a
            href="{{ route('admin.cod.conversations.index') }}"
            class="whitespace-nowrap rounded-full px-3 py-1.5 text-xs font-semibold {{ is_null($state) ? 'bg-blue-600 text-white' : 'bg-white text-gray-700 ring-1 ring-gray-200 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-800' }}"
        >
            @lang('admin::app.cod.conversations.all') ({{ $stateCounts['all'] }})
        </a>

        @foreach ($states as $filterState)
            <a
                href="{{ route('admin.cod.conversations.index', ['state' => $filterState]) }}"
                class="whitespace-nowrap rounded-full px-3 py-1.5 text-xs font-semibold {{ $state === $filterState ? 'bg-blue-600 text-white' : 'bg-white text-gray-700 ring-1 ring-gray-200 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-800' }}"
            >
                @lang('admin::app.cod.conversations.states.'.$filterState) ({{ $stateCounts[$filterState] ?? 0 }})
            </a>
        @endforeach
    </div>

    <!-- Search by phone and filter by linked order outcome -->
    <form method="GET" action="{{ route('admin.cod.conversations.index') }}" class="mt-4 flex flex-wrap items-center gap-2">
        <input type="hidden" name="state" value="{{ $state }}">

        <input
            type="text"
            name="q"
            value="{{ $phoneQuery }}"
            placeholder="@lang('admin::app.cod.conversations.search-placeholder')"
            class="w-full max-w-xs rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 dark:border-gray-800 dark:bg-gray-900 dark:text-white"
        >

        <select
            name="order_status"
            class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 dark:border-gray-800 dark:bg-gray-900 dark:text-white"
        >
            <option value="">{{ trans('admin::app.cod.conversations.all-outcomes') }}</option>

            @foreach ($orderStatuses as $orderStatusOption)
                <option value="{{ $orderStatusOption }}" @selected($orderStatus === $orderStatusOption)>
                    @lang('admin::app.cod.orders.statuses.'.$orderStatusOption)
                </option>
            @endforeach
        </select>

        <button type="submit" class="primary-button">@lang('admin::app.cod.my-tasks.filter')</button>
    </form>

    <!-- Conversations -->
    <div class="mt-4 overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
        @if ($conversations->isEmpty())
            <p class="p-8 text-center text-sm text-gray-600 dark:text-gray-300">
                @lang('admin::app.cod.conversations.empty')
            </p>
        @else
            <table class="w-full min-w-[900px] text-start text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-gray-600 dark:border-gray-800 dark:text-gray-300">
                        <th class="whitespace-nowrap px-3 py-3 text-start font-semibold">@lang('admin::app.cod.conversations.phone')</th>
                        <th class="whitespace-nowrap px-3 py-3 text-start font-semibold">@lang('admin::app.cod.conversations.customer')</th>
                        <th class="whitespace-nowrap px-3 py-3 text-start font-semibold">@lang('admin::app.cod.conversations.order')</th>
                        <th class="whitespace-nowrap px-3 py-3 text-start font-semibold">@lang('admin::app.cod.conversations.product')</th>
                        <th class="whitespace-nowrap px-3 py-3 text-start font-semibold">@lang('admin::app.cod.conversations.state')</th>
                        <th class="whitespace-nowrap px-3 py-3 text-start font-semibold">@lang('admin::app.cod.conversations.last-message')</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($conversations as $conversation)
                        <tr class="border-b border-gray-100 align-top last:border-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-800/50">
                            <td class="whitespace-nowrap px-3 py-3" dir="ltr">
                                <a
                                    href="{{ route('admin.cod.conversations.show', $conversation) }}"
                                    class="font-semibold text-blue-600 hover:underline dark:text-blue-400"
                                >
                                    {{ $conversation->phone }}
                                </a>
                            </td>

                            <td class="px-3 py-3 text-gray-800 dark:text-white">
                                {{ $conversation->codOrder?->customer_name ?? $conversation->answers()['name'] ?? '—' }}
                            </td>

                            <td class="whitespace-nowrap px-3 py-3 text-gray-800 dark:text-white">
                                @if ($conversation->codOrder)
                                    <span class="font-semibold">#{{ $conversation->codOrder->external_id }}</span>

                                    <x-admin::cod.status-badge :status="$conversation->codOrder->status" />
                                @else
                                    —
                                @endif
                            </td>

                            <td class="px-3 py-3 text-gray-800 dark:text-white">
                                {{ $conversation->product()['name'] ?? '—' }}
                            </td>

                            <td class="px-3 py-3">
                                <x-admin::cod.status-badge
                                    :status="$conversation->state"
                                    :label="trans('admin::app.cod.conversations.states.'.$conversation->state)"
                                />
                            </td>

                            <td class="whitespace-nowrap px-3 py-3 tabular-nums text-gray-600 dark:text-gray-300">
                                {{ $conversation->last_message_at?->format('Y-m-d H:i') ?? $conversation->updated_at?->format('Y-m-d H:i') }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    @if ($conversations->hasPages())
        <div class="mt-4">
            {{ $conversations->links() }}
        </div>
    @endif
</x-admin::layouts>
