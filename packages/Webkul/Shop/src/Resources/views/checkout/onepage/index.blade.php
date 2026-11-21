<!-- SEO Meta Content -->
@push('meta')
    <meta name="description" content="@lang('shop::app.checkout.onepage.index.checkout')"/>

    <meta name="keywords" content="@lang('shop::app.checkout.onepage.index.checkout')"/>
@endPush

<x-shop::layouts
    :has-header="false"
    :has-feature="false"
    :has-footer="false"
>
    <!-- Page Title -->
    <x-slot:title>
        @lang('shop::app.checkout.onepage.index.checkout')
    </x-slot>

    {!! view_render_event('bagisto.shop.checkout.onepage.header.before') !!}

    <!-- Page Header -->
    <div class="flex-wrap">
        <div class="flex w-full justify-between border border-b border-l-0 border-r-0 border-t-0 px-[60px] py-4 max-lg:px-8 max-sm:px-4">
            <div class="flex items-center gap-x-14 max-[1180px]:gap-x-9">
                <a
                    href="{{ route('shop.home.index') }}"
                    class="flex min-h-[30px]"
                    aria-label="@lang('shop::checkout.onepage.index.bagisto')"
                >
                    <img
                        src="{{ core()->getCurrentChannel()->logo_url ?? bagisto_asset('images/logo.svg') }}"
                        alt="{{ config('app.name') }}"
                        width="131"
                        height="29"
                    >
                </a>
            </div>

            @guest('customer')
                @include('shop::checkout.login')
            @endguest
        </div>
    </div>

    {!! view_render_event('bagisto.shop.checkout.onepage.header.after') !!}

    <!-- Page Content -->
    <div class="container px-[60px] max-lg:px-8 max-sm:px-4">

        {!! view_render_event('bagisto.shop.checkout.onepage.breadcrumbs.before') !!}

        <!-- Breadcrumbs -->
        @if ((core()->getConfigData('general.general.breadcrumbs.shop')))
            <x-shop::breadcrumbs name="checkout" />
        @endif

        {!! view_render_event('bagisto.shop.checkout.onepage.breadcrumbs.after') !!}

        <!-- Checkout Vue Component -->
        <v-checkout>
            <!-- Shimmer Effect -->
            <x-shop::shimmer.checkout.onepage />
        </v-checkout>
    </div>

    @pushOnce('scripts')
        <script
            type="text/x-template"
            id="v-checkout-template"
        >
            <template v-if="! cart">
                <!-- Shimmer Effect -->
                <x-shop::shimmer.checkout.onepage />
            </template>

            <template v-else>
                <div class="grid grid-cols-[minmax(0,1fr)_400px] items-start gap-8 max-lg:grid-cols-[1fr] max-lg:gap-6 max-md:gap-5">
                    <!-- Included Checkout Summary Blade File For Mobile view -->
                    <div class="hidden max-md:order-2 max-md:block">
                        @include('shop::checkout.onepage.summary')
                    </div>

                    <div
                        class="overflow-y-auto max-md:order-1 max-md:grid max-md:gap-4"
                        id="steps-container"
                    >
                        <!-- Included Addresses Blade File -->
                        <template v-if="['address', 'shipping', 'payment', 'review'].includes(currentStep)">
                            @include('shop::checkout.onepage.address')
                        </template>

                        <!-- Shipping and payment methods are hidden in the streamlined checkout flow -->
                        <template v-if="false"></template>
                        <template v-if="false"></template>
                    </div>

                    <!-- Included Checkout Summary Blade File For Desktop view -->
                    <div class="sticky top-6 block h-max w-full max-w-[400px] rounded-xl border border-zinc-200 bg-white p-6 shadow-sm max-lg:static max-lg:max-w-full max-md:order-3 max-md:p-4">
                        <div class="block max-md:hidden">
                            @include('shop::checkout.onepage.summary')
                        </div>

                        <div
                            class="flex justify-end"
                            v-if="canPlaceOrder"
                        >
                            <template v-if="(selectedPaymentMethod || cart.payment_method) == 'paypal_smart_button'">
                                {!! view_render_event('bagisto.shop.checkout.onepage.summary.paypal_smart_button.before') !!}

                                <!-- Paypal Smart Button Vue Component -->
                                <v-paypal-smart-button></v-paypal-smart-button>

                                {!! view_render_event('bagisto.shop.checkout.onepage.summary.paypal_smart_button.after') !!}
                            </template>

                            <template v-else>
                                <x-shop::button
                                    type="button"
                                    class="primary-button !w-full !max-w-full rounded-lg bg-navyBlue px-6 py-3"
                                    :title="trans('shop::app.checkout.onepage.summary.place-order')"
                                    ::disabled="isPlacingOrder"
                                    ::loading="isPlacingOrder"
                                    @click="placeOrder"
                                />
                            </template>
                        </div>
                    </div>
                </div>
            </template>
        </script>

        <script type="module">
            app.component('v-checkout', {
                template: '#v-checkout-template',

                data() {
                    return {
                        cart: null,

                        displayTax: {
                            prices: "{{ core()->getConfigData('sales.taxes.shopping_cart.display_prices') }}",

                            subtotal: "{{ core()->getConfigData('sales.taxes.shopping_cart.display_subtotal') }}",
                            
                            shipping: "{{ core()->getConfigData('sales.taxes.shopping_cart.display_shipping_amount') }}",
                        },

                        isPlacingOrder: false,

                        currentStep: 'review',

                        shippingMethods: null,

                        paymentMethods: null,

                        selectedPaymentMethod: null,

                        placeOrderAfterAddress: false,

                        canPlaceOrder: true,
                    }
                },

                mounted() {
                    this.getCart();
                },

                methods: {
                    getCart() {
                        this.$axios.get("{{ route('shop.checkout.onepage.summary') }}")
                            .then(response => {
                                this.cart = response.data.data;
                                this.currentStep = 'review';
                                this.canPlaceOrder = true;

                                this.scrollToCurrentStep();
                            })
                            .catch(error => {});
                    },

                    stepForward(step) {
                        this.currentStep = step;

                        if (step == 'review' || step == 'address') {
                            this.canPlaceOrder = true;

                            return;
                        }

                        this.canPlaceOrder = false;

                        if (this.currentStep == 'shipping') {
                            this.shippingMethods = null;
                        } else if (this.currentStep == 'payment') {
                            this.paymentMethods = null;
                        }
                    },

                    stepProcessed(data) {
                        if (this.currentStep == 'shipping') {
                            this.shippingMethods = data;

                            if (this.placeOrderAfterAddress) {
                                this.completeOrderAfterAddress(data);

                                return;
                            }

                            const shippingRates = Object.values(data || {}).flatMap(method => method.rates || []);
                            const defaultShippingMethod = shippingRates[0]?.method;

                            if (defaultShippingMethod) {
                                this.$axios.post("{{ route('shop.checkout.onepage.shipping_methods.store') }}", {
                                    shipping_method: defaultShippingMethod,
                                })
                                    .then(response => {
                                        const paymentMethods = response.data?.payment_methods || response.data;
                                        const list = Array.isArray(paymentMethods) ? paymentMethods : (paymentMethods?.payment_methods || []);
                                        const defaultPaymentMethod = list[0];

                                        if (defaultPaymentMethod) {
                                            this.selectedPaymentMethod = defaultPaymentMethod.method;

                                            return this.$axios.post("{{ route('shop.checkout.onepage.payment_methods.store') }}", {
                                                payment: defaultPaymentMethod,
                                            });
                                        }
                                    })
                                    .then(() => this.getCart())
                                    .catch(() => {});
                            }
                        } else if (this.currentStep == 'payment') {
                            this.paymentMethods = data;

                            if (this.placeOrderAfterAddress) {
                                this.completeOrderAfterAddress(data);

                                return;
                            }

                            const list = Array.isArray(data) ? data : (data?.payment_methods || []);

                            // Jemla : le paiement à la livraison est le mode par défaut
                            // du magasin — ne pas prendre le 1er de la liste (Stripe).
                            const defaultPaymentMethod = list.find((method) => method.method === 'cashondelivery') || list[0];

                            if (defaultPaymentMethod) {
                                this.selectedPaymentMethod = defaultPaymentMethod.method;

                                this.$axios.post("{{ route('shop.checkout.onepage.payment_methods.store') }}", {
                                    payment: defaultPaymentMethod,
                                })
                                    .then(() => this.getCart())
                                    .catch(() => {});
                            }
                        }

                        if (this.currentStep != 'shipping' && this.currentStep != 'payment') {
                            this.getCart();
                        }
                    },

                    scrollToCurrentStep() {
                        let container = document.getElementById('steps-container');

                        if (! container) {
                            return;
                        }

                        container.scrollIntoView({
                            behavior: 'smooth',
                            block: 'end'
                        });
                    },

                    setSelectedPaymentMethod(method) {
                        this.selectedPaymentMethod = method;
                    },

                    completeOrderAfterAddress(data) {
                        let paymentMethods = data;

                        if (this.currentStep == 'shipping') {
                            const shippingRates = Object.values(data || {}).flatMap(method => method.rates || []);
                            const defaultShippingMethod = shippingRates[0]?.method;

                            if (! defaultShippingMethod) {
                                this.failAddressCheckout('No shipping method is available.');

                                return;
                            }

                            this.$axios.post("{{ route('shop.checkout.onepage.shipping_methods.store') }}", {
                                shipping_method: defaultShippingMethod,
                            }).then(response => {
                                paymentMethods = response.data?.payment_methods || response.data;

                                this.saveDefaultPaymentMethod(paymentMethods);
                            }).catch(error => this.failAddressCheckout(error.response?.data?.message));

                            return;
                        }

                        this.saveDefaultPaymentMethod(paymentMethods);
                    },

                    saveDefaultPaymentMethod(paymentMethods) {
                        const list = Array.isArray(paymentMethods) ? paymentMethods : (paymentMethods?.payment_methods || []);

                        // Jemla : le paiement à la livraison est le mode par défaut
                        // du magasin — ne pas prendre le 1er de la liste (Stripe).
                        const defaultPaymentMethod = list.find((method) => method.method === 'cashondelivery') || list[0];

                        if (! defaultPaymentMethod) {
                            this.failAddressCheckout('No payment method is available.');

                            return;
                        }

                        this.selectedPaymentMethod = defaultPaymentMethod.method;

                        this.$axios.post("{{ route('shop.checkout.onepage.payment_methods.store') }}", {
                            payment: defaultPaymentMethod,
                        }).then(() => {
                            return this.$axios.get("{{ route('shop.checkout.onepage.summary') }}");
                        }).then(response => {
                            this.cart = response.data.data;
                            this.currentStep = 'review';
                            this.canPlaceOrder = true;
                            this.placeOrderAfterAddress = false;

                            this.placeOrder();
                        }).catch(error => this.failAddressCheckout(error.response?.data?.message));
                    },

                    failAddressCheckout(message) {
                        this.placeOrderAfterAddress = false;
                        this.currentStep = 'review';
                        this.canPlaceOrder = true;

                        this.$emitter.emit('add-flash', {
                            type: 'error',
                            message: message || 'Unable to complete checkout.',
                        });
                    },

                    placeOrder() {
                        if ((this.selectedPaymentMethod || this.cart.payment_method) == 'paypal_smart_button') {
                            return;
                        }

                        const needsAddress = ! this.cart.billing_address;
                        const needsShipping = this.cart.have_stockable_items && (! this.cart.shipping_address || ! this.cart.shipping_method);
                        const needsPayment = ! this.cart.payment_method;

                        if (needsAddress || needsShipping || needsPayment) {
                            this.placeOrderAfterAddress = true;

                            if (this.cart.is_guest) {
                                if (this.$refs.guestAddress?.submitAddress) {
                                    this.$refs.guestAddress.submitAddress();
                                }
                            } else {
                                if (this.$refs.customerAddress?.submitAddress) {
                                    this.$refs.customerAddress.submitAddress();
                                }
                            }

                            return;
                        }

                        this.isPlacingOrder = true;

                        this.$axios.post('{{ route('shop.checkout.onepage.orders.store') }}')
                            .then(response => {
                                if (response.data.data.redirect) {
                                    window.location.href = response.data.data.redirect_url;
                                } else {
                                    window.location.href = '{{ route('shop.checkout.onepage.success') }}';
                                }

                                this.isPlacingOrder = false;
                            })
                            .catch(error => {
                                this.isPlacingOrder = false

                                this.$emitter.emit('add-flash', { type: 'error', message: error.response?.data?.message || 'Error placing order.' });
                            });
                    }
                },
            });
        </script>
    @endPushOnce
</x-shop::layouts>
