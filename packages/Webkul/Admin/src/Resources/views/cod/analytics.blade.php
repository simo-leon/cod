<x-admin::layouts>
    <x-slot:title>
        @lang('admin::app.cod.analytics.title')
    </x-slot>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <div class="grid gap-1.5">
            <p class="text-xl font-bold !leading-normal text-gray-800 dark:text-white">
                @lang('admin::app.cod.analytics.title')
            </p>

            <p class="!leading-normal text-gray-600 dark:text-gray-300">
                @lang('admin::app.cod.analytics.subtitle')
            </p>
        </div>

        <div class="flex gap-2">
            <a href="{{ $dashboardUrl }}" class="secondary-button">
                @lang('admin::app.cod.analytics.back-to-dashboard')
            </a>

            @unless ($isScoped ?? false)
                <a href="{{ $settingsUrl }}" class="primary-button">
                    @lang('admin::app.cod.analytics.settings')
                </a>
            @endunless
        </div>
    </div>

    <!-- Filters: period, city, delivery provider, product. They apply to every figure below. -->
    <form method="GET" action="{{ route('admin.cod.analytics.index') }}" class="mt-8 flex flex-wrap items-end gap-3 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
        <div class="grid gap-1">
            <label for="from" class="text-xs font-semibold text-gray-600 dark:text-gray-300">@lang('admin::app.cod.analytics.from')</label>
            <input type="date" id="from" name="from" value="{{ $analytics['filters']['from'] }}" class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white">
        </div>

        <div class="grid gap-1">
            <label for="to" class="text-xs font-semibold text-gray-600 dark:text-gray-300">@lang('admin::app.cod.analytics.to')</label>
            <input type="date" id="to" name="to" value="{{ $analytics['filters']['to'] }}" class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white">
        </div>

        <div class="grid gap-1">
            <label for="city" class="text-xs font-semibold text-gray-600 dark:text-gray-300">@lang('admin::app.cod.analytics.city')</label>
            <select id="city" name="city" class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white">
                <option value="">@lang('admin::app.cod.analytics.all-cities')</option>
                @foreach ($analytics['filter_options']['cities'] as $city)
                    <option value="{{ $city }}" @selected($analytics['filters']['city'] === $city)>{{ $city }}</option>
                @endforeach
            </select>
        </div>

        <div class="grid gap-1">
            <label for="provider" class="text-xs font-semibold text-gray-600 dark:text-gray-300">@lang('admin::app.cod.analytics.provider')</label>
            <select id="provider" name="provider" class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white">
                <option value="">@lang('admin::app.cod.analytics.all-providers')</option>
                @foreach ($analytics['filter_options']['providers'] as $provider)
                    <option value="{{ $provider['name'] }}" @selected($analytics['filters']['provider'] === $provider['name'])>{{ $provider['label'] }}</option>
                @endforeach
            </select>
        </div>

        <div class="grid gap-1">
            <label for="sku" class="text-xs font-semibold text-gray-600 dark:text-gray-300">@lang('admin::app.cod.analytics.product')</label>
            <select id="sku" name="sku" class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white">
                <option value="">@lang('admin::app.cod.analytics.all-products')</option>
                @foreach ($analytics['filter_options']['skus'] as $sku => $name)
                    <option value="{{ $sku }}" @selected($analytics['filters']['sku'] === $sku)>{{ $sku }} — {{ $name }}</option>
                @endforeach
            </select>
        </div>

        <button type="submit" class="primary-button">
            @lang('admin::app.cod.analytics.apply-filters')
        </button>

        <a href="{{ route('admin.cod.analytics.index') }}" class="secondary-button">
            @lang('admin::app.cod.analytics.clear-filters')
        </a>
    </form>

    @php
        $overview = $analytics['overview'];
        $profit = $analytics['profit'];
        $currency = $analytics['currency'];
    @endphp

    <!-- Headline figures -->
    <div class="mt-8 grid grid-cols-5 gap-4 max-xl:grid-cols-2 max-sm:grid-cols-1">
        <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <p class="text-sm text-gray-600 dark:text-gray-300">@lang('admin::app.cod.analytics.orders')</p>
            <p class="mt-1 text-2xl font-bold text-gray-800 dark:text-white">{{ $overview['total'] }}</p>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <p class="text-sm text-gray-600 dark:text-gray-300">@lang('admin::app.cod.analytics.revenue')</p>
            <p class="mt-1 text-2xl font-bold text-gray-800 dark:text-white">{{ number_format($overview['revenue'], 2) }} {{ $currency }}</p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">@lang('admin::app.cod.analytics.revenue-info')</p>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <p class="text-sm text-gray-600 dark:text-gray-300">@lang('admin::app.cod.analytics.confirmation-rate')</p>
            <p class="mt-1 text-2xl font-bold text-gray-800 dark:text-white">{{ is_null($overview['confirmation_rate']) ? '—' : $overview['confirmation_rate'].'%' }}</p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">@lang('admin::app.cod.analytics.confirmation-rate-info')</p>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <p class="text-sm text-gray-600 dark:text-gray-300">@lang('admin::app.cod.analytics.delivery-rate')</p>
            <p class="mt-1 text-2xl font-bold text-gray-800 dark:text-white">{{ is_null($overview['delivery_rate']) ? '—' : $overview['delivery_rate'].'%' }}</p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">@lang('admin::app.cod.analytics.delivery-rate-info')</p>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <p class="text-sm text-gray-600 dark:text-gray-300">@lang('admin::app.cod.analytics.return-rate')</p>
            <p class="mt-1 text-2xl font-bold text-gray-800 dark:text-white">{{ is_null($overview['return_rate']) ? '—' : $overview['return_rate'].'%' }}</p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">@lang('admin::app.cod.analytics.return-rate-info')</p>
        </div>
    </div>

    <!-- Orders by status and by source -->
    <div class="mt-8 grid grid-cols-2 gap-4 max-lg:grid-cols-1">
        <div class="rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
            <p class="border-b border-gray-200 p-4 text-base font-semibold text-gray-800 dark:border-gray-800 dark:text-white">
                @lang('admin::app.cod.analytics.by-status-title')
            </p>

            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-gray-600 dark:border-gray-800 dark:text-gray-300">
                        <th class="p-4 font-semibold">@lang('admin::app.cod.analytics.status')</th>
                        <th class="p-4 font-semibold">@lang('admin::app.cod.analytics.count')</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($overview['by_status'] as $status => $count)
                        <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                            <td class="p-4 text-gray-800 dark:text-white">@lang('admin::app.cod.orders.statuses.'.$status)</td>
                            <td class="p-4 font-semibold text-gray-800 dark:text-white">{{ $count }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
            <p class="border-b border-gray-200 p-4 text-base font-semibold text-gray-800 dark:border-gray-800 dark:text-white">
                @lang('admin::app.cod.analytics.by-source-title')
            </p>

            @if (empty($overview['by_source']))
                <p class="p-4 text-sm text-gray-600 dark:text-gray-300">
                    @lang('admin::app.cod.analytics.empty')
                </p>
            @else
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-600 dark:border-gray-800 dark:text-gray-300">
                            <th class="p-4 font-semibold">@lang('admin::app.cod.analytics.source')</th>
                            <th class="p-4 font-semibold">@lang('admin::app.cod.analytics.count')</th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($overview['by_source'] as $source => $count)
                            <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                                <td class="p-4 text-gray-800 dark:text-white">{{ \Illuminate\Support\Facades\Lang::has('admin::app.cod.orders.sources.'.$source) ? trans('admin::app.cod.orders.sources.'.$source) : $source }}</td>
                                <td class="p-4 font-semibold text-gray-800 dark:text-white">{{ $count }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

    <!-- Delivery rate by city -->
    <div class="mt-8 rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
        <p class="border-b border-gray-200 p-4 text-base font-semibold text-gray-800 dark:border-gray-800 dark:text-white">
            @lang('admin::app.cod.analytics.by-city-title')
        </p>

        <p class="border-b border-gray-200 p-4 text-sm text-gray-600 dark:border-gray-800 dark:text-gray-300">
            @lang('admin::app.cod.analytics.by-city-info')
        </p>

        @if (empty($analytics['by_city']))
            <p class="p-4 text-sm text-gray-600 dark:text-gray-300">
                @lang('admin::app.cod.analytics.empty')
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-600 dark:border-gray-800 dark:text-gray-300">
                            <th class="p-4 font-semibold">@lang('admin::app.cod.analytics.city')</th>
                            <th class="p-4 font-semibold">@lang('admin::app.cod.analytics.orders')</th>
                            <th class="p-4 font-semibold">@lang('admin::app.cod.analytics.confirmed')</th>
                            <th class="p-4 font-semibold">@lang('admin::app.cod.analytics.delivered')</th>
                            <th class="p-4 font-semibold">@lang('admin::app.cod.analytics.delivery-rate')</th>
                            <th class="p-4 font-semibold">@lang('admin::app.cod.analytics.revenue')</th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($analytics['by_city'] as $row)
                            <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                                <td class="p-4 font-semibold text-gray-800 dark:text-white">{{ $row['city'] ?? trans('admin::app.cod.analytics.unknown-city') }}</td>
                                <td class="p-4 text-gray-800 dark:text-white">{{ $row['orders'] }}</td>
                                <td class="p-4 text-gray-800 dark:text-white">{{ $row['confirmed'] }}</td>
                                <td class="p-4 text-gray-800 dark:text-white">{{ $row['delivered'] }}</td>
                                <td class="p-4 font-semibold text-gray-800 dark:text-white">{{ is_null($row['delivery_rate']) ? '—' : $row['delivery_rate'].'%' }}</td>
                                <td class="p-4 text-gray-800 dark:text-white">{{ number_format($row['revenue'], 2) }} {{ $currency }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <!-- Delivery rate by provider -->
    <div class="mt-8 rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
        <p class="border-b border-gray-200 p-4 text-base font-semibold text-gray-800 dark:border-gray-800 dark:text-white">
            @lang('admin::app.cod.analytics.by-provider-title')
        </p>

        <p class="border-b border-gray-200 p-4 text-sm text-gray-600 dark:border-gray-800 dark:text-gray-300">
            @lang('admin::app.cod.analytics.by-provider-info')
        </p>

        @if (empty($analytics['by_provider']))
            <p class="p-4 text-sm text-gray-600 dark:text-gray-300">
                @lang('admin::app.cod.analytics.empty')
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-600 dark:border-gray-800 dark:text-gray-300">
                            <th class="p-4 font-semibold">@lang('admin::app.cod.analytics.provider')</th>
                            <th class="p-4 font-semibold">@lang('admin::app.cod.analytics.parcels')</th>
                            <th class="p-4 font-semibold">@lang('admin::app.cod.analytics.orders')</th>
                            <th class="p-4 font-semibold">@lang('admin::app.cod.analytics.delivered')</th>
                            <th class="p-4 font-semibold">@lang('admin::app.cod.analytics.delivery-rate')</th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($analytics['by_provider'] as $row)
                            <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                                <td class="p-4 font-semibold text-gray-800 dark:text-white">{{ $row['label'] }}</td>
                                <td class="p-4 text-gray-800 dark:text-white">{{ $row['parcels'] }}</td>
                                <td class="p-4 text-gray-800 dark:text-white">{{ $row['orders'] }}</td>
                                <td class="p-4 text-gray-800 dark:text-white">{{ $row['delivered'] }}</td>
                                <td class="p-4 font-semibold text-gray-800 dark:text-white">{{ is_null($row['delivery_rate']) ? '—' : $row['delivery_rate'].'%' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <!-- Product analytics -->
    <div class="mt-8 rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
        <p class="border-b border-gray-200 p-4 text-base font-semibold text-gray-800 dark:border-gray-800 dark:text-white">
            @lang('admin::app.cod.analytics.products-title')
        </p>

        <p class="border-b border-gray-200 p-4 text-sm text-gray-600 dark:border-gray-800 dark:text-gray-300">
            @lang('admin::app.cod.analytics.decision-rule')
        </p>

        @if (empty($analytics['products']))
            <p class="p-4 text-sm text-gray-600 dark:text-gray-300">
                @lang('admin::app.cod.analytics.empty-products')
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-600 dark:border-gray-800 dark:text-gray-300">
                            <th class="p-4 font-semibold">@lang('admin::app.cod.analytics.product')</th>
                            <th class="p-4 font-semibold">@lang('admin::app.cod.analytics.orders')</th>
                            <th class="p-4 font-semibold">@lang('admin::app.cod.analytics.confirmation-rate')</th>
                            <th class="p-4 font-semibold">@lang('admin::app.cod.analytics.delivery-rate')</th>
                            <th class="p-4 font-semibold">@lang('admin::app.cod.analytics.unit-price')</th>
                            <th class="p-4 font-semibold">@lang('admin::app.cod.analytics.unit-cost')</th>
                            <th class="p-4 font-semibold">@lang('admin::app.cod.analytics.profit-per-unit')</th>
                            <th class="p-4 font-semibold">@lang('admin::app.cod.analytics.suggestion')</th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($analytics['products'] as $row)
                            <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                                <td class="p-4 text-gray-800 dark:text-white">
                                    <span class="font-semibold">{{ $row['sku'] }}</span>
                                    @if ($row['name'] !== '' && $row['name'] !== $row['sku'])
                                        <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $row['name'] }}</span>
                                    @endif
                                </td>
                                <td class="p-4 text-gray-800 dark:text-white">{{ $row['orders'] }}</td>
                                <td class="p-4 font-semibold text-gray-800 dark:text-white">{{ is_null($row['confirmation_rate']) ? '—' : $row['confirmation_rate'].'%' }}</td>
                                <td class="p-4 font-semibold text-gray-800 dark:text-white">{{ is_null($row['delivery_rate']) ? '—' : $row['delivery_rate'].'%' }}</td>
                                <td class="p-4 text-gray-800 dark:text-white">{{ is_null($row['unit_price']) ? trans('admin::app.cod.analytics.not-available') : number_format($row['unit_price'], 2).' '.$currency }}</td>
                                <td class="p-4 text-gray-800 dark:text-white">{{ is_null($row['unit_cost']) ? trans('admin::app.cod.analytics.no-cost-recorded') : number_format($row['unit_cost'], 2).' '.$currency }}</td>
                                <td class="p-4 font-semibold text-gray-800 dark:text-white">{{ is_null($row['profit_per_unit']) ? '—' : number_format($row['profit_per_unit'], 2).' '.$currency }}</td>
                                <td class="p-4">
                                    @if ($row['suggestion'] === 'scale')
                                        <span class="rounded-full bg-green-100 px-2.5 py-1 text-xs font-semibold text-green-800 dark:bg-green-900 dark:text-green-200">@lang('admin::app.cod.analytics.suggestion-scale')</span>
                                    @elseif ($row['suggestion'] === 'stop')
                                        <span class="rounded-full bg-red-100 px-2.5 py-1 text-xs font-semibold text-red-800 dark:bg-red-900 dark:text-red-200">@lang('admin::app.cod.analytics.suggestion-stop')</span>
                                    @elseif ($row['suggestion'] === 'watch')
                                        <span class="rounded-full bg-yellow-100 px-2.5 py-1 text-xs font-semibold text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200">@lang('admin::app.cod.analytics.suggestion-watch')</span>
                                    @else
                                        <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-800 dark:bg-gray-800 dark:text-gray-200">@lang('admin::app.cod.analytics.suggestion-not-enough-data')</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <!-- Net profit -->
    <div class="mt-8 rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
        <p class="border-b border-gray-200 p-4 text-base font-semibold text-gray-800 dark:border-gray-800 dark:text-white">
            @lang('admin::app.cod.analytics.profit-title')
        </p>

        <p class="border-b border-gray-200 p-4 text-sm text-gray-600 dark:border-gray-800 dark:text-gray-300">
            @lang('admin::app.cod.analytics.profit-formula')
        </p>

        @if (! empty($profit['missing']))
            <div class="border-b border-gray-200 bg-yellow-50 p-4 text-sm text-yellow-800 dark:border-gray-800 dark:bg-yellow-900/20 dark:text-yellow-200">
                @lang('admin::app.cod.analytics.profit-missing')
                <ul class="mt-1 list-disc pl-5">
                    @foreach ($profit['missing'] as $missing)
                        <li>@lang('admin::app.cod.analytics.profit-missing-'.$missing)</li>
                    @endforeach
                </ul>
                @unless ($isScoped ?? false)
                    <a href="{{ $settingsUrl }}" class="mt-2 inline-block font-semibold text-blue-600 hover:underline dark:text-blue-400">
                        @lang('admin::app.cod.analytics.settings')
                    </a>
                @endunless
            </div>
        @endif

        <table class="w-full text-left text-sm">
            <tbody>
                <tr class="border-b border-gray-100 dark:border-gray-800">
                    <td class="p-4 text-gray-800 dark:text-white">@lang('admin::app.cod.analytics.revenue')</td>
                    <td class="p-4 text-right font-semibold text-gray-800 dark:text-white">{{ number_format($profit['revenue'], 2) }} {{ $currency }}</td>
                </tr>
                <tr class="border-b border-gray-100 dark:border-gray-800">
                    <td class="p-4 text-gray-800 dark:text-white">@lang('admin::app.cod.analytics.product-cost', ['count' => $profit['delivered_count']])</td>
                    <td class="p-4 text-right font-semibold text-gray-800 dark:text-white">{{ is_null($profit['product_cost']) ? trans('admin::app.cod.analytics.not-available') : '− '.number_format($profit['product_cost'], 2).' '.$currency }}</td>
                </tr>
                <tr class="border-b border-gray-100 dark:border-gray-800">
                    <td class="p-4 text-gray-800 dark:text-white">@lang('admin::app.cod.analytics.commissions', ['count' => $profit['delivered_count']])</td>
                    <td class="p-4 text-right font-semibold text-gray-800 dark:text-white">{{ is_null($profit['commissions']) ? trans('admin::app.cod.analytics.not-available') : '− '.number_format($profit['commissions'], 2).' '.$currency }}</td>
                </tr>
                <tr class="border-b border-gray-100 dark:border-gray-800">
                    <td class="p-4 text-gray-800 dark:text-white">@lang('admin::app.cod.analytics.delivery-cost', ['count' => $profit['shipped_count']])</td>
                    <td class="p-4 text-right font-semibold text-gray-800 dark:text-white">{{ is_null($profit['delivery_cost']) ? trans('admin::app.cod.analytics.not-available') : '− '.number_format($profit['delivery_cost'], 2).' '.$currency }}</td>
                </tr>
                <tr class="border-b border-gray-100 dark:border-gray-800">
                    <td class="p-4 text-gray-800 dark:text-white">@lang('admin::app.cod.analytics.returns-cost', ['count' => $profit['returned_count']])</td>
                    <td class="p-4 text-right font-semibold text-gray-800 dark:text-white">{{ is_null($profit['returns_cost']) ? trans('admin::app.cod.analytics.not-available') : '− '.number_format($profit['returns_cost'], 2).' '.$currency }}</td>
                </tr>
                <tr>
                    <td class="p-4 text-base font-bold text-gray-800 dark:text-white">@lang('admin::app.cod.analytics.net-profit')</td>
                    <td class="p-4 text-right text-base font-bold text-gray-800 dark:text-white">
                        @if (is_null($profit['net_profit']))
                            @lang('admin::app.cod.analytics.not-available')
                        @else
                            {{ number_format($profit['net_profit'], 2) }} {{ $currency }}
                        @endif
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Team performance and commissions -->
    <div class="mt-8 rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
        <p class="border-b border-gray-200 p-4 text-base font-semibold text-gray-800 dark:border-gray-800 dark:text-white">
            @lang('admin::app.cod.analytics.confirmers-title')
        </p>

        @if (empty($analytics['confirmers']))
            <p class="p-4 text-sm text-gray-600 dark:text-gray-300">
                @lang('admin::app.cod.analytics.empty-confirmers')
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-600 dark:border-gray-800 dark:text-gray-300">
                            <th class="p-4 font-semibold">@lang('admin::app.cod.analytics.confirmer')</th>
                            <th class="p-4 font-semibold">@lang('admin::app.cod.analytics.assigned')</th>
                            <th class="p-4 font-semibold">@lang('admin::app.cod.analytics.confirmed')</th>
                            <th class="p-4 font-semibold">@lang('admin::app.cod.analytics.confirmation-rate')</th>
                            <th class="p-4 font-semibold">@lang('admin::app.cod.analytics.delivered')</th>
                            <th class="p-4 font-semibold">@lang('admin::app.cod.analytics.delivery-rate')</th>
                            <th class="p-4 font-semibold">@lang('admin::app.cod.analytics.commission-due')</th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($analytics['confirmers'] as $row)
                            <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                                <td class="p-4">
                                    <a
                                        href="{{ route('admin.cod.confirmers.show', $row['admin']) }}"
                                        class="font-semibold text-blue-600 hover:underline dark:text-blue-400"
                                    >
                                        {{ $row['admin']->name }}
                                    </a>
                                </td>
                                <td class="p-4 text-gray-800 dark:text-white">{{ $row['assigned'] }}</td>
                                <td class="p-4 text-gray-800 dark:text-white">{{ $row['confirmed'] }}</td>
                                <td class="p-4 font-semibold text-gray-800 dark:text-white">{{ is_null($row['confirmation_rate']) ? '—' : $row['confirmation_rate'].'%' }}</td>
                                <td class="p-4 text-gray-800 dark:text-white">{{ $row['delivered'] }}</td>
                                <td class="p-4 font-semibold text-gray-800 dark:text-white">{{ is_null($row['delivery_rate']) ? '—' : $row['delivery_rate'].'%' }}</td>
                                <td class="p-4 font-semibold text-gray-800 dark:text-white">{{ is_null($row['commission_due']) ? trans('admin::app.cod.analytics.not-available') : number_format($row['commission_due'], 2).' '.$currency }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Store-wide stock is not a confirmer's own numbers, so it is
        hidden from the scoped (confirmer) view. --}}
    @unless ($isScoped ?? false)
    <!-- Inventory -->
    <div class="mt-8 rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
        <p class="border-b border-gray-200 p-4 text-base font-semibold text-gray-800 dark:border-gray-800 dark:text-white">
            @lang('admin::app.cod.analytics.inventory-title')
        </p>

        <p class="border-b border-gray-200 p-4 text-sm text-gray-600 dark:border-gray-800 dark:text-gray-300">
            @lang('admin::app.cod.analytics.inventory-info')
        </p>

        @if (empty($analytics['inventory']))
            <p class="p-4 text-sm text-gray-600 dark:text-gray-300">
                @lang('admin::app.cod.analytics.empty-inventory')
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-600 dark:border-gray-800 dark:text-gray-300">
                            <th class="p-4 font-semibold">@lang('admin::app.cod.analytics.product')</th>
                            <th class="p-4 font-semibold">@lang('admin::app.cod.analytics.stock')</th>
                            <th class="p-4 font-semibold">@lang('admin::app.cod.analytics.unit-price')</th>
                            <th class="p-4 font-semibold">@lang('admin::app.cod.analytics.stock-value')</th>
                            <th class="p-4 font-semibold">@lang('admin::app.cod.analytics.stock-status')</th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($analytics['inventory'] as $row)
                            <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                                <td class="p-4 text-gray-800 dark:text-white">
                                    <span class="font-semibold">{{ $row['sku'] }}</span>
                                    @if ($row['name'] !== '' && $row['name'] !== $row['sku'])
                                        <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $row['name'] }}</span>
                                    @endif
                                </td>
                                <td class="p-4 text-gray-800 dark:text-white">{{ $row['qty'] }}</td>
                                <td class="p-4 text-gray-800 dark:text-white">{{ is_null($row['price']) ? trans('admin::app.cod.analytics.not-available') : number_format($row['price'], 2).' '.$currency }}</td>
                                <td class="p-4 font-semibold text-gray-800 dark:text-white">{{ is_null($row['value']) ? '—' : number_format($row['value'], 2).' '.$currency }}</td>
                                <td class="p-4">
                                    @if ($row['low_stock'])
                                        <span class="rounded-full bg-red-100 px-2.5 py-1 text-xs font-semibold text-red-800 dark:bg-red-900 dark:text-red-200">@lang('admin::app.cod.analytics.low-stock')</span>
                                    @else
                                        <span class="rounded-full bg-green-100 px-2.5 py-1 text-xs font-semibold text-green-800 dark:bg-green-900 dark:text-green-200">@lang('admin::app.cod.analytics.in-stock')</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
    @endunless
</x-admin::layouts>
