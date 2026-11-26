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
                    ::value="address.first_name || '{{ addslashes(trim((auth()->guard('customer')->user()?->first_name ?? '') . ' ' . (auth()->guard('customer')->user()?->last_name ?? ''))) }}'"
                    rules="required"
                    class="h-[46px] rounded-xl border border-zinc-300 bg-white px-4 text-base text-zinc-700 placeholder:text-zinc-400 focus:border-blue-600 focus:ring-0"
                    :label="trans('shop::app.checkout.onepage.address.full-name')"
                    :placeholder="trans('shop::app.checkout.onepage.address.full-name')"
                />

                <x-shop::form.control-group.error ::name="controlName + '.first_name'" />
            </x-shop::form.control-group>

            {!! view_render_event('bagisto.shop.checkout.onepage.address.form.first_name.after') !!}

            <x-shop::form.control-group.control
                type="hidden"
                ::name="controlName + '.last_name'"
                ::value="address.last_name || '{{ addslashes(auth()->guard('customer')->user()?->last_name ?: 'Client') }}'"
            />

            {!! view_render_event('bagisto.shop.checkout.onepage.address.form.last_name.after') !!}

            @if (! auth()->guard('customer')->check())
                <template v-if="controlName == 'billing'">
                    <!-- Email (guest): visible and required — the order
                         confirmation is sent to this address, so it must be
                         the customer's real email, never a placeholder. -->
                    <x-shop::form.control-group>
                        <x-shop::form.control-group.label class="required !mt-0 mb-2 text-sm font-medium text-zinc-700">
                            @lang('shop::app.checkout.onepage.address.email')
                        </x-shop::form.control-group.label>

                        <x-shop::form.control-group.control
                            type="email"
                            ::name="controlName + '.email'"
                            ::value="address.email || ''"
                            rules="required|email"
                            class="h-[46px] rounded-xl border border-zinc-300 bg-white px-4 text-base text-zinc-700 placeholder:text-zinc-400 focus:border-blue-600 focus:ring-0"
                            :label="trans('shop::app.checkout.onepage.address.email')"
                            placeholder="example@email.com"
                        />

                        <x-shop::form.control-group.error ::name="controlName + '.email'" />
                    </x-shop::form.control-group>
                </template>

                <template v-else>
                    <x-shop::form.control-group.control
                        type="hidden"
                        ::name="controlName + '.email'"
                        ::value="address.email || ''"
                    />
                </template>
            @else
                <x-shop::form.control-group.control
                    type="hidden"
                    ::name="controlName + '.email'"
                    ::value="address.email || '{{ addslashes(auth()->guard('customer')->user()?->email ?: '') }}'"
                />
            @endif

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
                    :placeholder="trans('shop::app.checkout.onepage.address.city-placeholder')"
                    list="morocco-cities-list"
                />

                <datalist id="morocco-cities-list">
                    <option value="Casablanca">الدار البيضاء / Casablanca</option>
                    <option value="Rabat">الرباط / Rabat</option>
                    <option value="Marrakech">مراكش / Marrakech</option>
                    <option value="Fès">فاس / Fès</option>
                    <option value="Tanger">طنجة / Tanger</option>
                    <option value="Agadir">أكادير / Agadir</option>
                    <option value="Meknès">مكناس / Meknès</option>
                    <option value="Oujda">وجدة / Oujda</option>
                    <option value="Kénitra">القنيطرة / Kénitra</option>
                    <option value="Tétouan">تطوان / Tétouan</option>
                    <option value="Safi">آسفي / Safi</option>
                    <option value="Mohammedia">المحمدية / Mohammedia</option>
                    <option value="Temara">تمارة / Temara</option>
                    <option value="El Jadida">الجديدة / El Jadida</option>
                    <option value="Béni Mellal">بني ملال / Béni Mellal</option>
                    <option value="Nador">الناظور / Nador</option>
                    <option value="Taza">تازة / Taza</option>
                    <option value="Settat">سطات / Settat</option>
                    <option value="Berrechid">برشيد / Berrechid</option>
                    <option value="Khouribga">خريبكة / Khouribga</option>
                    <option value="Taroudant">تارودانت / Taroudant</option>
                    <option value="Larache">العرائش / Larache</option>
                    <option value="Ksar El Kebir">القصر الكبير / Ksar El Kebir</option>
                    <option value="Khémisset">الخميسات / Khémisset</option>
                    <option value="Guelmim">كلميم / Guelmim</option>
                    <option value="Berkane">بركان / Berkane</option>
                    <option value="Taourirt">تاوريرت / Taourirt</option>
                    <option value="Ouarzazate">ورزازات / Ouarzazate</option>
                    <option value="Essaouira">الصويرة / Essaouira</option>
                    <option value="Al Hoceïma">الحسيمة / Al Hoceïma</option>
                    <option value="Sidi Kacem">سيدي قاسم / Sidi Kacem</option>
                    <option value="Sidi Slimane">سيدي سليمان / Sidi Slimane</option>
                    <option value="Errachidia">الرشيدية / Errachidia</option>
                    <option value="Dakhla">الداخلة / Dakhla</option>
                    <option value="Laâyoune">العيون / Laâyoune</option>
                </datalist>

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

                <div class="relative flex items-center">
                    <div class="pointer-events-none absolute flex select-none items-center gap-1.5 text-sm font-medium text-zinc-500 ltr:left-3 rtl:right-3">
                        <span class="text-base">🇲🇦</span>
                        <span>+212</span>
                        <span class="text-zinc-300">|</span>
                    </div>

                    <x-shop::form.control-group.control
                        type="text"
                        ::name="controlName + '.phone'"
                        ::value="address.phone || '{{ addslashes(auth()->guard('customer')->user()?->phone ?? '') }}'"
                        rules="required|phone"
                        class="h-[52px] w-full rounded-xl border border-zinc-300 bg-white text-base text-zinc-700 placeholder:text-zinc-400 focus:border-blue-600 focus:ring-0 ltr:pl-20 ltr:pr-4 rtl:pl-4 rtl:pr-20"
                        :label="trans('shop::app.checkout.onepage.address.telephone')"
                        placeholder="06 12 34 56 78"
                    />
                </div>

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
                        this.address.email = '{{ addslashes(auth()->guard('customer')->user()?->email ?: '') }}';
                    }

                    if (! this.address.last_name) {
                        this.address.last_name = '{{ addslashes(auth()->guard('customer')->user()?->last_name ?: 'Client') }}';
                    }

                    if (! this.address.first_name) {
                        this.address.first_name = '{{ addslashes(trim((auth()->guard('customer')->user()?->first_name ?? '') . ' ' . (auth()->guard('customer')->user()?->last_name ?? ''))) }}';
                    }

                    if (! this.address.phone) {
                        this.address.phone = '{{ addslashes(auth()->guard('customer')->user()?->phone ?? '') }}';
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
