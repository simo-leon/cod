<v-shop-by-category :errors="errors">
    <x-admin::shimmer.settings.themes.product-carousel />
</v-shop-by-category>

<!-- Shop By Category Vue Component -->
@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-shop-by-category-template"
    >
        <div class="flex flex-1 flex-col gap-2 max-xl:flex-auto">
            <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                <div class="mb-2.5 flex items-center justify-between gap-x-2.5">
                    <div class="flex flex-col gap-1">
                        <p class="text-base font-semibold text-gray-800 dark:text-white">
                            @lang('admin::app.settings.themes.edit.shop-by-category')
                        </p>

                        <p class="text-xs font-medium text-gray-500 dark:text-gray-300">
                            @lang('admin::app.settings.themes.edit.shop-by-category-description')
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

                <!-- Limit -->
                <x-admin::form.control-group>
                    <x-admin::form.control-group.label>
                        @lang('admin::app.settings.themes.edit.limit')
                    </x-admin::form.control-group.label>

                    <v-field
                        type="text"
                        name="{{ $currentLocale->code }}[options][limit]"
                        value="{{ $theme->translate($currentLocale->code)->options['limit'] ?? '' }}"
                        class="flex min-h-[39px] w-full rounded-md border px-3 py-2 text-sm text-gray-600 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 dark:focus:border-gray-400"
                        label="@lang('admin::app.settings.themes.edit.limit')"
                        placeholder="@lang('admin::app.settings.themes.edit.limit')"
                    >
                    </v-field>

                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-300">
                        @lang('admin::app.settings.themes.edit.use-global-default')
                    </p>

                    <x-admin::form.control-group.error control-name="{{ $currentLocale->code }}[options][limit]" />
                </x-admin::form.control-group>
            </div>
        </div>
    </script>

    <script type="module">
        app.component('v-shop-by-category', {
            template: '#v-shop-by-category-template',

            props: ['errors'],
        });
    </script>
@endPushOnce
