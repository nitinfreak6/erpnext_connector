<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Jobs\Erp\FetchErpProductsJob;
use App\Jobs\Shopify\PushProductToShopifyJob;
use App\Jobs\Amazon\PushProductToAmazonJob;
use App\Models\ProductCache;
use App\Models\SyncLog;
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

    // ── Index — 100% DB, zero file reads ────────────────────────────────

    public function index(Request $request)
    {
        $search  = $request->input('search', '');
        $channel = $request->input('channel', 'all');
        $status  = $request->input('status', 'all');
        $perPage = (int) $request->input('per_page', 25);

        // ── Single DB query — no JSON files touched ──────────────────────
        $query = ProductCache::query()->orderByDesc('fetched_at');

        // Search across DB columns (fast, indexed)
        if ($search) {
            $query->search($search);
        }

        // Filter by channel sync status
        if ($channel === 'shopify' && $status !== 'all') {
            $query->shopifyStatus($status);
        } elseif ($channel === 'amazon' && $status !== 'all') {
            $query->amazonStatus($status);
        } elseif ($status !== 'all') {
            // Both channels: show if either matches
            $query->where(function ($q) use ($status) {
                $q->where('shopify_status', $status)
                  ->orWhere('amazon_status', $status);
            });
        }

        // Filter to channel
        if ($channel === 'shopify') {
            $query->whereNotNull('shopify_product_id')
                  ->orWhere('shopify_status', '!=', 'pending');
        } elseif ($channel === 'amazon') {
            $query->whereNotNull('amazon_asin')
                  ->orWhere('amazon_status', '!=', 'pending');
        }

        $products = $query->paginate($perPage)->withQueryString();

        // ── Stats for filter bar (cheap COUNT queries) ───────────────────
        $stats = [
            'total'           => ProductCache::count(),
            'shopify_sent'    => ProductCache::where('shopify_status', 'sent')->count(),
            'shopify_failed'  => ProductCache::where('shopify_status', 'failed')->count(),
            'shopify_pending' => ProductCache::where('shopify_status', 'pending')->count(),
            'amazon_sent'     => ProductCache::where('amazon_status', 'sent')->count(),
            'amazon_failed'   => ProductCache::where('amazon_status', 'failed')->count(),
            'amazon_pending'  => ProductCache::where('amazon_status', 'pending')->count(),
        ];

        $shopifyStore = $this->settings->shopifyShop() ?: config('shopify.shop', '—');

        return view('dashboard.products', compact(
            'products', 'search', 'channel', 'status',
            'perPage', 'stats', 'shopifyStore'
        ));

        // ────────────────────────────────────────────────────────────────
        // What changed vs old version:
        //
        // OLD: SyncMapping query + Storage::exists() loop + Storage::get() loop
        //      = N+1 file reads per page, slow with many products
        //
        // NEW: Single ProductCache::paginate() — one SQL query, zero file reads
        //      Scales to 10,000+ products with no slowdown
        // ────────────────────────────────────────────────────────────────
    }

    // ── Show product detail — reads raw_data from DB (one row fetch) ─────

    public function show(int $odooId)
    {
        $productCache = ProductCache::where('odoo_id', $odooId)->first();

        if (!$productCache || !$productCache->cacheExists()) {
            return back()->with('error', "No cached data for Odoo product #{$odooId}. Run Fetch Products first.");
        }

        // readCache() returns raw_data column — no file I/O if raw_data is populated
        $data = $productCache->readCache();

        // Build Shopify payload preview
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
        if ($syncLog?->response_payload) {
            $shopifyResponse = json_decode($syncLog->response_payload, true) ?? $syncLog->response_payload;
        }

        return view('dashboard.products-detail', compact(
            'odooId', 'data', 'productCache', 'shopifyPayload', 'shopifyResponse', 'syncLog'
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

        // Select only IDs — no raw_data loaded into memory
        $odooIds = ProductCache::pluck('odoo_id')->toArray();

        $queued = 0;
        foreach ($odooIds as $odooId) {
            if (in_array($channel, ['shopify', 'both'])) {
                PushProductToShopifyJob::dispatch((int) $odooId);
            }
            if (in_array($channel, ['amazon', 'both'])) {
                PushProductToAmazonJob::dispatch((int) $odooId);
            }
            $queued++;
        }

        return back()->with('success', "Queued {$queued} products for sync to " . strtoupper($channel) . ".");
    }

    // ── Fetch single product from ERP ─────────────────────────────────────

    public function fetchSingle(int $odooId)
    {
        try {
            $this->cache->fetchAndCacheSingle($odooId);
            return back()->with('success', "Product #{$odooId} fetched and cached from ERP.");
        } catch (\Throwable $e) {
            return back()->with('error', "Fetch failed for #{$odooId}: " . $e->getMessage());
        }
    }

    // ── Post single product to Shopify / Amazon ───────────────────────────

    public function postSingle(Request $request, int $odooId)
    {
        $productCache = ProductCache::where('odoo_id', $odooId)->first();

        if (!$productCache || !$productCache->cacheExists()) {
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

    public function refresh(int $odooId)
    {
        return $this->fetchSingle($odooId);
    }
}