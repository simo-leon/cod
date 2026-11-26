<v-notification-bar :errors="errors">
    <x-admin::shimmer.settings.themes.product-carousel />
</v-notification-bar>

<!-- Notification Bar Vue Component -->
@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-notification-bar-template"
    >
        <div class="flex flex-1 flex-col gap-2 max-xl:flex-auto">
            <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                <div class="mb-2.5 flex items-center justify-between gap-x-2.5">
                    <div class="flex flex-col gap-1">
                        <p class="text-base font-semibold text-gray-800 dark:text-white">
                            @lang('admin::app.settings.themes.edit.notification-bar')
                        </p>

                        <p class="text-xs font-medium text-gray-500 dark:text-gray-300">
                            @lang('admin::app.settings.themes.edit.notification-bar-description')
                        </p>
                    </div>
                </div>

                <!-- Message -->
                <x-admin::form.control-group class="mb-2.5 pt-4">
                    <x-admin::form.control-group.label class="required">
                        @lang('admin::app.settings.themes.edit.message')
                    </x-admin::form.control-group.label>

                    <v-field
                        type="text"
                        name="{{ $currentLocale->code }}[options][message]"
                        value="{{ $theme->translate($currentLocale->code)->options['message'] ?? '' }}"
                        class="flex min-h-[39px] w-full rounded-md border px-3 py-2 text-sm text-gray-600 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 dark:focus:border-gray-400"
                        :class="[errors['{{ $currentLocale->code }}[options][message]'] ? 'border border-red-600 hover:border-red-600' : '']"
                        rules="required"
                        label="@lang('admin::app.settings.themes.edit.message')"
                        placeholder="@lang('admin::app.settings.themes.edit.message')"
                    >
                    </v-field>

                    <x-admin::form.control-group.error control-name="{{ $currentLocale->code }}[options][message]" />
                </x-admin::form.control-group>

                <!-- Link -->
                <x-admin::form.control-group>
                    <x-admin::form.control-group.label>
                        @lang('admin::app.settings.themes.edit.link')
                    </x-admin::form.control-group.label>

                    <v-field
                        type="text"
                        name="{{ $currentLocale->code }}[options][link]"
                        value="{{ $theme->translate($currentLocale->code)->options['link'] ?? '' }}"
                        class="flex min-h-[39px] w-full rounded-md border px-3 py-2 text-sm text-gray-600 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 dark:focus:border-gray-400"
                        label="@lang('admin::app.settings.themes.edit.link')"
                        placeholder="@lang('admin::app.settings.themes.edit.link')"
                    >
                    </v-field>

                    <x-admin::form.control-group.error control-name="{{ $currentLocale->code }}[options][link]" />
                </x-admin::form.control-group>

                <!-- Link Title -->
                <x-admin::form.control-group>
                    <x-admin::form.control-group.label>
                        @lang('admin::app.settings.themes.edit.link-title')
                    </x-admin::form.control-group.label>

                    <v-field
                        type="text"
                        name="{{ $currentLocale->code }}[options][link_title]"
                        value="{{ $theme->translate($currentLocale->code)->options['link_title'] ?? '' }}"
                        class="flex min-h-[39px] w-full rounded-md border px-3 py-2 text-sm text-gray-600 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 dark:focus:border-gray-400"
                        label="@lang('admin::app.settings.themes.edit.link-title')"
                        placeholder="@lang('admin::app.settings.themes.edit.link-title')"
                    >
                    </v-field>

                    <x-admin::form.control-group.error control-name="{{ $currentLocale->code }}[options][link_title]" />
                </x-admin::form.control-group>
            </div>
        </div>
    </script>

    <script type="module">
        app.component('v-notification-bar', {
            template: '#v-notification-bar-template',

            props: ['errors'],
        });
    </script>
@endPushOnce
