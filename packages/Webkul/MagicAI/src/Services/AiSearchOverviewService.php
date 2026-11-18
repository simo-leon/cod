<?php

namespace Webkul\MagicAI\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Webkul\MagicAI\AiProvider;
use Webkul\MagicAI\MagicAI;
use Webkul\Product\Models\ProductFlat;

class AiSearchOverviewService
{
    /**
     * Common Arabic and French/English stop words to ignore during keyword extraction.
     *
     * @var array<int, string>
     */
    protected array $stopWords = [
        'بغيت', 'شي', 'ديال', 'مع', 'في', 'على', 'ل', 'ال',
        'من', 'إلى', 'واحد', 'اثنين', 'ثلاثة', 'أربع', 'خمسة',
        'واش', 'واخا', 'عفاك', 'شنو', 'باش', 'أنا', 'لي', 'عندي',
        'اريد', 'ابحث', 'عن', 'افضل', 'احسن', 'كيفاش', 'شحال',
        'je', 'veux', 'cherche', 'pour', 'avec', 'dans', 'sur', 'un', 'une', 'des',
        'i', 'want', 'looking', 'for', 'the', 'a', 'an', 'and', 'with', 'best',
    ];

    /**
     * Create a new service instance.
     */
    public function __construct(
        protected MagicAI $magicAI
    ) {}

    /**
     * Generate an AI search overview for a given user query.
     *
     * @return array{summary: string, products: array<int, array<string, mixed>>}|null
     */
    public function generate(string $query): ?array
    {
        $channel = core()->getCurrentChannel()?->code ?? 'default';
        $locale = core()->getCurrentLocale()?->code ?? app()->getLocale();
        $currency = core()->getCurrentCurrencyCode() ?? '';
        $cacheKey = 'ai_search_overview_'.md5(mb_strtolower(trim($query))."_{$locale}_{$channel}_{$currency}");

        return Cache::remember($cacheKey, now()->addHours(12), function () use ($query, $channel, $locale) {
            return $this->performSearchAndSummarize($query, $channel, $locale);
        });
    }

    /**
     * Perform product search and invoke AI summarization.
     */
    protected function performSearchAndSummarize(string $query, string $channel, string $locale): ?array
    {
        $candidates = $this->findCandidateProducts($query, $channel, $locale);

        if ($candidates->isEmpty()) {
            return null;
        }

        $maxProducts = (int) (core()->getConfigData('magic_ai.storefront_features.ai_search_overview.max_products') ?: 5);

        $productData = $candidates->map(function (ProductFlat $flat) {
            return [
                'id' => (int) $flat->product_id,
                'name' => (string) $flat->name,
                'price' => core()->formatPrice($flat->price),
                'short_description' => strip_tags((string) ($flat->short_description ?? '')),
                'url' => route('shop.product_or_category.index', $flat->url_key),
            ];
        })->values()->toArray();

        $systemPrompt = core()->getConfigData('magic_ai.storefront_features.ai_search_overview.system_prompt')
            ?: 'أنت مساعد تسوق ذكي ومحترف في المتجر. أجب على استفسار الزبون باقتراح أفضل الخيارات من قائمة المنتجات المتوفرة والمرفقة حصراً. قدم ملخصاً مقنعاً ومختصراً يبرز مميزات المنتجات ولماذا تناسب طلبه. لا تخترع منتجات أو أسعار أو معلومات غير موجودة في البيانات المزودة.';

        $model = core()->getConfigData('magic_ai.storefront_features.ai_search_overview.model');

        if (! $model) {
            $default = AiProvider::defaultTextModel(AiProvider::defaultTextProvider());
            $model = $default?->value;
        }

        $productsJson = json_encode($productData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $prompt = implode("\n\n", [
            $systemPrompt,
            "User Search Query: \"{$query}\"",
            "Available Store Products (JSON):\n```json\n{$productsJson}\n```",
            "Instructions:\n".
            "- Answer the user query directly and convincingly.\n".
            "- Select at most {$maxProducts} matching product IDs from the provided list.\n".
            "- Return strictly valid JSON with this exact structure (NO Markdown fences, NO commentary outside the JSON):\n".
            "{\n  \"summary\": \"<smart overview text>\",\n  \"recommended_product_ids\": [<array of numeric IDs chosen from the list>]\n}",
        ]);

        try {
            $rawResponse = $this->magicAI->generateContent($prompt, $model);
        } catch (\Throwable $e) {
            Log::error('AI Search Overview generation failed: '.$e->getMessage(), [
                'query' => $query,
                'model' => $model,
            ]);

            return null;
        }

        $decoded = $this->cleanAndParseJson($rawResponse);

        if (! isset($decoded['summary'])) {
            return null;
        }

        $recommendedIds = array_map('intval', (array) ($decoded['recommended_product_ids'] ?? []));

        $matchedProducts = [];
        foreach ($candidates as $candidate) {
            if (in_array((int) $candidate->product_id, $recommendedIds, true)) {
                $matchedProducts[] = $this->formatProductForResponse($candidate);
            }
        }

        // If model didn't pick IDs or IDs didn't match, fallback to the top candidates
        if (empty($matchedProducts)) {
            foreach ($candidates->take($maxProducts) as $candidate) {
                $matchedProducts[] = $this->formatProductForResponse($candidate);
            }
        }

        return [
            'summary' => (string) $decoded['summary'],
            'products' => array_slice($matchedProducts, 0, $maxProducts),
        ];
    }

    /**
     * Find candidate products for the search query.
     */
    protected function findCandidateProducts(string $query, string $channel, string $locale)
    {
        $rawWords = preg_split('/[\s,،.?!;:+\-_()"\']+/u', mb_strtolower(trim($query))) ?: [];
        $keywords = array_values(array_filter($rawWords, function ($word) {
            return mb_strlen($word) >= 2 && ! in_array($word, $this->stopWords, true);
        }));

        $queryBuilder = ProductFlat::query()
            ->where('status', 1)
            ->where('visible_individually', 1)
            ->where('channel', $channel)
            ->where('locale', $locale)
            ->with(['product.images']);

        if (! empty($keywords)) {
            $queryBuilder->where(function ($q) use ($keywords) {
                foreach ($keywords as $word) {
                    $term = '%'.$word.'%';
                    $q->orWhere('name', 'LIKE', $term)
                        ->orWhere('short_description', 'LIKE', $term)
                        ->orWhere('description', 'LIKE', $term);
                }
            });
        }

        $candidates = $queryBuilder->limit(20)->get();

        // If no matching candidates found, fallback to the most recent active products
        if ($candidates->isEmpty()) {
            $candidates = ProductFlat::query()
                ->where('status', 1)
                ->where('visible_individually', 1)
                ->where('channel', $channel)
                ->where('locale', $locale)
                ->with(['product.images'])
                ->orderBy('created_at', 'desc')
                ->limit(15)
                ->get();
        }

        return $candidates;
    }

    /**
     * Format a single ProductFlat model for API response.
     */
    protected function formatProductForResponse(ProductFlat $flat): array
    {
        $baseImage = product_image()->getProductBaseImage($flat->product);

        return [
            'id' => (int) $flat->product_id,
            'name' => (string) $flat->name,
            'price' => core()->formatPrice($flat->price),
            'url' => route('shop.product_or_category.index', $flat->url_key),
            'image' => $baseImage['medium_image_url'] ?? $baseImage['small_image_url'] ?? bagisto_asset('images/medium-product-placeholder.webp', 'shop'),
            'short_description' => strip_tags((string) ($flat->short_description ?? '')),
        ];
    }

    /**
     * Clean and parse JSON from the raw AI response.
     */
    protected function cleanAndParseJson(string $raw): ?array
    {
        $cleaned = trim($raw);

        // Strip markdown code fences if present
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/u', $cleaned, $matches)) {
            $cleaned = trim($matches[1]);
        }

        // Try direct decode
        $decoded = json_decode($cleaned, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Try finding the first '{' and last '}'
        $firstBrace = mb_strpos($cleaned, '{');
        $lastBrace = mb_strrpos($cleaned, '}');

        if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
            $jsonSubstring = mb_substr($cleaned, $firstBrace, $lastBrace - $firstBrace + 1);
            $decoded = json_decode($jsonSubstring, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
