<?php

namespace App\Cod\Conversations;

use Webkul\Product\Models\ProductFlat;

/**
 * Match a free-text WhatsApp inquiry ("I want this product, how much
 * is it?") to a store product.
 *
 * The message that arrives from the store's WhatsApp button carries
 * the product name, its price and the product link, so matching uses
 * both signals:
 * - name: the message text (links and price removed) is tokenized and
 *   matched against product_flat names in every locale, ignoring
 *   case, Arabic diacritics / letter variants and Latin accents;
 * - price: a number next to DH / درهم / MAD (or next to "price" /
 *   "prix" / "ثمن") must be within a small margin of the product
 *   price (10 DH or 10%, whichever is bigger). A candidate whose
 *   price is far off is a different product and is excluded.
 *
 * Result: ['status' => 'single'|'multiple'|'none', 'products' =>
 * [['product_id', 'sku', 'name', 'price'], ...], 'price' =>
 * ?float]. 'single' means exactly one clear product — only then does
 * the AI start the collection flow. 'multiple' / 'none' mean the AI
 * asks for clarification (or escalates to a human).
 *
 * Never throws: a database failure returns 'none', and the caller
 * falls back to asking the customer.
 */
class ProductMatcher
{
    public const MATCH_SINGLE = 'single';

    public const MATCH_MULTIPLE = 'multiple';

    public const MATCH_NONE = 'none';

    /**
     * Words that carry no product meaning, removed from the name
     * query (Darija, Arabic, French, English).
     */
    protected const STOPWORDS = [
        'بغيت', 'نعرف', 'شحال', 'الثمن', 'ثمن', 'هذا', 'هاد', 'هادي', 'المنتج', 'ديال', 'ديالك', 'ديالي',
        'سلام', 'عليكم', 'مرحبا', 'واش', 'عندكم', 'كاين', 'بشحال', 'شري', 'نشري', 'طلب', 'نطلب',
        'prix', 'price', 'le', 'la', 'les', 'de', 'du', 'des', 'pour', 'je', 'veux', 'commander', 'svp', 'bonjour', 'salam',
        'the', 'this', 'that', 'want', 'order', 'how', 'much', 'is', 'it', 'please',
    ];

    /**
     * Locale preferred for the product name shown to the customer
     * (the customer writes in Darija / Arabic).
     */
    protected const PREFERRED_LOCALE = 'ar';

    public function match(string $text): array
    {
        try {
            return $this->doMatch($text);
        } catch (\Throwable) {
            return ['status' => self::MATCH_NONE, 'products' => [], 'price' => null];
        }
    }

    protected function doMatch(string $text): array
    {
        $price = $this->extractPrice($text);
        $tokens = $this->nameTokens($text);

        if ($tokens === []) {
            return ['status' => self::MATCH_NONE, 'products' => [], 'price' => $price];
        }

        $rows = ProductFlat::query()
            ->where('status', 1)
            ->where(function ($query) use ($tokens) {
                foreach ($tokens as $token) {
                    $query->orWhere('name', 'LIKE', '%'.$token.'%');

                    // Arabic letter variants (alef forms, teh marbuta /
                    // heh, alef maksura / yeh) are unified by
                    // normalize(), but the database stores the original
                    // spelling: a LIKE on the normalized token alone
                    // misses every name whose spelling differs (e.g. the
                    // token 'ساعه' never matches the stored 'ساعة', so
                    // a query whose tokens all contain ة/ى returns
                    // nothing). The wildcarded form matches every
                    // variant; the exact normalized scoring below still
                    // decides whether a candidate really matches.
                    $wild = str_replace(['ا', 'ه', 'ي'], '_', $token);

                    if ($wild !== $token) {
                        $query->orWhere('name', 'LIKE', '%'.$wild.'%');
                    }
                }
            })
            ->limit(100)
            ->get(['product_id', 'sku', 'name', 'price', 'locale']);

        $candidates = [];

        foreach ($rows as $row) {
            $productId = (int) $row->product_id;

            if (! isset($candidates[$productId])) {
                $candidates[$productId] = [
                    'product_id' => $productId,
                    'sku' => (string) $row->sku,
                    'name' => (string) $row->name,
                    'price' => (float) $row->price,
                    'locale' => (string) $row->locale,
                    'score' => 0.0,
                    'price_diff' => null,
                ];
            }

            // Prefer the Arabic name for display; keep the first row
            // otherwise.
            if ($row->locale === self::PREFERRED_LOCALE) {
                $candidates[$productId]['name'] = (string) $row->name;
                $candidates[$productId]['locale'] = (string) $row->locale;
            }
        }

        $matched = [];

        foreach ($candidates as $candidate) {
            $normalizedName = $this->normalize($candidate['name']);

            $hits = 0;

            foreach ($tokens as $token) {
                if (str_contains($normalizedName, $this->normalize($token))) {
                    $hits++;
                }
            }

            // At least half the query tokens must be in the name.
            if ($hits === 0 || $hits / count($tokens) < 0.5) {
                continue;
            }

            // With a price in the message, the product price must be
            // within the margin — otherwise it is another product.
            if ($price !== null) {
                $diff = abs($candidate['price'] - $price);
                $margin = max(10.0, $candidate['price'] * 0.10);

                if ($diff > $margin) {
                    continue;
                }

                $candidate['price_diff'] = $diff;
            }

            $candidate['score'] = $hits / count($tokens);
            $matched[] = $candidate;
        }

        usort($matched, function (array $a, array $b) {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            return ($a['price_diff'] ?? PHP_FLOAT_MAX) <=> ($b['price_diff'] ?? PHP_FLOAT_MAX);
        });

        $products = array_slice(array_map(fn (array $c) => [
            'product_id' => $c['product_id'],
            'sku' => $c['sku'],
            'name' => $c['name'],
            'price' => $c['price'],
        ], $matched), 0, 5);

        $status = match (count($products)) {
            0 => self::MATCH_NONE,
            1 => self::MATCH_SINGLE,
            default => self::MATCH_MULTIPLE,
        };

        return ['status' => $status, 'products' => $products, 'price' => $price];
    }

    /**
     * The price stated in the message, or null when no price can be
     * read. Only a number next to a currency marker (DH / درهم / MAD)
     * or next to a price word (ثمن / prix / price) counts — a bare
     * number could be a phone fragment or a quantity.
     */
    public function extractPrice(string $text): ?float
    {
        $patterns = [
            // 259 DH, 259 درهم, 259 MAD
            '/(\d+(?:[.,]\d{1,2})?)\s*(?:dh|dhs|درهم|دراهم|mad|د\.م)/iu',
            // الثمن 259, prix 259, price: 259
            '/(?:ثمن|الثمن|prix|price)\D{0,12}(\d+(?:[.,]\d{1,2})?)/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                return (float) str_replace(',', '.', $matches[1]);
            }
        }

        return null;
    }

    /**
     * Name tokens of the message: links and price fragments removed,
     * normalized, split, stopwords and pure numbers removed.
     *
     * @return string[]
     */
    public function nameTokens(string $text): array
    {
        $query = $this->extractNameQuery($text);

        $tokens = preg_split('/\s+/u', $this->normalize($query)) ?: [];

        $tokens = array_values(array_filter($tokens, function (string $token) {
            return mb_strlen($token) >= 2
                && ! is_numeric($token)
                && ! in_array($token, self::STOPWORDS, true);
        }));

        return array_values(array_unique($tokens));
    }

    /**
     * The message text with links and price fragments removed, as the
     * raw name query.
     */
    public function extractNameQuery(string $text): string
    {
        $query = preg_replace('/https?:\/\/\S+|www\.\S+/iu', ' ', $text) ?? $text;
        $query = preg_replace('/(\d+(?:[.,]\d{1,2})?)\s*(?:dh|dhs|درهم|دراهم|mad|د\.م)/iu', ' ', $query) ?? $query;
        $query = preg_replace('/(?:ثمن|الثمن|prix|price)\D{0,12}(\d+(?:[.,]\d{1,2})?)/iu', ' ', $query) ?? $query;

        return trim($query);
    }

    /**
     * Normalize for comparison: lowercase, Arabic diacritics and
     * tatweel removed, alef / teh marbuta / alef maksura unified,
     * Latin accents stripped, punctuation turned into spaces.
     */
    public function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[\x{064B}-\x{0652}\x{0670}\x{0640}]/u', '', $text) ?? $text;
        $text = str_replace(['أ', 'إ', 'آ', 'ٱ'], 'ا', $text);
        $text = str_replace(['ة'], 'ه', $text);
        $text = str_replace(['ى'], 'ي', $text);
        $text = str_replace(['ؤ'], 'و', $text);
        $text = str_replace(['ئ'], 'ي', $text);
        $text = strtr($text, [
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'à' => 'a', 'â' => 'a', 'ä' => 'a',
            'ç' => 'c', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ô' => 'o', 'ö' => 'o', 'î' => 'i', 'ï' => 'i',
        ]);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
