<x-admin::layouts>
    <x-slot:title>
        @lang('admin::app.cod.assignment.title')
    </x-slot>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <div class="grid gap-1.5">
            <p class="text-xl font-bold !leading-normal text-gray-800 dark:text-white">
                @lang('admin::app.cod.assignment.title')
            </p>

            <p class="!leading-normal text-gray-600 dark:text-gray-300">
                @lang('admin::app.cod.assignment.subtitle')
            </p>
        </div>

        <div class="flex gap-2">
            <a href="{{ $ordersUrl }}" class="secondary-button">
                @lang('admin::app.cod.assignment.view-orders')
            </a>

            <a href="{{ $dashboardUrl }}" class="secondary-button">
                @lang('admin::app.cod.assignment.back-to-dashboard')
            </a>
        </div>
    </div>

    @if ($errors->any())
        <div class="mt-8 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <!-- Distribute the orders that have no confirmer yet -->
    <div class="mt-8 flex flex-wrap items-center gap-4 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
        <p class="text-sm text-gray-600 dark:text-gray-300">
            @lang('admin::app.cod.assignment.unassigned-count', ['count' => $unassignedCount])
        </p>

        <form method="POST" action="{{ route('admin.cod.assignment.distribute') }}">
            @csrf

            <button type="submit" class="primary-button" @disabled($unassignedCount === 0)>
                @lang('admin::app.cod.assignment.distribute')
            </button>
        </form>

        <p class="w-full text-xs text-gray-500 dark:text-gray-400">
            @lang('admin::app.cod.assignment.daily-limit-info', ['limit' => $dailyLimit])
        </p>
    </div>

    <!-- Percentage rules -->
    <div class="mt-8 rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
        <p class="border-b border-gray-200 p-4 text-base font-semibold text-gray-800 dark:border-gray-800 dark:text-white">
            @lang('admin::app.cod.assignment.percentage-rules')
        </p>

        <p class="p-4 pb-0 text-sm text-gray-600 dark:text-gray-300">
            @lang('admin::app.cod.assignment.percentage-rules-info')
        </p>

        @if ($percentageRules->isEmpty())
            <p class="p-4 text-sm text-gray-600 dark:text-gray-300">
                @lang('admin::app.cod.assignment.no-percentage-rules')
            </p>
        @else
            <table class="mt-2 w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-gray-600 dark:border-gray-800 dark:text-gray-300">
                        <th class="p-4 font-semibold">@lang('admin::app.cod.assignment.confirmer')</th>
                        <th class="p-4 font-semibold">@lang('admin::app.cod.assignment.weight')</th>
                        <th class="p-4 font-semibold">@lang('admin::app.cod.assignment.assigned-today')</th>
                        <th class="p-4 font-semibold"></th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($percentageRules as $rule)
                        <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                            <td class="p-4 text-gray-800 dark:text-white">{{ $rule->confirmer?->name ?? '—' }}</td>
                            <td class="p-4 font-semibold text-gray-800 dark:text-white">{{ $rule->weight }}%</td>

                            <td class="p-4 text-gray-800 dark:text-white">
                                {{ $assignedToday[$rule->admin_id] ?? 0 }} / {{ $dailyLimit }}
                            </td>

                            <td class="p-4 text-right">
                                <form method="POST" action="{{ route('admin.cod.assignment.rules.destroy', $rule) }}" onsubmit="return confirm('@lang('admin::app.cod.assignment.delete-confirm')')">
                                    @csrf
                                    @method('DELETE')

                                    <button type="submit" class="text-sm font-semibold text-red-600 hover:underline dark:text-red-400">
                                        @lang('admin::app.cod.assignment.delete')
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <form method="POST" action="{{ route('admin.cod.assignment.rules.store') }}" class="flex flex-wrap items-center gap-2 border-t border-gray-200 p-4 dark:border-gray-800">
            @csrf

            <input type="hidden" name="type" value="percentage">

            <select name="admin_id" required class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 dark:border-gray-800 dark:bg-gray-900 dark:text-white">
                <option value="" disabled selected>@lang('admin::app.cod.assignment.choose-confirmer')</option>

                @foreach ($confirmers as $confirmer)
                    <option value="{{ $confirmer->id }}">{{ $confirmer->name }}</option>
                @endforeach
            </select>

            <input
                type="number"
                name="weight"
                min="0"
                max="100"
                required
                placeholder="@lang('admin::app.cod.assignment.weight-placeholder')"
                class="w-28 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 dark:border-gray-800 dark:bg-gray-900 dark:text-white"
            >

            <button type="submit" class="primary-button">
                @lang('admin::app.cod.assignment.add-rule')
            </button>
        </form>
    </div>

    <!-- Product rules -->
    <div class="mt-8 rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
        <p class="border-b border-gray-200 p-4 text-base font-semibold text-gray-800 dark:border-gray-800 dark:text-white">
            @lang('admin::app.cod.assignment.product-rules')
        </p>

        <p class="p-4 pb-0 text-sm text-gray-600 dark:text-gray-300">
            @lang('admin::app.cod.assignment.product-rules-info')
        </p>

        @if ($productRules->isEmpty())
            <p class="p-4 text-sm text-gray-600 dark:text-gray-300">
                @lang('admin::app.cod.assignment.no-product-rules')
            </p>
        @else
            <table class="mt-2 w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-gray-600 dark:border-gray-800 dark:text-gray-300">
                        <th class="p-4 font-semibold">@lang('admin::app.cod.assignment.confirmer')</th>
                        <th class="p-4 font-semibold">@lang('admin::app.cod.assignment.sku')</th>
                        <th class="p-4 font-semibold"></th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($productRules as $rule)
                        <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                            <td class="p-4 text-gray-800 dark:text-white">{{ $rule->confirmer?->name ?? '—' }}</td>
                            <td class="p-4 font-semibold text-gray-800 dark:text-white">{{ $rule->product_sku }}</td>

                            <td class="p-4 text-right">
                                <form method="POST" action="{{ route('admin.cod.assignment.rules.destroy', $rule) }}" onsubmit="return confirm('@lang('admin::app.cod.assignment.delete-confirm')')">
                                    @csrf
                                    @method('DELETE')

                                    <button type="submit" class="text-sm font-semibold text-red-600 hover:underline dark:text-red-400">
                                        @lang('admin::app.cod.assignment.delete')
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <form method="POST" action="{{ route('admin.cod.assignment.rules.store') }}" class="flex flex-wrap items-center gap-2 border-t border-gray-200 p-4 dark:border-gray-800">
            @csrf

            <input type="hidden" name="type" value="product">

            <select name="admin_id" required class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 dark:border-gray-800 dark:bg-gray-900 dark:text-white">
                <option value="" disabled selected>@lang('admin::app.cod.assignment.choose-confirmer')</option>

                @foreach ($confirmers as $confirmer)
                    <option value="{{ $confirmer->id }}">{{ $confirmer->name }}</option>
                @endforeach
            </select>

            <input
                type="text"
                name="product_sku"
                required
                maxlength="255"
                placeholder="@lang('admin::app.cod.assignment.sku-placeholder')"
                class="w-48 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 dark:border-gray-800 dark:bg-gray-900 dark:text-white"
            >

            <button type="submit" class="primary-button">
                @lang('admin::app.cod.assignment.add-rule')
            </button>
        </form>
    </div>
</x-admin::layouts>
