<x-admin::layouts>
    <x-slot:title>
        @lang('admin::app.cod.my-tasks.title')
    </x-slot>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <div class="grid gap-1.5">
            <p class="text-xl font-bold !leading-normal text-gray-800 dark:text-white">
                @lang('admin::app.cod.my-tasks.title')
            </p>

            <p class="!leading-normal text-gray-600 dark:text-gray-300">
                @lang('admin::app.cod.my-tasks.subtitle')
            </p>
        </div>

        <a href="{{ $dashboardUrl }}" class="secondary-button">
            @lang('admin::app.cod.my-tasks.back-to-dashboard')
        </a>
    </div>

    @if ($canManage)
        <!-- Supervisor: filter by confirmer -->
        <form method="GET" action="{{ route('admin.cod.my-tasks.index') }}" class="mt-8 flex flex-wrap items-center gap-2">
            <select name="confirmer" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 dark:border-gray-800 dark:bg-gray-900 dark:text-white">
                <option value="">{{ __('admin::app.cod.my-tasks.all-confirmers') }}</option>

                @foreach ($confirmers as $confirmer)
                    <option value="{{ $confirmer->id }}" @selected($selectedConfirmer?->id === $confirmer->id)>
                        {{ $confirmer->name }}
                    </option>
                @endforeach
            </select>

            <button type="submit" class="secondary-button">
                @lang('admin::app.cod.my-tasks.filter')
            </button>
        </form>
    @endif

    <!-- Confirmation tasks, pending first -->
    <div class="mt-4 overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
        @if ($tasks->isEmpty())
            <p class="p-8 text-center text-sm text-gray-600 dark:text-gray-300">
                @lang('admin::app.cod.my-tasks.empty')
            </p>
        @else
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-gray-600 dark:border-gray-800 dark:text-gray-300">
                        <th class="p-4 font-semibold">@lang('admin::app.cod.my-tasks.order')</th>
                        <th class="p-4 font-semibold">@lang('admin::app.cod.my-tasks.customer')</th>
                        <th class="p-4 font-semibold">@lang('admin::app.cod.my-tasks.total')</th>
                        <th class="p-4 font-semibold">@lang('admin::app.cod.my-tasks.status')</th>

                        @if ($canManage)
                            <th class="p-4 font-semibold">@lang('admin::app.cod.my-tasks.assigned-to')</th>
                        @endif

                        <th class="p-4 font-semibold">@lang('admin::app.cod.my-tasks.attempts')</th>
                        <th class="p-4 font-semibold">@lang('admin::app.cod.my-tasks.last-call')</th>
                        <th class="p-4 font-semibold">@lang('admin::app.cod.my-tasks.record-call')</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($tasks as $task)
                        @php($lastCall = $task->callLogs->first())

                        <tr class="border-b border-gray-100 align-top last:border-0 dark:border-gray-800">
                            <td class="p-4">
                                @if ($task->order_id)
                                    <a
                                        href="{{ route('admin.sales.orders.view', $task->order_id) }}"
                                        class="font-semibold text-blue-600 hover:underline dark:text-blue-400"
                                    >
                                        #{{ $task->external_id }}
                                    </a>
                                @else
                                    <span class="font-semibold text-gray-800 dark:text-white">#{{ $task->external_id }}</span>
                                @endif

                                <span class="block whitespace-nowrap text-xs text-gray-500 dark:text-gray-400">
                                    {{ $task->created_at?->format('Y-m-d H:i') }}
                                </span>
                            </td>

                            <td class="p-4 text-gray-800 dark:text-white">
                                {{ $task->customer_name ?? '—' }}

                                @if ($task->customer_phone)
                                    <span class="block text-xs text-gray-500 dark:text-gray-400" dir="ltr">{{ $task->customer_phone }}</span>
                                @endif

                                @if ($task->city)
                                    <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $task->city }}</span>
                                @endif
                            </td>

                            <td class="whitespace-nowrap p-4 text-gray-800 dark:text-white">
                                {{ number_format((float) $task->grand_total, 2) }} {{ $task->currency_code ?? '' }}
                            </td>

                            <td class="p-4">
                                <span class="whitespace-nowrap rounded-full bg-blue-100 px-2.5 py-1 text-xs font-semibold text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                                    @lang('admin::app.cod.orders.statuses.'.$task->status)
                                </span>

                                @if ($task->callback_at)
                                    <span class="mt-1 block whitespace-nowrap text-xs text-gray-500 dark:text-gray-400">
                                        @lang('admin::app.cod.my-tasks.callback-at', ['date' => $task->callback_at->format('Y-m-d H:i')])
                                    </span>
                                @endif
                            </td>

                            @if ($canManage)
                                <td class="p-4 text-gray-800 dark:text-white">
                                    {{ $task->assignedTo?->name ?? '—' }}
                                </td>
                            @endif

                            <td class="p-4 text-center text-gray-800 dark:text-white">
                                {{ $task->confirmation_attempts }}
                            </td>

                            <td class="p-4 text-gray-800 dark:text-white">
                                @if ($lastCall)
                                    <span class="whitespace-nowrap rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-800 dark:bg-gray-800 dark:text-gray-200">
                                        @lang('admin::app.cod.calls.results.'.$lastCall->result)
                                    </span>

                                    @if ($lastCall->note)
                                        <span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">{{ $lastCall->note }}</span>
                                    @endif

                                    <span class="mt-1 block whitespace-nowrap text-xs text-gray-500 dark:text-gray-400">
                                        {{ $lastCall->called_at?->format('Y-m-d H:i') }}
                                    </span>
                                @else
                                    <span class="text-xs text-gray-500 dark:text-gray-400">
                                        @lang('admin::app.cod.my-tasks.no-calls-yet')
                                    </span>
                                @endif
                            </td>

                            <td class="p-4">
                                @if ($task->status === 'pending_confirmation')
                                    <form method="POST" action="{{ route('admin.cod.orders.calls.store', $task) }}" class="grid gap-2">
                                        @csrf

                                        <select name="result" required class="rounded-lg border border-gray-200 bg-white px-2 py-1.5 text-xs text-gray-800 dark:border-gray-800 dark:bg-gray-900 dark:text-white">
                                            @foreach ($results as $result)
                                                <option value="{{ $result }}">
                                                    @lang('admin::app.cod.calls.results.'.$result)
                                                </option>
                                            @endforeach
                                        </select>

                                        <input
                                            type="text"
                                            name="note"
                                            maxlength="1000"
                                            placeholder="@lang('admin::app.cod.calls.note-placeholder')"
                                            class="w-44 rounded-lg border border-gray-200 bg-white px-2 py-1.5 text-xs text-gray-800 dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                        >

                                        <input
                                            type="datetime-local"
                                            name="callback_at"
                                            title="@lang('admin::app.cod.calls.callback-at')"
                                            class="w-44 rounded-lg border border-gray-200 bg-white px-2 py-1.5 text-xs text-gray-800 dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                        >

                                        <button type="submit" class="primary-button justify-center !px-3 !py-1.5 !text-xs">
                                            @lang('admin::app.cod.my-tasks.save-call')
                                        </button>
                                    </form>
                                @else
                                    <span class="text-xs text-gray-500 dark:text-gray-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    @if ($tasks->hasPages())
        <div class="mt-4">
            {{ $tasks->links() }}
        </div>
    @endif
</x-admin::layouts>
