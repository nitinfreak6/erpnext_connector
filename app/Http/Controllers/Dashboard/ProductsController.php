<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Jobs\Erp\FetchErpProductsJob;
use App\Jobs\Shopify\PushProductToShopifyJob;
use App\Jobs\Amazon\PushProductToAmazonJob;
use App\Models\SyncLog;
use App\Models\SyncMapping;
use App\Services\ProductCacheService;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProductsController extends Controller
{
    public function __construct(
        private readonly ProductCacheService $cache,
        private readonly SettingsService     $settings,
    ) {}

    // ── Index ────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $search  = $request->input('search');
        $channel = $request->input('channel', 'all');
        $status  = $request->input('status', 'all');
        $perPage = (int) $request->input('per_page', 25);

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

        $products = $query->paginate($perPage)->withQueryString();

        // Variant counts per shopify product
        $variantCounts = SyncMapping::where('entity_type', 'product_variant')
            ->selectRaw('COUNT(*) as count, shopify_id')
            ->groupBy('shopify_id')
            ->pluck('count', 'shopify_id');

        // Check which odoo IDs have cached JSON
        $cachedIds = [];
        foreach ($products as $mapping) {
            $path = 'products/' . $mapping->odoo_id . '.json';
            if (Storage::disk('local')->exists($path)) {
                $cachedIds[] = (int) $mapping->odoo_id;
            }
        }

        // Load cached template data for status column
        $cachedStatuses = [];
        foreach ($cachedIds as $odooId) {
            try {
                $json = Storage::disk('local')->get("products/{$odooId}.json");
                $data = json_decode($json, true);
                $cachedStatuses[$odooId] = $data['template']['active'] ?? null;
            } catch (\Throwable) {}
        }

        // Shopify store name
        $shopifyStore = $this->settings->shopifyShop() ?: config('shopify.shop', '—');

        return view('dashboard.products', compact(
            'products', 'search', 'channel', 'status', 'perPage',
            'variantCounts', 'cachedIds', 'cachedStatuses', 'shopifyStore'
        ));
    }

    // ── Show (product detail / cached JSON) ──────────────────────────────

    public function show(int $odooId)
    {
        $path = 'products/' . $odooId . '.json';

        if (!Storage::disk('local')->exists($path)) {
            return back()->with('error', "No cached data for Odoo product #{$odooId}. Run Fetch Products first.");
        }

        $data = json_decode(Storage::disk('local')->get($path), true);

        // Build Shopify payload preview from cached data
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

        // Latest sync log for this product
        $syncLog = SyncLog::where('entity_type', 'product')
            ->where('entity_id', (string) $odooId)
            ->whereIn('status', ['success', 'failed'])
            ->latest()
            ->first();

        $shopifyResponse = null;
        if ($syncLog && $syncLog->response_payload) {
            $shopifyResponse = json_decode($syncLog->response_payload, true) ?? $syncLog->response_payload;
        }

        return view('dashboard.products-detail', compact(
            'odooId', 'data', 'shopifyPayload', 'shopifyResponse', 'syncLog'
        ));
    }

    // ── Fetch ALL products from ERP ───────────────────────────────────────

    public function fetch()
    {
        FetchErpProductsJob::dispatchSync(fullSync: true, shopify: false, amazon: false);
		return back()->with('success', 'All products fetched from ERP successfully.');
    }

    // ── Post ALL products to Shopify + Amazon ─────────────────────────────

    public function postAll(Request $request)
    {
        $channel = $request->input('channel', 'both');

        // Get all product IDs from sync_mappings
        $odooIds = SyncMapping::where('entity_type', 'product')
            ->pluck('odoo_id')
            ->toArray();

        $queued = 0;
        foreach ($odooIds as $odooId) {
            $path = 'products/' . $odooId . '.json';
            if (!Storage::disk('local')->exists($path)) continue;

            if (in_array($channel, ['shopify', 'both'])) {
                PushProductToShopifyJob::dispatchSync((int) $odooId);
            }
            if (in_array($channel, ['amazon', 'both'])) {
                PushProductToAmazonJob::dispatchSync((int) $odooId);
            }
            $queued++;
        }

        return back()->with('success', "Synced {$queued} products successfully.");
    }

    // ── Fetch single product from ERP ────────────────────────────────────

    public function fetchSingle(int $odooId)
    {
        try {
            $this->cache->fetchAndCacheSingle($odooId);
            return back()->with('success', "Product #{$odooId} fetched and cached from ERP.");
        } catch (\Throwable $e) {
            return back()->with('error', "Fetch failed for #{$odooId}: " . $e->getMessage());
        }
    }

    // ── Post single product to Shopify/Amazon ─────────────────────────────

    public function postSingle(Request $request, int $odooId)
    {
        $path = 'products/' . $odooId . '.json';

        if (!Storage::disk('local')->exists($path)) {
            return back()->with('error', "No cached data for #{$odooId}. Fetch the product first.");
        }

        $channel = $request->input('channel', 'both');
        $queued  = 0;

        if (in_array($channel, ['shopify', 'both'])) {
            PushProductToShopifyJob::dispatchSync($odooId);
            $queued++;
        }

        if (in_array($channel, ['amazon', 'both'])) {
            PushProductToAmazonJob::dispatchSync($odooId);
            $queued++;
        }

        return back()->with('success', "Product #{$odooId} synced to " . strtoupper($channel) . " successfully.");
    }

    // ── Refresh (alias for fetchSingle) ──────────────────────────────────

    public function refresh(int $odooId)
    {
        return $this->fetchSingle($odooId);
    }
}