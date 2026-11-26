<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Webkul\Category\Repositories\CategoryRepository;
use Webkul\Marketing\Jobs\UpdateCreateSearchTerm as UpdateCreateSearchTermJob;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Shop\Helpers\CatalogApiCache;
use Webkul\Shop\Http\Resources\ProductCardResource;

class ProductController extends APIController
{
    /**
     * Create a controller instance.
     *
     * @return void
     */
    public function __construct(
        protected CategoryRepository $categoryRepository,
        protected ProductRepository $productRepository,
        protected CatalogApiCache $catalogApiCache
    ) {}

    /**
     * Product listings.
     */
    public function index(): JsonResource|JsonResponse
    {
        $searchEngine = 'database';

        if (core()->getConfigData('catalog.products.search.engine') == 'elastic') {
            $searchEngine = core()->getConfigData('catalog.products.search.storefront_mode');
        }

        $searchData = $this->resolveSearchQueryData($searchEngine);

        $query = $searchData['effective_query'] ?? $searchData['original_query'];

        /**
         * Search results are never cached: the search-term space is unbounded
         * and the search-term job must run on every request.
         */
        if (! empty($query)) {
            $products = $this->getProducts($searchEngine, $query);

            /**
             * Update or create search term only if
             * there is only one filter that is query param
             */
            if (count(request()->except(['mode', 'sort', 'limit'])) == 1) {
                UpdateCreateSearchTermJob::dispatch([
                    'term' => $query,
                    'results' => $products->total(),
                    'channel_id' => core()->getCurrentChannel()->id,
                    'locale' => app()->getLocale(),
                ]);
            }

            return ProductCardResource::collection($products);
        }

        /** Listing/carousel responses (no search term) are cached per catalog version */
        $data = $this->catalogApiCache->remember('products', request()->query(), function () use ($searchEngine) {
            return ProductCardResource::collection($this->getProducts($searchEngine, ''))
                ->response()
                ->getData(true);
        });

        return response()->json($data)->withHeaders($this->catalogCacheHeaders());
    }

    /**
     * Fetch the storefront product listing for the given search engine and query.
     */
    protected function getProducts(string $searchEngine, string $query)
    {
        return $this->productRepository
            ->setSearchEngine($searchEngine)
            ->getAll(array_merge(request()->query(), [
                'query' => $query,
                'channel_id' => core()->getCurrentChannel()->id,
                'status' => 1,
                'visible_individually' => 1,
            ]));
    }

    /**
     * Resolve search query data.
     */
    protected function resolveSearchQueryData($searchEngine): array
    {
        if (request()->query('suggest', '') === '0') {
            return [
                'original_query' => request()->query('query', ''),
                'effective_query' => null,
            ];
        }

        $originalQuery = request()->query('query', '');

        return [
            'original_query' => $originalQuery,
            'effective_query' => $this->getEffectiveQuery($originalQuery, $searchEngine),
        ];
    }

    /**
     * It will return the effective query based on the search engine.
     */
    protected function getEffectiveQuery(string $originalQuery, string $searchEngine): ?string
    {
        $effectiveQuery = $this->productRepository->setSearchEngine($searchEngine)->getSuggestions($originalQuery);

        return $effectiveQuery;
    }

    /**
     * Best sellers: products ranked by ordered quantity across all
     * non-canceled orders (Jemla: Amazon "Best Sellers" /
     * AliExpress "Top Rankings" for the homepage best-sellers
     * topic). Only products that are currently visible in this
     * channel/locale are returned, in best-seller order. An empty
     * store (no orders yet) returns an empty list — the section
     * simply does not render — rather than invented best sellers.
     */
    public function bestSellers(): JsonResource
    {
        $limit = max(1, min(50, (int) request()->query('limit', 12)));

        $bestSellerIds = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.status', '<>', 'canceled')
            ->whereNotNull('order_items.product_id')
            ->groupBy('order_items.product_id')
            ->orderByRaw('SUM(order_items.qty_ordered) DESC')
            ->limit($limit)
            ->pluck('order_items.product_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (empty($bestSellerIds)) {
            return ProductCardResource::collection(collect());
        }

        $channel = core()->getCurrentChannelCode();
        $locale = core()->getCurrentLocale()->code;

        $products = $this->productRepository->getModel()->newQuery()
            ->whereIn('id', $bestSellerIds)
            ->whereExists(function ($query) use ($channel, $locale) {
                $query->select(DB::raw(1))
                    ->from('product_flat')
                    ->whereColumn('product_flat.product_id', 'products.id')
                    ->where('product_flat.channel', $channel)
                    ->where('product_flat.locale', $locale)
                    ->where('product_flat.status', 1);
            })
            ->get()
            ->sortBy(fn ($product) => array_search($product->id, $bestSellerIds))
            ->values();

        return ProductCardResource::collection($products);
    }

    /**
     * Related product listings.
     *
     * @param  int  $id
     */
    public function relatedProducts($id): JsonResource
    {
        $perPage = max(1, (int) request()->query('per_page', 20));
        $page = max(1, (int) request()->query('page', 1));

        $product = $this->productRepository->findOrFail($id);
        $categoryIds = $product->categories->pluck('id')->toArray();

        if (empty($categoryIds)) {
            return ProductCardResource::collection(collect());
        }

        $query = $this->productRepository->getModel()->newQuery()
            ->whereHas('categories', function ($q) use ($categoryIds) {
                $q->whereIn('category_id', $categoryIds);
            })
            ->where('id', '<>', $product->id)
            ->inRandomOrder()
            ->distinct();

        $relatedProducts = $query->paginate($perPage, ['*'], 'page', $page);

        return ProductCardResource::collection($relatedProducts);
    }

    /**
     * Up-sell product listings.
     *
     * @param  int  $id
     */
    public function upSellProducts($id): JsonResource
    {
        $product = $this->productRepository->findOrFail($id);

        $upSellProducts = $product->up_sells()
            ->take(core()->getConfigData('catalog.products.product_view_page.no_of_up_sells_products'))
            ->get();

        return ProductCardResource::collection($upSellProducts);
    }
}
