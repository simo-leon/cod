<x-shop::layouts
	:has-header="true"
	:has-feature="false"
	:has-footer="true"
>
    <!-- Page Title -->
    <x-slot:title>
		@lang('shop::app.checkout.success.thanks')
    </x-slot>

	<!-- Page content -->
	<div class="container mt-8 px-[60px] max-lg:px-8">
		<div class="grid place-items-center gap-y-5 max-md:gap-y-2.5">
			{{ view_render_event('bagisto.shop.checkout.success.image.before', ['order' => $order]) }}

			<img
				class="max-md:h-[100px] max-md:w-[100px]"
				src="{{ bagisto_asset('images/thank-you.png') }}"
				alt="@lang('shop::app.checkout.success.thanks')"
				title="@lang('shop::app.checkout.success.thanks')"
                loading="lazy"
                decoding="async"
			>

			{{ view_render_event('bagisto.shop.checkout.success.image.after', ['order' => $order]) }}

			<p class="text-xl max-md:text-sm">
				@if (auth()->guard('customer')->user())
					@lang('shop::app.checkout.success.order-id-info', [
						'order_id' => '<a class="text-blue-700" href="'.route('shop.customers.account.orders.view', $order->id).'">'.$order->increment_id.'</a>'
					])
				@else
					@lang('shop::app.checkout.success.order-id-info', ['order_id' => $order->increment_id])
				@endif
			</p>

			<p class="font-medium md:text-2xl">
				@lang('shop::app.checkout.success.thanks')
			</p>

			<p class="text-xl text-zinc-500 max-md:text-center max-md:text-xs">
				@if (! empty($order->checkout_message))
					{!! nl2br($order->checkout_message) !!}
				@else
					@lang('shop::app.checkout.success.info')
				@endif
			</p>

			@php
				$waEnabled = core()->getConfigData('general.general.whatsapp.status');
				$storePhone = core()->getConfigData('general.general.whatsapp.phone_number');
				$orderMsgTemplate = core()->getConfigData('general.general.whatsapp.order_message');
				$customerName = $order->customer_full_name ?? ($order->billing_address?->name ?? '');
				$city = $order->shipping_address?->city ?? ($order->billing_address?->city ?? '');
				$total = core()->formatPrice($order->grand_total, $order->order_currency_code);

				if ($orderMsgTemplate) {
					$waMessage = str_replace(
						['{order_id}', '{total}', '{name}', '{city}'],
						[$order->increment_id, $total, $customerName, $city],
						$orderMsgTemplate
					);
				} else {
					$waMessage = trans('shop::app.checkout.success.whatsapp-default-message')
						. "\n📦 " . trans('shop::app.checkout.success.whatsapp-order-id') . ": #" . $order->increment_id
						. "\n💰 " . trans('shop::app.checkout.success.whatsapp-total') . ": " . $total
						. ($customerName ? "\n👤 " . trans('shop::app.checkout.success.whatsapp-customer-name') . ": " . $customerName : "")
						. ($city ? "\n📍 " . trans('shop::app.checkout.success.whatsapp-city') . ": " . $city : "")
						. "\n" . trans('shop::app.checkout.success.whatsapp-thanks');
				}

				$cleanPhone = preg_replace('/[^0-9]/', '', (string)$storePhone);
				$whatsappUrl = 'https://api.whatsapp.com/send?' . ($cleanPhone ? 'phone=' . $cleanPhone . '&' : '') . 'text=' . urlencode($waMessage);
			@endphp

			@if ($waEnabled && $cleanPhone)
				<!-- WhatsApp Order Confirmation Button -->
				<div
					class="my-4 w-full max-w-[460px] overflow-hidden rounded-2xl bg-white shadow-lg"
					style="border: 2px solid #25D366;"
				>
					<div
						class="px-5 py-3 text-center"
						style="background-color: #25D366;"
					>
						<p
							class="text-base font-bold"
							style="color: #ffffff; margin: 0;"
						>
							@lang('shop::app.checkout.success.whatsapp-title')
						</p>
					</div>
					<div class="px-5 py-4 text-center">
						<p class="text-sm text-zinc-600" style="margin: 0 0 16px 0;">
							@lang('shop::app.checkout.success.whatsapp-help')
						</p>
						<a
							href="{{ $whatsappUrl }}"
							target="_blank"
							rel="noopener noreferrer"
							class="inline-flex w-full items-center justify-center gap-2.5 rounded-xl px-6 py-3.5 text-base font-bold shadow-md transition-all duration-200 active:scale-[0.98]"
							style="background-color: #25D366; color: #ffffff !important; text-decoration: none; cursor: pointer;"
							onmouseover="this.style.backgroundColor='#1da851'"
							onmouseout="this.style.backgroundColor='#25D366'"
						>
							<svg style="width: 24px; height: 24px; min-width: 24px; fill: #ffffff;" viewBox="0 0 24 24">
								<path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/>
							</svg>
							<span style="color: #ffffff !important; font-weight: 700; font-size: 16px;">
								@lang('shop::app.checkout.success.whatsapp-btn')
							</span>
						</a>
					</div>
				</div>
			@endif

			{{ view_render_event('bagisto.shop.checkout.success.continue-shopping.before', ['order' => $order]) }}

			<a href="{{ route('shop.home.index') }}">
				<div class="w-max cursor-pointer rounded-2xl bg-navyBlue px-11 py-3 text-center text-base font-medium text-white max-md:rounded-lg max-md:px-6 max-md:py-1.5">
             		@lang('shop::app.checkout.cart.index.continue-shopping')
				</div>
			</a>

			{{ view_render_event('bagisto.shop.checkout.success.continue-shopping.after', ['order' => $order]) }}
		</div>
	</div>
</x-shop::layouts>
