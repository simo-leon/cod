<v-trust-badges :errors="errors">
    <x-admin::shimmer.settings.themes.product-carousel />
</v-trust-badges>

<!-- Trust Badges Vue Component -->
@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-trust-badges-template"
    >
        <div class="flex flex-1 flex-col gap-2 max-xl:flex-auto">
            <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                <div class="mb-2.5 flex items-center justify-between gap-x-2.5">
                    <div class="flex flex-col gap-1">
                        <p class="text-base font-semibold text-gray-800 dark:text-white">
                            @lang('admin::app.settings.themes.edit.trust-badges')
                        </p>

                        <p class="text-xs font-medium text-gray-500 dark:text-gray-300">
                            @lang('admin::app.settings.themes.edit.trust-badges-description')
                        </p>
                    </div>
                </div>

                <!-- Title -->
                <x-admin::form.control-group class="mb-2.5 pt-4">
                    <x-admin::form.control-group.label>
                        @lang('admin::app.settings.themes.edit.filter-title')
                    </x-admin::form.control-group.label>

                    <v-field
                        type="text"
                        name="{{ $currentLocale->code }}[options][title]"
                        value="{{ $theme->translate($currentLocale->code)->options['title'] ?? '' }}"
                        class="flex min-h-[39px] w-full rounded-md border px-3 py-2 text-sm text-gray-600 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 dark:focus:border-gray-400"
                        label="@lang('admin::app.settings.themes.edit.filter-title')"
                        placeholder="@lang('admin::app.settings.themes.edit.filter-title')"
                    >
                    </v-field>

                    <x-admin::form.control-group.error control-name="{{ $currentLocale->code }}[options][title]" />
                </x-admin::form.control-group>

                <p class="mb-2.5 text-xs text-gray-500 dark:text-gray-300">
                    @lang('admin::app.settings.themes.edit.trust-badges-hint')
                </p>

                <!-- Trust point 1 -->
                <x-admin::form.control-group>
                    <x-admin::form.control-group.label>
                        @lang('admin::app.settings.themes.edit.item-title', ['number' => 1])
                    </x-admin::form.control-group.label>

                    <v-field
                        type="text"
                        name="{{ $currentLocale->code }}[options][items][0][title]"
                        value="{{ $theme->translate($currentLocale->code)->options['items'][0]['title'] ?? '' }}"
                        class="flex min-h-[39px] w-full rounded-md border px-3 py-2 text-sm text-gray-600 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 dark:focus:border-gray-400"
                        label="@lang('admin::app.settings.themes.edit.item-title', ['number' => 1])"
                        placeholder="@lang('admin::app.settings.themes.edit.item-title', ['number' => 1])"
                    >
                    </v-field>

                    <x-admin::form.control-group.error control-name="{{ $currentLocale->code }}[options][items][0][title]" />
                </x-admin::form.control-group>

                <x-admin::form.control-group>
                    <x-admin::form.control-group.label>
                        @lang('admin::app.settings.themes.edit.item-text', ['number' => 1])
                    </x-admin::form.control-group.label>

                    <v-field
                        type="text"
                        name="{{ $currentLocale->code }}[options][items][0][text]"
                        value="{{ $theme->translate($currentLocale->code)->options['items'][0]['text'] ?? '' }}"
                        class="flex min-h-[39px] w-full rounded-md border px-3 py-2 text-sm text-gray-600 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 dark:focus:border-gray-400"
                        label="@lang('admin::app.settings.themes.edit.item-text', ['number' => 1])"
                        placeholder="@lang('admin::app.settings.themes.edit.item-text', ['number' => 1])"
                    >
                    </v-field>

                    <x-admin::form.control-group.error control-name="{{ $currentLocale->code }}[options][items][0][text]" />
                </x-admin::form.control-group>

                <!-- Trust point 2 -->
                <x-admin::form.control-group>
                    <x-admin::form.control-group.label>
                        @lang('admin::app.settings.themes.edit.item-title', ['number' => 2])
                    </x-admin::form.control-group.label>

                    <v-field
                        type="text"
                        name="{{ $currentLocale->code }}[options][items][1][title]"
                        value="{{ $theme->translate($currentLocale->code)->options['items'][1]['title'] ?? '' }}"
                        class="flex min-h-[39px] w-full rounded-md border px-3 py-2 text-sm text-gray-600 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 dark:focus:border-gray-400"
                        label="@lang('admin::app.settings.themes.edit.item-title', ['number' => 2])"
                        placeholder="@lang('admin::app.settings.themes.edit.item-title', ['number' => 2])"
                    >
                    </v-field>

                    <x-admin::form.control-group.error control-name="{{ $currentLocale->code }}[options][items][1][title]" />
                </x-admin::form.control-group>

                <x-admin::form.control-group>
                    <x-admin::form.control-group.label>
                        @lang('admin::app.settings.themes.edit.item-text', ['number' => 2])
                    </x-admin::form.control-group.label>

                    <v-field
                        type="text"
                        name="{{ $currentLocale->code }}[options][items][1][text]"
                        value="{{ $theme->translate($currentLocale->code)->options['items'][1]['text'] ?? '' }}"
                        class="flex min-h-[39px] w-full rounded-md border px-3 py-2 text-sm text-gray-600 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 dark:focus:border-gray-400"
                        label="@lang('admin::app.settings.themes.edit.item-text', ['number' => 2])"
                        placeholder="@lang('admin::app.settings.themes.edit.item-text', ['number' => 2])"
                    >
                    </v-field>

                    <x-admin::form.control-group.error control-name="{{ $currentLocale->code }}[options][items][1][text]" />
                </x-admin::form.control-group>

                <!-- Trust point 3 -->
                <x-admin::form.control-group>
                    <x-admin::form.control-group.label>
                        @lang('admin::app.settings.themes.edit.item-title', ['number' => 3])
                    </x-admin::form.control-group.label>

                    <v-field
                        type="text"
                        name="{{ $currentLocale->code }}[options][items][2][title]"
                        value="{{ $theme->translate($currentLocale->code)->options['items'][2]['title'] ?? '' }}"
                        class="flex min-h-[39px] w-full rounded-md border px-3 py-2 text-sm text-gray-600 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 dark:focus:border-gray-400"
                        label="@lang('admin::app.settings.themes.edit.item-title', ['number' => 3])"
                        placeholder="@lang('admin::app.settings.themes.edit.item-title', ['number' => 3])"
                    >
                    </v-field>

                    <x-admin::form.control-group.error control-name="{{ $currentLocale->code }}[options][items][2][title]" />
                </x-admin::form.control-group>

                <x-admin::form.control-group>
                    <x-admin::form.control-group.label>
                        @lang('admin::app.settings.themes.edit.item-text', ['number' => 3])
                    </x-admin::form.control-group.label>

                    <v-field
                        type="text"
                        name="{{ $currentLocale->code }}[options][items][2][text]"
                        value="{{ $theme->translate($currentLocale->code)->options['items'][2]['text'] ?? '' }}"
                        class="flex min-h-[39px] w-full rounded-md border px-3 py-2 text-sm text-gray-600 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 dark:focus:border-gray-400"
                        label="@lang('admin::app.settings.themes.edit.item-text', ['number' => 3])"
                        placeholder="@lang('admin::app.settings.themes.edit.item-text', ['number' => 3])"
                    >
                    </v-field>

                    <x-admin::form.control-group.error control-name="{{ $currentLocale->code }}[options][items][2][text]" />
                </x-admin::form.control-group>

                <!-- Trust point 4 -->
                <x-admin::form.control-group>
                    <x-admin::form.control-group.label>
                        @lang('admin::app.settings.themes.edit.item-title', ['number' => 4])
                    </x-admin::form.control-group.label>

                    <v-field
                        type="text"
                        name="{{ $currentLocale->code }}[options][items][3][title]"
                        value="{{ $theme->translate($currentLocale->code)->options['items'][3]['title'] ?? '' }}"
                        class="flex min-h-[39px] w-full rounded-md border px-3 py-2 text-sm text-gray-600 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 dark:focus:border-gray-400"
                        label="@lang('admin::app.settings.themes.edit.item-title', ['number' => 4])"
                        placeholder="@lang('admin::app.settings.themes.edit.item-title', ['number' => 4])"
                    >
                    </v-field>

                    <x-admin::form.control-group.error control-name="{{ $currentLocale->code }}[options][items][3][title]" />
                </x-admin::form.control-group>

                <x-admin::form.control-group>
                    <x-admin::form.control-group.label>
                        @lang('admin::app.settings.themes.edit.item-text', ['number' => 4])
                    </x-admin::form.control-group.label>

                    <v-field
                        type="text"
                        name="{{ $currentLocale->code }}[options][items][3][text]"
                        value="{{ $theme->translate($currentLocale->code)->options['items'][3]['text'] ?? '' }}"
                        class="flex min-h-[39px] w-full rounded-md border px-3 py-2 text-sm text-gray-600 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 dark:focus:border-gray-400"
                        label="@lang('admin::app.settings.themes.edit.item-text', ['number' => 4])"
                        placeholder="@lang('admin::app.settings.themes.edit.item-text', ['number' => 4])"
                    >
                    </v-field>

                    <x-admin::form.control-group.error control-name="{{ $currentLocale->code }}[options][items][3][text]" />
                </x-admin::form.control-group>
            </div>
        </div>
    </script>

    <script type="module">
        app.component('v-trust-badges', {
            template: '#v-trust-badges-template',

            props: ['errors'],
        });
    </script>
@endPushOnce
