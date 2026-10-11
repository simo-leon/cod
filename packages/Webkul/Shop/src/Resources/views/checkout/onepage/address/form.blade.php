@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-checkout-address-form-template"
    >
        <div class="mt-2 space-y-3 max-md:mt-3">
            <x-shop::form.control-group class="hidden">
                <x-shop::form.control-group.control
                    type="text"
                    ::name="controlName + '.id'"
                    ::value="address.id"
                />
            </x-shop::form.control-group>

            <x-shop::form.control-group.control
                type="hidden"
                ::name="controlName + '.company_name'"
                ::value="address.company_name || ''"
            />

            {!! view_render_event('bagisto.shop.checkout.onepage.address.form.company_name.after') !!}

            <!-- First Name -->
            <x-shop::form.control-group>
                <x-shop::form.control-group.label class="required !mt-0 mb-2 text-sm font-medium text-zinc-700">
                    @lang('shop::app.checkout.onepage.address.full-name')
                </x-shop::form.control-group.label>

                <x-shop::form.control-group.control
                    type="text"
                    ::name="controlName + '.first_name'"
                    ::value="address.first_name || ''"
                    class="h-[46px] rounded-xl border border-zinc-300 bg-white px-4 text-base text-zinc-700 placeholder:text-zinc-400 focus:border-blue-600 focus:ring-0"
                    :placeholder="trans('shop::app.checkout.onepage.address.full-name')"
                />

                <x-shop::form.control-group.error ::name="controlName + '.first_name'" />
            </x-shop::form.control-group>

            {!! view_render_event('bagisto.shop.checkout.onepage.address.form.first_name.after') !!}

            <x-shop::form.control-group.control
                type="hidden"
                ::name="controlName + '.last_name'"
                ::value="address.last_name || 'Gest'"
            />

            {!! view_render_event('bagisto.shop.checkout.onepage.address.form.last_name.after') !!}

            <x-shop::form.control-group.control
                type="hidden"
                ::name="controlName + '.email'"
                ::value="address.email || 'gest@shop.ma'"
            />

            {!! view_render_event('bagisto.shop.checkout.onepage.address.form.email.after') !!}

            <template v-if="controlName=='billing'">
                <x-shop::form.control-group.control
                    type="hidden"
                    ::name="controlName + '.vat_id'"
                    ::value="address.vat_id || ''"
                />

                {!! view_render_event('bagisto.shop.checkout.onepage.address.form.vat_id.after') !!}
            </template>

            <!-- Street Address -->
            <x-shop::form.control-group>
                <x-shop::form.control-group.label class="required !mt-0 text-base font-medium text-zinc-700">
                    @lang('shop::app.checkout.onepage.address.street-address')
                </x-shop::form.control-group.label>

                <x-shop::form.control-group.control
                    type="text"
                    ::name="controlName + '.address.[0]'"
                    ::value="address.address[0]"
                    rules="required|address"
                    class="h-[52px] rounded-xl border border-zinc-300 bg-white px-4 text-base text-zinc-700 placeholder:text-zinc-400 focus:border-blue-600 focus:ring-0"
                    :label="trans('shop::app.checkout.onepage.address.street-address')"
                    :placeholder="trans('shop::app.checkout.onepage.address.street-address')"
                />

                <x-shop::form.control-group.error
                    class="mb-2"
                    ::name="controlName + '.address.[0]'"
                />

                @if (core()->getConfigData('customer.address.information.street_lines') > 1)
                    @for ($i = 1; $i < core()->getConfigData('customer.address.information.street_lines'); $i++)
                        <x-shop::form.control-group.control
                            type="text"
                            ::name="controlName + '.address.[{{ $i }}]'"
                            rules="address"
                            :label="trans('shop::app.checkout.onepage.address.street-address')"
                            :placeholder="trans('shop::app.checkout.onepage.address.street-address')"
                        />

                        <x-shop::form.control-group.error
                            class="mb-2"
                            ::name="controlName + '.address.[{{ $i }}]'"
                        />
                    @endfor
                @endif
            </x-shop::form.control-group>

            {!! view_render_event('bagisto.shop.checkout.onepage.address.form.address.after') !!}

            <x-shop::form.control-group.control
                type="hidden"
                ::name="controlName + '.country'"
                ::value="address.country || 'MA'"
                v-model="selectedCountry"
            />

            {!! view_render_event('bagisto.shop.checkout.onepage.address.form.country.after') !!}

            <x-shop::form.control-group.control
                type="hidden"
                ::name="controlName + '.state'"
                ::value="address.state || getDefaultStateCode()"
            />

            {!! view_render_event('bagisto.shop.checkout.onepage.address.form.state.after') !!}

            <!-- City -->
            <x-shop::form.control-group>
                <x-shop::form.control-group.label class="required !mt-0 mb-2 text-sm font-medium text-zinc-700">
                    @lang('shop::app.checkout.onepage.address.city')
                </x-shop::form.control-group.label>

                <x-shop::form.control-group.control
                    type="text"
                    ::name="controlName + '.city'"
                    ::value="address.city || ''"
                    rules="required"
                    class="h-[46px] rounded-xl border border-zinc-300 bg-white px-4 text-base text-zinc-700 placeholder:text-zinc-400 focus:border-blue-600 focus:ring-0"
                    :label="trans('shop::app.checkout.onepage.address.city')"
                    :placeholder="trans('shop::app.checkout.onepage.address.city')"
                />

                <x-shop::form.control-group.error ::name="controlName + '.city'" />
            </x-shop::form.control-group>

            {!! view_render_event('bagisto.shop.checkout.onepage.address.form.city.after') !!}

            <x-shop::form.control-group.control
                type="hidden"
                ::name="controlName + '.postcode'"
                ::value="address.postcode || '20000'"
            />

            {!! view_render_event('bagisto.shop.checkout.onepage.address.form.postcode.after') !!}

            <!-- Phone Number -->
            <x-shop::form.control-group>
                <x-shop::form.control-group.label class="required !mt-0 text-base font-medium text-zinc-700">
                    @lang('shop::app.checkout.onepage.address.telephone')
                </x-shop::form.control-group.label>

                <x-shop::form.control-group.control
                    type="text"
                    ::name="controlName + '.phone'"
                    ::value="address.phone"
                    rules="required|phone"
                    class="h-[52px] rounded-xl border border-zinc-300 bg-white px-4 text-base text-zinc-700 placeholder:text-zinc-400 focus:border-blue-600 focus:ring-0"
                    :label="trans('shop::app.checkout.onepage.address.telephone')"
                    :placeholder="trans('shop::app.checkout.onepage.address.telephone')"
                />

                <x-shop::form.control-group.error ::name="controlName + '.phone'" />
            </x-shop::form.control-group>

            {!! view_render_event('bagisto.shop.checkout.onepage.address.form.phone.after') !!}
        </div>
    </script>

    <script type="module">
        app.component('v-checkout-address-form', {
            template: '#v-checkout-address-form-template',

            props: {
                controlName: {
                    type: String,
                    required: true,
                },

                address: {
                    type: Object,

                    default: () => ({
                        id: 0,
                        company_name: '',
                        first_name: '',
                        last_name: '',
                        email: '',
                        address: [],
                        country: '',
                        state: '',
                        city: '',
                        postcode: '',
                        phone: '',
                    }),
                },
            },

            data() {
                return {
                    selectedCountry: this.address.country || 'MA',

                    countries: [],

                    states: null,
                }
            },

            computed: {
                haveStates() {
                    return !! this.states?.[this.selectedCountry]?.length;
                },
            },

            mounted() {
                this.applyDefaultValues();

                this.getCountries();

                this.getStates();
            },

            methods: {
                applyDefaultValues() {
                    if (! this.address) {
                        this.address = {};
                    }

                    if (! this.address.email) {
                        this.address.email = 'gest@shop.ma';
                    }

                    if (! this.address.country) {
                        this.address.country = 'MA';
                        this.selectedCountry = 'MA';
                    }

                    if (! this.address.state) {
                        this.address.state = this.getDefaultStateCode();
                    }

                    if (! this.address.postcode) {
                        this.address.postcode = '20000';
                    }
                },

                getDefaultStateCode() {
                    const countryCode = this.selectedCountry || this.address.country || 'MA';
                    const states = this.states?.[countryCode] ?? [];

                    const matchedState = states.find(state => {
                        const label = (state.default_name || state.name || '').toLowerCase();

                        return label.includes('casablanca') || label.includes('casablanc');
                    });

                    return matchedState?.code || 'casablanca';
                },

                getCountries() {
                    this.$axios.get("{{ route('shop.api.core.countries') }}")
                        .then(response => {
                            this.countries = response.data.data;
                        })
                        .catch(() => {});
                },

                getStates() {
                    this.$axios.get("{{ route('shop.api.core.states') }}")
                        .then(response => {
                            this.states = response.data.data;

                            if (! this.address.state) {
                                this.address.state = this.getDefaultStateCode();
                            }
                        })
                        .catch(() => {});
                },
            }
        });
    </script>
@endPushOnce
