<?php

namespace Webkul\FPC\Listeners;

use Spatie\ResponseCache\Facades\ResponseCache;
use Webkul\Theme\Repositories\ThemeCustomizationRepository;

class ThemeCustomization
{
    /**
     * Create a new listener instance.
     *
     * @return void
     */
    public function __construct(protected ThemeCustomizationRepository $themeCustomizationRepository) {}

    /**
     * After theme customization create
     *
     * @param  \Webkul\Shop\Contracts\ThemeCustomization  $themeCustomization
     * @return void
     */
    public function afterCreate($themeCustomization)
    {
        $this->forgetThemeCustomization($themeCustomization);
    }

    /**
     * After theme customization update
     *
     * @param  \Webkul\Shop\Contracts\ThemeCustomization  $themeCustomization
     * @return void
     */
    public function afterUpdate($themeCustomization)
    {
        $this->forgetThemeCustomization($themeCustomization);
    }

    /**
     * Before theme customization delete
     *
     * @param  int  $themeCustomizationId
     * @return void
     */
    public function beforeDelete($themeCustomizationId)
    {
        $themeCustomization = $this->themeCustomizationRepository->find($themeCustomizationId);

        if (! $themeCustomization) {
            return;
        }

        $this->forgetThemeCustomization($themeCustomization);
    }

    /**
     * Forget the cached pages a theme customization can change.
     *
     * Footer links / services content render on every page, so those
     * clear the whole response cache (unchanged Bagisto behaviour).
     * Every other topic only renders on the homepage, so only the
     * homepage copies are forgotten — one per channel / locale /
     * currency combination.
     *
     * Why the loop: the cache key suffix is
     * `{channel}-{locale}-{currency}-{customer id or ''}`. The old
     * code forgot a single copy built from the *admin* request
     * (admin locale, usually `en`), while the homepage visitors
     * actually see is cached under the channel default locale
     * (`ar`) — so the forget never matched and the homepage kept
     * serving the stale copy until `cache:clear`. Guest copies
     * (empty customer suffix) are the ones visitors and Simo see;
     * a copy cached for a logged-in customer keeps its own suffix
     * and expires with the cache lifetime, as with every other
     * Bagisto FPC listener.
     *
     * @param  \Webkul\Shop\Contracts\ThemeCustomization  $themeCustomization
     */
    protected function forgetThemeCustomization($themeCustomization): void
    {
        if (in_array($themeCustomization->type, ['footer_links', 'services_content'])) {
            ResponseCache::clear();

            return;
        }

        $homepageUrl = config('app.url').'/';

        foreach (core()->getAllChannels() as $channel) {
            foreach ($channel->locales as $locale) {
                foreach ($channel->currencies as $currency) {
                    ResponseCache::selectCachedItems()
                        ->usingSuffix("{$channel->code}-{$locale->code}-{$currency->code}-")
                        ->forUrls($homepageUrl)
                        ->forget();
                }
            }
        }

        // Safety net: the copy built from the current request context
        // (e.g. the admin locale/currency), in case it is not one of
        // the channel combinations above.
        ResponseCache::selectCachedItems()
            ->forUrls($homepageUrl)
            ->forget();
    }
}
