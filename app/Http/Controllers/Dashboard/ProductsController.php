<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Jobs\Ecom\FetchEcomProductsJob;
use App\Jobs\Erp\FetchErpProductsJob;
use App\Models\ProductCache;
use App\Models\SyncLog;
use App\Models\SyncMapping;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductsController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    public function index(Request $request)
    {
        $syncMode  = $this->settings->productSyncMode();
        $search    = $request->input('search', '');
        $status    = $request->input('status', 'all');
        $perPage   = (int) $request->input('per_page', 25);
        $direction = $request->input('direction', 'erp_to_ecom');

        $products = match($syncMode) {
            'erp_to_ecom'   => $this->getErpToEcomProducts($search, $status, $perPage),
            'ecom_to_erp'   => $this->getEcomToErpProducts($search, $status, $perPage),
            'bidirectional' => $this->getBidirectionalProducts($search, $status, $perPage, $direction),
            default         => collect([]),
        };

        $stats = match($syncMode) {
            'erp_to_ecom'   => $this->getErpToEcomStats(),
            'ecom_to_erp'   => $this->getEcomToErpStats(),
            'bidirectional' => $this->getBidirectionalStats(),
            default         => [],
        };

        $ecomDriver   = $this->settings->ecomDriver();
        $shopifyStore = $this->settings->shopifyShop() ?: config('shopify.shop', '—');

        return view('dashboard.products', compact(
            'products', 'search', 'status', 'perPage', 'stats',
            'syncMode', 'ecomDriver', 'shopifyStore', 'direction'
        ));
    }

    // ── Fetch: ERP → cache (incremental, new/updated only) ───────────────────

    // ── Fetch: ERP → cache (incremental, new/updated only) ───────────────────

	public function fetch()
	{
		$syncMode = $this->settings->productSyncMode();

		if ($syncMode === 'ecom_to_erp') {
			return back()->with('error', 'Sync mode is Ecom → ERP. Use Pull instead.');
		}

		// autoPush: false — manual Fetch button only fetches/caches. Use Push button to push.
		FetchErpProductsJob::dispatchSync(fullSync: false, erpIds: null, autoPush: false);

		$notes = \App\Models\SyncQueueState::forType('products')->fresh()->notes ?? '';

		if ($notes === 'nothing_changed') {
			return back()->with('info', 'No new or updated products in ' . $this->settings->erpDisplayName() . ' since last sync.');
		}

		if (str_starts_with($notes, 'synced:')) {
			$count = str_replace('synced:', '', $notes);
			return back()->with('success', "{$count} product(s) fetched from " . $this->settings->erpDisplayName() . '. Use "Push to ' . $this->settings->ecomDisplayName() . '" to sync.');
		}

		return back()->with('success', 'Fetch completed from ' . $this->settings->erpDisplayName() . '. Use "Push" to send products to ' . $this->settings->ecomDisplayName() . '.');
	}

	// ── Pull: ecom → ERP (incremental, new/updated only) ─────────────────────

	public function pull()
	{
		$syncMode = $this->settings->productSyncMode();

		if ($syncMode === 'erp_to_ecom') {
			return back()->with('error', 'Sync mode is ERP → Ecom. Use Fetch instead.');
		}

		FetchEcomProductsJob::dispatchSync(fullSync: false);

		$notes = \App\Models\SyncQueueState::forType('products')->fresh()->notes ?? '';

		if ($notes === 'nothing_changed') {
			return back()->with('info', 'No new or updated products in ' . $this->settings->ecomDisplayName() . ' since last sync.');
		}

		return back()->with('success', 'Products pulled from ' . $this->settings->ecomDisplayName() . '. ' . $notes);
	}

    // ── Post all: push all cached products to ecom ────────────────────────────

    public function postAll(Request $request)
	{
		$syncMode = $this->settings->productSyncMode();

		if ($syncMode === 'ecom_to_erp') {
			return back()->with('error', 'Sync mode is Ecom → ERP. Products flow the other direction.');
		}

		$ecomDriver = $this->settings->ecomDriver();
		$ecomJobMap = ['shopify' => \App\Jobs\Ecom\PushProductToEcomJob::class];

		if (!isset($ecomJobMap[$ecomDriver])) {
			return back()->with('error', "No push job registered for ecom driver [{$ecomDriver}].");
		}

		$ecomJobClass  = $ecomJobMap[$ecomDriver];
		$amazonEnabled = $this->settings->isAmazonChannelEnabled();

		// Only push pending/failed — skip already sent
		$erpCol = ProductCache::erpIdColumn();
		$erpIds = ProductCache::where(function ($q) {
				$col = ProductCache::ecomStatusColumn();
				$q->where($col, ProductCache::STATUS_PENDING)
				  ->orWhere($col, ProductCache::STATUS_FAILED)
				  ->orWhereNull($col);
			})
			->pluck($erpCol)
			->map(fn($id) => (int) $id)
			->filter()
			->toArray();

		if (empty($erpIds)) {
			return back()->with('info', 'All products already pushed to ' . $this->settings->ecomDisplayName() . '.');
		}

		foreach ($erpIds as $erpId) {
			$ecomJobClass::dispatch($erpId);
			if ($amazonEnabled) {
				\App\Jobs\Amazon\PushProductToAmazonJob::dispatch($erpId);
			}
		}

		return back()->with('success', count($erpIds) . ' product(s) pushed to ' . $this->settings->ecomDisplayName() . '.');
	}

    // ── Show product detail ───────────────────────────────────────────────────

    public function show(int $erpId)
    {
        $syncMode = $this->settings->productSyncMode();

        if ($syncMode === 'erp_to_ecom' || $syncMode === 'bidirectional') {
            return $this->showErpToEcom($erpId);
        }

        return $this->showEcomToErp($erpId);
    }

    private function showErpToEcom(int $erpId)
    {
        // FIX: look up by erp_id OR odoo_id for backwards compat
        $productCache = ProductCache::where('erp_id', $erpId)
            ->orWhere('odoo_id', $erpId)
            ->first();

        if (!$productCache || !$productCache->cacheExists()) {
            return back()->with('error', "No cached data for product #{$erpId}.");
        }

        $data = $productCache->readCache();

        $syncLog = SyncLog::where('entity_type', 'product')
            ->where('entity_id', (string) $erpId)
            ->whereIn('direction', ['erp_to_ecom', 'odoo_to_shopify', 'erp_to_shopify'])
            ->latest()
            ->first();

        // Build ecom payload preview
        $ecomPayload    = [];
        $shopifyPayload = []; // keep old var name for blade compat
        try {
            $shopifyService = app(\App\Services\Shopify\ShopifyProductService::class);
            $ecomPayload    = $shopifyService->buildPayload(
                $data['template']         ?? [],
                $data['variants']         ?? [],
                $data['attribute_values'] ?? [],
            );
            $shopifyPayload = $ecomPayload; // blade still references shopifyPayload
        } catch (\Throwable $e) {
            $ecomPayload    = ['_error' => $e->getMessage()];
            $shopifyPayload = $ecomPayload;
        }

        $ecomResponse    = null;
        $shopifyResponse = null;
        if ($syncLog?->response_payload) {
            $ecomResponse    = json_decode($syncLog->response_payload, true) ?? $syncLog->response_payload;
            $shopifyResponse = $ecomResponse;
        }

        $odooId = $erpId; // blade still references $odooId in some places

        return view('dashboard.products-detail', compact(
            'erpId', 'odooId', 'data', 'productCache', 'syncLog',
            'ecomPayload', 'shopifyPayload', 'ecomResponse', 'shopifyResponse'
        ));
    }

    private function showEcomToErp(int $erpId)
    {
        $mapping = SyncMapping::where('entity_type', 'product')
            ->where('erp_id', (string) $erpId)
            ->where('last_sync_direction', 'ecom_to_erp')
            ->first();

        if (!$mapping) {
            return back()->with('error', "No mapping found for product #{$erpId}.");
        }

        $syncLog = SyncLog::where('entity_type', 'product')
            ->where('entity_id', $mapping->ecom_id)
            ->where('direction', 'ecom_to_erp')
            ->latest()
            ->first();

        return view('dashboard.products-detail-ecom', compact('mapping', 'syncLog'));
    }

    // ── Single product actions ─────────────────────────────────────────────────

    public function fetchSingle(int $erpId)
    {
        if ($this->settings->productSyncMode() === 'ecom_to_erp') {
            return back()->with('error', 'Cannot fetch from ERP in Ecom → ERP mode.');
        }

        try {
            app(\App\Services\ProductCacheService::class)->fetchAndCacheSingle($erpId);
            return back()->with('success', "Product #{$erpId} fetched from " . $this->settings->erpDisplayName() . '.');
        } catch (\Throwable $e) {
            return back()->with('error', 'Fetch failed: ' . $e->getMessage());
        }
    }

    public function postSingle(int $erpId)
    {
        if ($this->settings->productSyncMode() === 'ecom_to_erp') {
            return back()->with('error', 'Cannot push to Ecom in Ecom → ERP mode.');
        }

        $ecomDriver = $this->settings->ecomDriver();
        $ecomJobMap = [
            'shopify' => \App\Jobs\Ecom\PushProductToEcomJob::class,
        ];

        if (!isset($ecomJobMap[$ecomDriver])) {
            return back()->with('error', "No push job registered for driver [{$ecomDriver}].");
        }

        $ecomJobMap[$ecomDriver]::dispatchSync($erpId);

        return back()->with('success',
            "Product #{$erpId} pushed to " . $this->settings->ecomDisplayName() . '.'
        );
    }

    public function refresh(int $erpId)
    {
        return $this->fetchSingle($erpId);
    }

    // ── Private data helpers ──────────────────────────────────────────────────

    private function getErpToEcomProducts(?string $search, string $status, int $perPage)
    {
        $query = ProductCache::query()->orderByDesc('fetched_at');

        if ($search) {
            $query->search($search);
        }

        // Use model scope — works before and after migration
        if (in_array($status, ['sent', 'failed', 'pending'])) {
            $query->ecomStatus($status);
        }

        return $query->paginate($perPage)->withQueryString();
    }

    private function getEcomToErpProducts(?string $search, string $status, int $perPage)
    {
        $query = SyncMapping::where('sync_mappings.entity_type', 'product')
            ->whereIn('sync_mappings.last_sync_direction', ['ecom_to_erp', 'shopify_to_erp', 'shopify_to_odoo'])
            ->orderByDesc('sync_mappings.last_synced_at');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('sync_mappings.ecom_id', 'like', "%{$search}%")
                  ->orWhere('sync_mappings.erp_id', 'like', "%{$search}%")
                  ->orWhere('sync_mappings.ecom_handle', 'like', "%{$search}%");
            });
        }

        $query->leftJoin('product_cache', function ($join) {
            $join->on('product_cache.erp_id', '=', 'sync_mappings.erp_id')
                 ->orOn('product_cache.odoo_id', '=', DB::raw('CAST(sync_mappings.erp_id AS UNSIGNED)'));
        })->select([
            'sync_mappings.*',
            'product_cache.name as product_name',
            'product_cache.default_code as sku',
        ]);

        if ($status !== 'all') {
            $query->whereExists(function ($q) use ($status) {
                $q->select(DB::raw(1))
                  ->from('sync_logs')
                  ->whereColumn('sync_logs.entity_id', 'sync_mappings.ecom_id')
                  ->where('sync_logs.entity_type', 'product')
                  ->whereIn('sync_logs.direction', ['ecom_to_erp', 'shopify_to_erp', 'shopify_to_odoo'])
                  ->where('sync_logs.status', $status)
                  ->limit(1);
            });
        }

        $results = $query->paginate($perPage)->withQueryString();

        $results->getCollection()->transform(function ($product) {
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

    private function getBidirectionalProducts(?string $search, string $status, int $perPage, string $direction)
    {
        return $direction === 'ecom_to_erp'
            ? $this->getEcomToErpProducts($search, $status, $perPage)
            : $this->getErpToEcomProducts($search, $status, $perPage);
    }

    private function getErpToEcomStats(): array
    {
        return [
            'total'   => ProductCache::count(),
            'sent'    => ProductCache::countEcomStatus('sent'),
            'failed'  => ProductCache::countEcomStatus('failed'),
            'pending' => ProductCache::countEcomStatus('pending'),
        ];
    }

    private function getEcomToErpStats(): array
    {
        $total = SyncMapping::where('entity_type', 'product')
            ->whereIn('last_sync_direction', ['ecom_to_erp', 'shopify_to_erp', 'shopify_to_odoo'])
            ->count();

        $success = DB::table('sync_mappings')
            ->join('sync_logs', function ($join) {
                $join->on('sync_logs.entity_id', '=', 'sync_mappings.ecom_id')
                     ->where('sync_logs.entity_type', 'product')
                     ->whereIn('sync_logs.direction', ['ecom_to_erp', 'shopify_to_erp', 'shopify_to_odoo'])
                     ->whereRaw('sync_logs.id = (
                         SELECT id FROM sync_logs sl2
                         WHERE sl2.entity_id = sync_mappings.ecom_id
                         AND sl2.entity_type = "product"
                         ORDER BY created_at DESC LIMIT 1
                     )');
            })
            ->where('sync_logs.status', 'success')
            ->count();

        $failed = DB::table('sync_mappings')
            ->join('sync_logs', function ($join) {
                $join->on('sync_logs.entity_id', '=', 'sync_mappings.ecom_id')
                     ->where('sync_logs.entity_type', 'product')
                     ->whereIn('sync_logs.direction', ['ecom_to_erp', 'shopify_to_erp', 'shopify_to_odoo'])
                     ->whereRaw('sync_logs.id = (
                         SELECT id FROM sync_logs sl2
                         WHERE sl2.entity_id = sync_mappings.ecom_id
                         AND sl2.entity_type = "product"
                         ORDER BY created_at DESC LIMIT 1
                     )');
            })
            ->where('sync_logs.status', 'failed')
            ->count();

        return [
            'total'   => $total,
            'success' => $success,
            'failed'  => $failed,
            'pending' => max(0, $total - $success - $failed),
        ];
    }

    private function getBidirectionalStats(): array
    {
        return [
            'erp_to_ecom' => $this->getErpToEcomStats(),
            'ecom_to_erp' => $this->getEcomToErpStats(),
            'total'       => ProductCache::count(),
        ];
    }
}