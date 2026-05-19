<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Jobs\Ecom\FetchEcomProductsJob;
use App\Jobs\Erp\FetchErpProductsJob;
use App\Jobs\Shopify\PushProductToShopifyJob;
use App\Models\ProductCache;
use App\Models\SyncMapping;
use App\Models\SyncLog;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductsController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    /**
     * Products index - adapts to current sync direction
     */
    public function index(Request $request)
    {
        $syncMode = $this->settings->productSyncMode();
        $search = $request->input('search', '');
        $status = $request->input('status', 'all');
        $perPage = (int) $request->input('per_page', 25);
        $direction = $request->input('direction', 'erp_to_ecom'); // For bidirectional mode

        // Get products based on sync mode
        $products = match($syncMode) {
            'erp_to_ecom' => $this->getErpToEcomProducts($search, $status, $perPage),
            'ecom_to_erp' => $this->getEcomToErpProducts($search, $status, $perPage),
            'bidirectional' => $this->getBidirectionalProducts($search, $status, $perPage, $direction),
            default => collect([]),
        };

        // Get stats based on sync mode
        $stats = match($syncMode) {
            'erp_to_ecom' => $this->getErpToEcomStats(),
            'ecom_to_erp' => $this->getEcomToErpStats(),
            'bidirectional' => $this->getBidirectionalStats(),
            default => [],
        };

        $ecomDriver = $this->settings->ecomDriver();
        $shopifyStore = $this->settings->shopifyShop() ?: config('shopify.shop', '—');

        return view('dashboard.products', compact(
            'products', 'search', 'status', 'perPage', 'stats', 
            'syncMode', 'ecomDriver', 'shopifyStore', 'direction'
        ));
    }

    /**
     * Get products for ERP → Ecom direction (from product_cache)
     */
    private function getErpToEcomProducts(?string $search, string $status, int $perPage)
    {
        $query = ProductCache::query()->orderByDesc('fetched_at');

        if ($search) {
            $query->search($search);
        }

        if ($status === 'sent') {
            $query->where('shopify_status', 'sent');
        } elseif ($status === 'failed') {
            $query->where('shopify_status', 'failed');
        } elseif ($status === 'pending') {
            $query->where('shopify_status', 'pending');
        }

        return $query->paginate($perPage)->withQueryString();
    }

    /**
     * Get products for Ecom → ERP direction (from sync_mappings)
     */
    private function getEcomToErpProducts(?string $search, string $status, int $perPage)
    {
        $query = SyncMapping::where('sync_mappings.entity_type', 'product')
            ->whereIn('sync_mappings.last_sync_direction', ['ecom_to_erp', 'shopify_to_erp', 'shopify_to_odoo'])
            ->orderByDesc('sync_mappings.last_synced_at');

        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('sync_mappings.ecom_id', 'like', "%{$search}%")
                  ->orWhere('sync_mappings.erp_id', 'like', "%{$search}%")
                  ->orWhere('sync_mappings.ecom_handle', 'like', "%{$search}%");
            });
        }
        
        // Join with product_cache to get product name
        $query->leftJoin('product_cache', function($join) {
            $join->on('product_cache.odoo_id', '=', DB::raw('CAST(sync_mappings.erp_id AS UNSIGNED)'));
        });
        
        // Select columns
        $query->select([
            'sync_mappings.*',
            'product_cache.name as product_name',
            'product_cache.default_code as sku',
        ]);

        // Status filter
        if ($status !== 'all') {
            $query->whereExists(function($q) use ($status) {
                $q->select(DB::raw(1))
                  ->from('sync_logs')
                  ->whereColumn('sync_logs.entity_id', 'sync_mappings.ecom_id')
                  ->where('sync_logs.entity_type', 'product')
                  ->whereIn('sync_logs.direction', ['ecom_to_erp', 'shopify_to_erp', 'shopify_to_odoo'])
                  ->where('sync_logs.status', $status)
                  ->orderByDesc('sync_logs.created_at')
                  ->limit(1);
            });
        }

        $results = $query->paginate($perPage)->withQueryString();
        
        // Load latest log status for each product
        $results->getCollection()->transform(function($product) {
            $latestLog = SyncLog::where('entity_id', $product->ecom_id)
                ->where('entity_type', 'product')
                ->whereIn('direction', ['ecom_to_erp', 'shopify_to_erp', 'shopify_to_odoo'])
                ->latest()
                ->first();
            
            $product->latest_log_status = $latestLog?->status ?? 'pending';
            return $product;
        });

        return $results;
    }

    /**
     * Get products for bidirectional mode
     */
    private function getBidirectionalProducts(?string $search, string $status, int $perPage, string $direction)
    {
        if ($direction === 'ecom_to_erp') {
            return $this->getEcomToErpProducts($search, $status, $perPage);
        }
        
        return $this->getErpToEcomProducts($search, $status, $perPage);
    }

    /**
     * Stats for ERP → Ecom
     */
    private function getErpToEcomStats(): array
    {
        return [
            'total' => ProductCache::count(),
            'sent' => ProductCache::where('shopify_status', 'sent')->count(),
            'failed' => ProductCache::where('shopify_status', 'failed')->count(),
            'pending' => ProductCache::where('shopify_status', 'pending')->count(),
        ];
    }

    /**
     * Stats for Ecom → ERP
     */
    private function getEcomToErpStats(): array
    {
        $total = SyncMapping::where('entity_type', 'product')
            ->whereIn('last_sync_direction', ['ecom_to_erp', 'shopify_to_erp', 'shopify_to_odoo'])
            ->count();

        $success = DB::table('sync_mappings')
            ->join('sync_logs', function($join) {
                $join->on('sync_logs.entity_id', '=', 'sync_mappings.ecom_id')
                     ->where('sync_logs.entity_type', 'product')
                     ->whereIn('sync_logs.direction', ['ecom_to_erp', 'shopify_to_erp', 'shopify_to_odoo'])
                     ->whereRaw('sync_logs.id = (
                         SELECT id FROM sync_logs sl2 
                         WHERE sl2.entity_id = sync_mappings.ecom_id 
                         AND sl2.entity_type = "product"
                         AND sl2.direction IN ("ecom_to_erp", "shopify_to_erp", "shopify_to_odoo")
                         ORDER BY created_at DESC LIMIT 1
                     )');
            })
            ->where('sync_logs.status', 'success')
            ->count();

        $failed = DB::table('sync_mappings')
            ->join('sync_logs', function($join) {
                $join->on('sync_logs.entity_id', '=', 'sync_mappings.ecom_id')
                     ->where('sync_logs.entity_type', 'product')
                     ->whereIn('sync_logs.direction', ['ecom_to_erp', 'shopify_to_erp', 'shopify_to_odoo'])
                     ->whereRaw('sync_logs.id = (
                         SELECT id FROM sync_logs sl2 
                         WHERE sl2.entity_id = sync_mappings.ecom_id 
                         AND sl2.entity_type = "product"
                         AND sl2.direction IN ("ecom_to_erp", "shopify_to_erp", "shopify_to_odoo")
                         ORDER BY created_at DESC LIMIT 1
                     )');
            })
            ->where('sync_logs.status', 'failed')
            ->count();

        return [
            'total' => $total,
            'success' => $success,
            'failed' => $failed,
            'pending' => max(0, $total - $success - $failed),
        ];
    }

    /**
     * Stats for bidirectional
     */
    private function getBidirectionalStats(): array
    {
        $erpToEcom = $this->getErpToEcomStats();
        $ecomToErp = $this->getEcomToErpStats();

        return [
            'erp_to_ecom' => $erpToEcom,
            'ecom_to_erp' => $ecomToErp,
            'total' => $erpToEcom['total'] + $ecomToErp['total'],
        ];
    }

    /**
     * Fetch products FROM ERP (Odoo → Shopify direction)
     */
    public function fetch()
    {
        $syncMode = $this->settings->productSyncMode();

        if ($syncMode === 'ecom_to_erp') {
            return back()->with('error', 'Cannot fetch from ERP when sync mode is Ecom → ERP. Change sync direction first.');
        }

        FetchErpProductsJob::dispatchSync(fullSync: true, shopify: false, amazon: false);
        return back()->with('success', 'Products fetched from ERP successfully.');
    }

    /**
     * Pull products FROM Ecom platform (Shopify → Odoo direction)
     */
    public function pull()
    {
        $syncMode = $this->settings->productSyncMode();

        if ($syncMode === 'erp_to_ecom') {
            return back()->with('error', 'Cannot pull from Ecom when sync mode is ERP → Ecom. Change sync direction first.');
        }

        FetchEcomProductsJob::dispatch();
        return back()->with('success', 'Pull from ' . ucfirst($this->settings->ecomDriver()) . ' queued successfully.');
    }

    /**
     * Push products TO Ecom platform
     */
    public function postAll(Request $request)
    {
        $syncMode = $this->settings->productSyncMode();

        if ($syncMode === 'ecom_to_erp') {
            return back()->with('error', 'Cannot push to Ecom when sync mode is Ecom → ERP. Change sync direction first.');
        }

        $odooIds = ProductCache::pluck('odoo_id')->toArray();
        $queued = 0;

        foreach ($odooIds as $odooId) {
            PushProductToShopifyJob::dispatch((int) $odooId);
            $queued++;
        }

        return back()->with('success', "Queued {$queued} products for push to " . ucfirst($this->settings->ecomDriver()));
    }

    /**
     * Product detail view
     */
    public function show(int $odooId)
    {
        $syncMode = $this->settings->productSyncMode();

        if ($syncMode === 'erp_to_ecom' || $syncMode === 'bidirectional') {
            return $this->showErpToEcom($odooId);
        }

        return $this->showEcomToErp($odooId);
    }

    private function showErpToEcom(int $odooId)
    {
        $productCache = ProductCache::where('odoo_id', $odooId)->first();

        if (!$productCache || !$productCache->cacheExists()) {
            return back()->with('error', "No cached data for product #{$odooId}.");
        }

        $data = $productCache->readCache();

        $syncLog = SyncLog::where('entity_type', 'product')
            ->where('entity_id', (string) $odooId)
            ->whereIn('direction', ['erp_to_ecom', 'odoo_to_shopify', 'erp_to_shopify'])
            ->latest()
            ->first();

        // Build Shopify payload preview (for compatibility with old view)
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

        // Get Shopify response from sync log
        $shopifyResponse = null;
        if ($syncLog?->response_payload) {
            $shopifyResponse = json_decode($syncLog->response_payload, true) ?? $syncLog->response_payload;
        }

        return view('dashboard.products-detail', compact(
            'odooId', 'data', 'productCache', 'syncLog', 'shopifyPayload', 'shopifyResponse'
        ));
    }

    private function showEcomToErp(int $odooId)
    {
        $mapping = SyncMapping::where('entity_type', 'product')
            ->where('erp_id', (string) $odooId)
            ->where('last_sync_direction', 'ecom_to_erp')
            ->first();

        if (!$mapping) {
            return back()->with('error', "No mapping found for product #{$odooId}.");
        }

        $syncLog = SyncLog::where('entity_type', 'product')
            ->where('entity_id', $mapping->ecom_id)
            ->where('direction', 'ecom_to_erp')
            ->latest()
            ->first();

        return view('dashboard.products-detail-ecom', compact('mapping', 'syncLog'));
    }

    /**
     * Fetch single product
     */
    public function fetchSingle(int $odooId)
    {
        $syncMode = $this->settings->productSyncMode();

        if ($syncMode === 'ecom_to_erp') {
            return back()->with('error', 'Cannot fetch from ERP in Ecom → ERP mode.');
        }

        try {
            $cacheService = app(\App\Services\ProductCacheService::class);
            $cacheService->fetchAndCacheSingle($odooId);
            return back()->with('success', "Product #{$odooId} fetched from ERP.");
        } catch (\Throwable $e) {
            return back()->with('error', "Fetch failed: " . $e->getMessage());
        }
    }

    /**
     * Push single product
     */
    public function postSingle(int $odooId)
    {
        $syncMode = $this->settings->productSyncMode();

        if ($syncMode === 'ecom_to_erp') {
            return back()->with('error', 'Cannot push to Ecom in Ecom → ERP mode.');
        }

        PushProductToShopifyJob::dispatchSync($odooId);
        return back()->with('success', "Product #{$odooId} pushed successfully.");
    }

    public function refresh(int $odooId)
    {
        return $this->fetchSingle($odooId);
    }
}