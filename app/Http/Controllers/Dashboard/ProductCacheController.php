<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Jobs\Amazon\PushProductToAmazonJob;
use App\Jobs\Shopify\PushProductToShopifyJob;
use App\Models\ProductCache;
use App\Services\ProductCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductCacheController extends Controller
{
    public function __construct(
        private readonly ProductCacheService $cache,
    ) {}

    /**
     * Product listing page.
     */
    public function index(Request $request): View
    {
        $shopifyFilter = $request->query('shopify');
        $amazonFilter  = $request->query('amazon');
        $search        = $request->query('search');

        $products = ProductCache::query()
            ->when($shopifyFilter, fn($q) => $q->where('shopify_status', $shopifyFilter))
            ->when($amazonFilter,  fn($q) => $q->where('amazon_status',  $amazonFilter))
            ->when($search, fn($q) => $q->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('default_code', 'like', "%{$search}%")
                  ->orWhere('odoo_id', $search);
            }))
            ->orderByDesc('fetched_at')
            ->paginate(25)
            ->withQueryString();

        $stats = [
            'total'           => ProductCache::count(),
            'shopify_sent'    => ProductCache::where('shopify_status', 'sent')->count(),
            'shopify_failed'  => ProductCache::where('shopify_status', 'failed')->count(),
            'shopify_pending' => ProductCache::where('shopify_status', 'pending')->count(),
            'amazon_sent'     => ProductCache::where('amazon_status', 'sent')->count(),
            'amazon_failed'   => ProductCache::where('amazon_status', 'failed')->count(),
            'amazon_pending'  => ProductCache::where('amazon_status', 'pending')->count(),
        ];

        return view('dashboard.products.cache', compact('products', 'stats', 'shopifyFilter', 'amazonFilter', 'search'));
    }

    /**
     * Show raw cached Odoo data for a product (like the Log Detail page in reference).
     */
    public function show(int $odooId): View
    {
        $cacheRecord = ProductCache::where('odoo_id', $odooId)->firstOrFail();
        $data        = $cacheRecord->readCache();

        return view('dashboard.products.cache-detail', compact('cacheRecord', 'data'));
    }

    /**
     * Fetch ALL products from Odoo and cache to JSON files.
     */
    public function fetchAll(): RedirectResponse
    {
        try {
            $count = $this->cache->fetchAndCacheAll();
            return back()->with('success', "Fetched and cached {$count} products from Odoo.");
        } catch (\Throwable $e) {
            return back()->with('error', 'Fetch failed: ' . $e->getMessage());
        }
    }

    /**
     * Re-fetch a single product from Odoo (refresh its cache).
     */
    public function refresh(int $odooId): RedirectResponse
    {
        try {
            $this->cache->fetchAndCacheSingle($odooId);
            return back()->with('success', "Product #{$odooId} cache refreshed.");
        } catch (\Throwable $e) {
            return back()->with('error', 'Refresh failed: ' . $e->getMessage());
        }
    }

      /**
     * Post selected (or all pending) products to Shopify.
     * Uses FetchOdooProductsJob → reads cache → PushProductToShopifyJob.
     */
    public function postShopify(Request $request): RedirectResponse
    {
        $ids = array_filter(array_map('intval', $request->input('ids', [])));
 
        if (!empty($ids)) {
            // Manual selection — dispatch specific IDs
            \App\Jobs\Odoo\FetchOdooProductsJob::dispatch(
                fullSync: false,
                shopify:  true,
                amazon:   false,
                odooIds:  $ids,
            )->onQueue('sync');
 
            return back()->with('success', count($ids) . ' product(s) queued for Shopify sync.');
        }
 
        // No selection — queue all non-sent products
        $pendingIds = \App\Models\ProductCache::where('shopify_status', '!=', 'sent')
            ->pluck('odoo_id')
            ->map(fn($id) => (int) $id)
            ->toArray();
 
        if (empty($pendingIds)) {
            return back()->with('success', 'All products already sent to Shopify.');
        }
 
        \App\Jobs\Odoo\FetchOdooProductsJob::dispatch(
            fullSync: false,
            shopify:  true,
            amazon:   false,
            odooIds:  $pendingIds,
        )->onQueue('sync');
 
        return back()->with('success', count($pendingIds) . ' product(s) queued for Shopify sync.');
    }

    /**
     * Post selected (or all pending) products to Amazon.
     */
    public function postAmazon(Request $request): RedirectResponse
    {
        $ids = array_filter(array_map('intval', $request->input('ids', [])));
 
        if (!empty($ids)) {
            \App\Jobs\Odoo\FetchOdooProductsJob::dispatch(
                fullSync: false,
                shopify:  false,
                amazon:   true,
                odooIds:  $ids,
            )->onQueue('sync');
 
            return back()->with('success', count($ids) . ' product(s) queued for Amazon sync.');
        }
 
        $pendingIds = \App\Models\ProductCache::where('amazon_status', '!=', 'sent')
            ->pluck('odoo_id')
            ->map(fn($id) => (int) $id)
            ->toArray();
 
        if (empty($pendingIds)) {
            return back()->with('success', 'All products already sent to Amazon.');
        }
 
        \App\Jobs\Odoo\FetchOdooProductsJob::dispatch(
            fullSync: false,
            shopify:  false,
            amazon:   true,
            odooIds:  $pendingIds,
        )->onQueue('sync');
 
        return back()->with('success', count($pendingIds) . ' product(s) queued for Amazon sync.');
    }

    /**
     * Clear cache for a single product.
     */
    public function clear(int $odooId): RedirectResponse
    {
        $this->cache->clearCache($odooId);
        return back()->with('success', "Cache cleared for product #{$odooId}.");
    }

    /**
     * Clear ALL cache.
     */
    public function clearAll(): RedirectResponse
    {
        $count = $this->cache->clearAll();
        return back()->with('success', "Cleared cache for {$count} products.");
    }
}