<?php

namespace App\Services\AbandonedCart;

use App\Cod\Support\PhoneNumber;
use App\Cod\WhatsApp\WhatsAppServiceInterface;
use App\Models\AbandonedCartReminder;
use App\Models\ProductPromotionSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Webkul\Checkout\Models\Cart;

/**
 * Abandoned-cart WhatsApp reminders (idea 7). Every setting comes
 * from the admin (general.store-features.abandoned-cart); nothing
 * is hard-coded. Sending itself goes through the existing COD
 * WhatsApp service (WhatsAppService::sendAbandonedCartReminder):
 * Meta Cloud API for production, Evolution API for testing, and
 * the log provider when nothing is configured — so no real message
 * is ever sent while the tokens are missing.
 *
 * Phone lookup order for a cart: the logged-in customer's phone,
 * then the billing address phone the guest typed at checkout, then
 * the shipping address phone. A cart with no retrievable phone is
 * skipped (logged), never sent.
 *
 * Message language: the channel's default locale (the cart does
 * not store the language the customer browsed in). The live store
 * default is French; the local channel default is Arabic.
 */
class AbandonedCartService implements AbandonedCartServiceInterface
{
    public const CONFIG_KEY = 'general.store-features.abandoned-cart';

    /**
     * Placeholders: {name} {products} {total} {cart_url} {store}.
     * {products} is one "• name ×qty — price" line per included
     * product (at most 5, then a "+ N more" line).
     */
    public const DEFAULT_MESSAGES = [
        'fr' => "Bonjour {name}, vous avez oublié quelque chose dans votre panier chez {store} :\n{products}\nTotal : {total}\nRetrouvez votre panier ici : {cart_url}",
        'ar' => "السلام {name}، نسيتي شي حاجة فالسلة ديالك فـ {store}:\n{products}\nالمجموع: {total}\nرجع للسلة من هنا: {cart_url}",
        'en' => "Hi {name}, you left something in your cart at {store}:\n{products}\nTotal: {total}\nBack to your cart: {cart_url}",
    ];

    public const MAX_PRODUCTS_LISTED = 5;

    public function __construct(
        protected WhatsAppServiceInterface $whatsapp,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) (core()->getConfigData(self::CONFIG_KEY.'.status') ?? true);
    }

    public function delayMinutes(): int
    {
        return max(1, (int) (core()->getConfigData(self::CONFIG_KEY.'.delay_minutes') ?? 60));
    }

    public function messageTemplate(string $locale): string
    {
        $locale = in_array($locale, ['fr', 'ar', 'en'], true) ? $locale : 'fr';

        $configured = core()->getConfigData(self::CONFIG_KEY.'.message_'.$locale);

        if (is_string($configured) && trim($configured) !== '') {
            return $configured;
        }

        return self::DEFAULT_MESSAGES[$locale];
    }

    public function includeInReminder(int $productId): bool
    {
        $row = ProductPromotionSetting::query()
            ->where('product_id', $productId)
            ->first();

        return $row === null || (bool) $row->include_in_abandoned_cart_reminder;
    }

    public function setIncludeInReminder(int $productId, bool $include): void
    {
        ProductPromotionSetting::updateOrCreate(
            ['product_id' => $productId],
            ['include_in_abandoned_cart_reminder' => $include],
        );
    }

    public function cartPhone(Cart $cart): ?string
    {
        $raw = null;

        if ($cart->customer_id) {
            $raw = DB::table('customers')
                ->where('id', $cart->customer_id)
                ->value('phone');
        }

        if (! $raw) {
            // The guest typed the phone at checkout: the billing
            // address on the cart, else the shipping address.
            foreach (['billing', 'shipping'] as $type) {
                $raw = DB::table('addresses')
                    ->where('cart_id', $cart->id)
                    ->where('address_type', $type)
                    ->whereNotNull('phone')
                    ->orderByDesc('id')
                    ->value('phone');

                if ($raw) {
                    break;
                }
            }
        }

        if (! $raw) {
            return null;
        }

        $normalized = PhoneNumber::normalize((string) $raw);

        // A Moroccan number normalizes to 12 digits (212 + 9). A
        // shorter result is not a usable phone number.
        return strlen($normalized) >= 9 ? $normalized : null;
    }

    public function localeForCart(Cart $cart): string
    {
        $localeId = DB::table('channels')
            ->where('id', $cart->channel_id)
            ->value('default_locale_id');

        $code = $localeId
            ? DB::table('locales')->where('id', $localeId)->value('code')
            : null;

        return in_array($code, ['fr', 'ar', 'en'], true) ? (string) $code : 'fr';
    }

    public function includedItems(Cart $cart): Collection
    {
        $items = $cart->items ?? collect();

        if ($items->isEmpty()) {
            return collect();
        }

        $productIds = $items
            ->map(fn ($item) => (int) $item->product_id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $excluded = ProductPromotionSetting::query()
            ->whereIn('product_id', $productIds)
            ->where('include_in_abandoned_cart_reminder', false)
            ->pluck('product_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        // Gift items (free, added by the promotions module) were not
        // chosen by the customer — they are never listed.
        return $items->filter(function ($item) use ($excluded) {
            if (! empty($item->additional['is_gift'])) {
                return false;
            }

            return ! in_array((int) $item->product_id, $excluded, true);
        })->values();
    }

    public function dueCarts(): Collection
    {
        return Cart::query()
            ->where('is_active', 1)
            ->where('items_count', '>', 0)
            ->where('updated_at', '<=', now()->subMinutes($this->delayMinutes()))
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('orders')
                    ->whereColumn('orders.cart_id', 'cart.id');
            })
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('abandoned_cart_reminders')
                    ->whereColumn('abandoned_cart_reminders.cart_id', 'cart.id');
            })
            ->orderBy('updated_at')
            ->get();
    }

    public function cartUrl(string $locale): string
    {
        $locale = in_array($locale, ['fr', 'ar', 'en'], true) ? $locale : 'fr';

        return rtrim((string) config('app.url'), '/').'/checkout/cart?locale='.$locale;
    }

    public function renderMessage(Cart $cart, string $locale): string
    {
        $items = $this->includedItems($cart);

        $more = [
            'fr' => 'et :count autre(s)',
            'ar' => 'و :count آخرين',
            'en' => 'and :count more',
        ][$locale] ?? 'and :count more';

        $lines = [];
        $total = (float) $items->sum(fn ($item) => (float) $item->total);

        foreach ($items->take(self::MAX_PRODUCTS_LISTED) as $item) {
            $lines[] = '• '.$item->name.' ×'.(int) $item->quantity.' — '.$this->formatPrice((float) $item->total);
        }

        if ($items->count() > self::MAX_PRODUCTS_LISTED) {
            $lines[] = '• '.str_replace(':count', (string) ($items->count() - self::MAX_PRODUCTS_LISTED), $more);
        }

        return str_replace(
            ['{name}', '{products}', '{total}', '{cart_url}', '{store}'],
            [
                trim((string) ($cart->customer_first_name ?? '')),
                implode("\n", $lines),
                $this->formatPrice($total),
                $this->cartUrl($locale),
                (string) config('app.name'),
            ],
            $this->messageTemplate($locale),
        );
    }

    public function processCart(Cart $cart): array
    {
        // Re-checked here (not only in dueCarts): two runs at the
        // same minute must not both send.
        if (AbandonedCartReminder::query()->where('cart_id', $cart->id)->exists()) {
            return ['status' => 'skipped', 'message' => 'Reminder was already sent for this cart.'];
        }

        if (DB::table('orders')->where('cart_id', $cart->id)->exists()) {
            return ['status' => 'skipped', 'message' => 'Cart became an order.'];
        }

        $items = $this->includedItems($cart);

        if ($items->isEmpty()) {
            return ['status' => 'skipped', 'message' => 'Cart has no products included in reminders.'];
        }

        $phone = $this->cartPhone($cart);

        if ($phone === null) {
            Log::info('Abandoned-cart reminder skipped: no phone number on the cart', ['cart_id' => $cart->id]);

            return ['status' => 'skipped', 'message' => 'No phone number on the cart.'];
        }

        $locale = $this->localeForCart($cart);
        $text = $this->renderMessage($cart, $locale);

        // Template parameters for Meta, in this order: name,
        // products, total. The template must be approved by Meta
        // with exactly these three {{1}}..{{3}} parameters.
        $result = $this->whatsapp->sendAbandonedCartReminder($phone, $text, [
            trim((string) ($cart->customer_first_name ?? '')),
            $this->productNames($items),
            $this->formatPrice($items->sum(fn ($item) => (float) $item->total)),
        ]);

        if (! ($result['success'] ?? false)) {
            Log::warning('Abandoned-cart reminder failed', [
                'cart_id' => $cart->id,
                'phone' => $phone,
                'message' => $result['message'] ?? 'Unknown error.',
            ]);

            return ['status' => 'failed', 'message' => $result['message'] ?? 'Unknown error.'];
        }

        // firstOrCreate: the unique cart_id makes a concurrent
        // second send impossible — only the first writer gets a row.
        AbandonedCartReminder::firstOrCreate(
            ['cart_id' => $cart->id],
            [
                'phone' => $phone,
                'locale' => $locale,
                'message_id' => $result['message_id'] ?? null,
                'sent_at' => now(),
            ],
        );

        return ['status' => 'sent', 'message' => 'Reminder sent.'];
    }

    /**
     * The product names as one line, for the Meta template
     * parameter (templates cannot take a multi-line list).
     */
    protected function productNames(Collection $items): string
    {
        return $items->take(self::MAX_PRODUCTS_LISTED)
            ->map(fn ($item) => $item->name.' ×'.(int) $item->quantity)
            ->implode(', ');
    }

    protected function formatPrice(float $amount): string
    {
        try {
            return core()->formatPrice($amount);
        } catch (\Throwable) {
            return number_format($amount, 2).' DH';
        }
    }
}
