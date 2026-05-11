<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Jobs\Odoo\FetchOdooProductsJob;
use App\Models\SyncLog;
use App\Models\SyncMapping;
use App\Services\ProductCacheService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProductsController extends Controller
{
    public function __construct(private readonly ProductCacheService $cache) {}

    public function index(Request $request)
    {
        $search  = $request->input('search');
        $channel = $request->input('channel', 'all');

        $entityTypes = match ($channel) {
            'shopify' => ['product'],
            'amazon'  => ['amazon_product'],
            default   => ['product', 'amazon_product'],
        };

        $query = SyncMapping::whereIn('entity_type', $entityTypes)
            ->orderByDesc('last_synced_at');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('odoo_id', 'like', "%{$search}%")
                  ->orWhere('shopify_id', 'like', "%{$search}%")
                  ->orWhere('odoo_reference', 'like', "%{$search}%")
                  ->orWhere('shopify_handle', 'like', "%{$search}%");
            });
        }

        $products = $query->paginate(50)->withQueryString();

        $variantCounts = SyncMapping::where('entity_type', 'product_variant')
            ->selectRaw('COUNT(*) as count, shopify_id')
            ->groupBy('shopify_id')
            ->pluck('count', 'shopify_id');

        $recentLogs = SyncLog::whereIn('entity_type', ['product', 'amazon_product', 'amazon_variant'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        // Check which odoo IDs have a cached JSON file
        $cachedIds = [];
        foreach ($products as $mapping) {
            $path = 'products/' . $mapping->odoo_id . '.json';
            if (Storage::disk('local')->exists($path)) {
                $cachedIds[] = (int) $mapping->odoo_id;
            }
        }

        return view('dashboard.products', compact(
            'products', 'search', 'channel', 'variantCounts', 'recentLogs', 'cachedIds'
        ));
    }

    /**
     * Show raw Odoo data for a product — reads from JSON cache, never calls Odoo.
     * Also previews what the Shopify payload would look like.
     */
    public function show(int $odooId)
    {
        $path = 'products/' . $odooId . '.json';

        if (!Storage::disk('local')->exists($path)) {
            return back()->with('error', "No cached data for Odoo product #{$odooId}. Run Fetch Products first.");
        }

        $data = json_decode(Storage::disk('local')->get($path), true);

        // Build Shopify payload preview from cached data — no Odoo API call
        $shopifyPayload = [];
        try {
            $shopifyService = app(\App\Services\Shopify\ShopifyProductService::class);
            $shopifyPayload = $shopifyService->buildPayload(
                $data['template']         ?? [],
                $data['variants']         ?? [],
                $data['attribute_values'] ?? [],
            );
        } catch (\Throwable $e) {
            $shopifyPayload = ['_error' => $e->getMessage()];
        }

        return view('dashboard.products-detail', compact('odooId', 'data', 'shopifyPayload'));
    }

    /**
     * Trigger a full fetch from Odoo → saves JSON files → sync_mappings populated via jobs.
     * Odoo is called ONCE here. All subsequent pushes read from JSON files.
     */
    public function fetch(Request $request)
    {
        // Dispatch full sync job — fetches from Odoo, caches each product as JSON,
        // then dispatches PushProductToShopifyJob / PushProductToAmazonJob which
        // read from JSON (no further Odoo calls).
        FetchOdooProductsJob::dispatch(
            fullSync: true,
            shopify: true,
            amazon: true,
        );

        return back()->with('success', 'Fetch Products job dispatched. Products will appear once the queue worker processes it.');
    }

    /**
     * Refresh cache for a single product from Odoo.
     */
    public function refresh(int $odooId)
    {
        try {
            $this->cache->fetchAndCacheSingle($odooId);
            return back()->with('success', "Product #{$odooId} cache refreshed from Odoo.");
        } catch (\Throwable $e) {
            return back()->with('error', "Failed to refresh #{$odooId}: " . $e->getMessage());
        }
    }
}