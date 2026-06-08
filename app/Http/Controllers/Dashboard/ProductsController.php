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

    // ── Fetch: ERP → cache only (no push) ───────────────────────────────────
    public function fetch()
    {
        // Use ProductCacheService directly instead of FetchErpProductsJob
        // to avoid ShouldBeUnique lock issues and ensure autoPush is never triggered.
        try {
            $erp       = app(\App\Services\Erp\ErpInterface::class);
            $cache     = app(\App\Services\ProductCacheService::class);
            $state     = \App\Models\SyncQueueState::forType('products');
            $writeDate = $state->getErpWriteDate();

            $products = $erp->getProductsModifiedSince($writeDate);

            if (empty($products)) {
                $state->markComplete($writeDate, 'nothing_changed');
                return back()->with('info', 'No new or updated products in ' . $this->settings->erpDisplayName() . ' since last sync.');
            }

            $fetched         = 0;
            $latestWriteDate = $writeDate;

            foreach ($products as $product) {
                $cache->fetchAndCacheSingle((int) $product['id']);
                $fetched++;
                if (($product['write_date'] ?? '') > $latestWriteDate) {
                    $latestWriteDate = $product['write_date'];
                }
            }

            // Advance cursor by 1 second
            $cursor = date('Y-m-d H:i:s', strtotime($latestWriteDate) + 1);
            $state->markComplete($cursor, "synced:{$fetched}");

            return back()->with('success', "{$fetched} product(s) fetched from " . $this->settings->erpDisplayName() . '. Use "Push to ' . $this->settings->ecomDisplayName() . '" to sync.');

        } catch (\Throwable $e) {
            return back()->with('error', 'Fetch from ERP failed: ' . $e->getMessage());
        }
    }

    // ── Pull: Ecom → local (fetch only, no push to ERP) ─────────────────────
    public function pull()
    {
        FetchEcomProductsJob::dispatchSync(fullSync: false);

        $notes = \App\Models\SyncQueueState::forType('products')->fresh()->notes ?? '';

        if ($notes === 'nothing_changed' || str_starts_with($notes, 'fetched:0')) {
            return back()->with('info', 'No new or updated products in ' . $this->settings->ecomDisplayName() . ' since last sync.');
        }

        if (str_starts_with($notes, 'fetched:')) {
            preg_match('/fetched:(\d+)(?::skipped:(\d+))?/', $notes, $m);
            $fetched = $m[1] ?? '?';
            $skipped = isset($m[2]) ? " ({$m[2]} unchanged skipped)" : '';
            return back()->with('success', "{$fetched} product(s) fetched{$skipped}. Click <strong>Push to " . $this->settings->erpDisplayName() . '</strong> to sync.');
        }

        return back()->with('success', 'Products fetched from ' . $this->settings->ecomDisplayName() . '. Click Push to ' . $this->settings->erpDisplayName() . '.');
    }

    // ── Post all: direction-aware push ───────────────────────────────────────
    // ecom_to_erp: push pulled Shopify products → Odoo
    // erp_to_ecom: push cached Odoo products → Shopify
    public function postAll(Request $request)
    {
        $syncMode   = $this->settings->productSyncMode();
        $ecomDriver = $this->settings->ecomDriver();

        // ── Shopify → Odoo (ecom_to_erp) ─────────────────────────────────
        if ($syncMode === 'ecom_to_erp') {
            $pending = SyncMapping::where('entity_type', 'product')
                ->whereIn('last_sync_direction', ['ecom_to_erp', 'shopify_to_erp', 'shopify_to_odoo'])
                ->where(function ($q) {
                    $q->whereNull('erp_id')->orWhere('erp_id', '0')->orWhere('erp_id', '');
                })
                ->get();

            if ($pending->isEmpty()) {
                return back()->with('info', 'No products pending push to ' . $this->settings->erpDisplayName() . '. Run "Fetch from ' . $this->settings->ecomDisplayName() . '" first.');
            }

            $pushed = 0;
            $failed = 0;
            $ecom   = app(\App\Services\Ecom\EcomInterface::class);

            foreach ($pending as $mapping) {
                try {
                    // Fetch fresh product data from Shopify
                    $ecomProduct = $ecom->getProduct($mapping->ecom_id);

                    if (empty($ecomProduct)) {
                        \Illuminate\Support\Facades\Log::warning("postAll ecom_to_erp: no data for ecom#{$mapping->ecom_id}");
                        $failed++;
                        continue;
                    }

                    // Create product in Odoo directly — maps Shopify title/price/SKU
                    $erp   = app(\App\Services\Erp\ErpInterface::class);
                    $erpId = $erp->createProduct($ecomProduct);

                    if ($erpId) {
                        $mapping->update([
                            'erp_id'         => (string) $erpId,
                            'last_synced_at'  => now(),
                            'last_sync_direction' => 'ecom_to_erp',
                        ]);
                    }

                    $pushed++;
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error("postAll ecom_to_erp: failed for ecom#{$mapping->ecom_id}: " . $e->getMessage());
                    $failed++;
                }
            }

            $msg = "{$pushed} product(s) pushed to " . $this->settings->erpDisplayName() . ".";
            if ($failed) $msg .= " {$failed} failed — check logs.";
            return back()->with($failed ? 'warning' : 'success', $msg);
        }

        // ── ERP → Ecom (erp_to_ecom or bidirectional) ───────────────────
        $ecomJobClass = app(\App\Services\ConnectorRegistry::class)->job($ecomDriver, 'push_product');

        if (!$ecomJobClass) {
            return back()->with('error', "No push job registered for ecom driver [{$ecomDriver}].");
        }

        $amazonEnabled = $this->settings->isAmazonChannelEnabled();

        // Only push pending/failed — skip already sent with no changes
        $erpIdCol = ProductCache::erpIdColumn();
        $records  = ProductCache::where(function ($q) {
                $col = ProductCache::ecomStatusColumn();
                $q->where($col, ProductCache::STATUS_PENDING)
                  ->orWhere($col, ProductCache::STATUS_FAILED)
                  ->orWhereNull($col);
            })->get();

        if ($records->isEmpty()) {
            return back()->with('info', 'No products pending push. Fetch from ' . $this->settings->erpDisplayName() . ' first.');
        }

        $queued  = 0;
        $skipped = 0;
        foreach ($records as $record) {
            $erpId = (int) $record->$erpIdCol;
            if (!$erpId) continue;

            // Skip if sent and write_date unchanged since last fetch
            if ($record->ecom_status === ProductCache::STATUS_SENT && $record->fetched_at) {
                $product      = app(\App\Services\Erp\ErpInterface::class)->getProductById($erpId);
                $erpWriteDate = $product['write_date'] ?? null;
                if ($erpWriteDate && !\Carbon\Carbon::parse($erpWriteDate)->isAfter(\Carbon\Carbon::parse($record->fetched_at))) {
                    $skipped++;
                    continue;
                }
            }

            $ecomJobClass::dispatch($erpId);
            if ($amazonEnabled) \App\Jobs\Amazon\PushProductToAmazonJob::dispatch($erpId);
            $queued++;
        }

        if ($queued === 0) {
            return back()->with('info', "All products already pushed and unchanged." . ($skipped > 0 ? " {$skipped} skipped." : ''));
        }

        $skipNote = $skipped > 0 ? " ({$skipped} already up to date skipped)" : '';
        return back()->with('success', "{$queued} product(s) queued to push to " . $this->settings->ecomDisplayName() . "{$skipNote}.");
    }

    // ── Show product detail ───────────────────────────────────────────────────

    public function show(int $erpId)
    {
        $syncMode = $this->settings->productSyncMode();

        if ($syncMode === 'ecom_to_erp') {
            return $this->showEcomToErp($erpId);
        }

        return $this->showErpToEcom($erpId);
    }

    private function showErpToEcom(int $erpId)
    {
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

        $ecomPayload    = [];
        $shopifyPayload = [];
        try {
            $shopifyService = app(\App\Services\Shopify\ShopifyProductService::class);
            $ecomPayload    = $shopifyService->buildPayload(
                $data['template']         ?? [],
                $data['variants']         ?? [],
                $data['attribute_values'] ?? [],
            );
            $shopifyPayload = $ecomPayload;
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

        $odooId = $erpId;

        return view('dashboard.products-detail', compact(
            'erpId', 'odooId', 'data', 'productCache', 'syncLog',
            'ecomPayload', 'shopifyPayload', 'ecomResponse', 'shopifyResponse'
        ));
    }

    private function showEcomToErp(int $id)
    {
        // In ecom_to_erp mode the ID may be either ecom_id (Shopify) or erp_id (Odoo)
        $mapping = SyncMapping::where('entity_type', 'product')
            ->whereIn('last_sync_direction', ['ecom_to_erp', 'shopify_to_erp', 'shopify_to_odoo'])
            ->where(function ($q) use ($id) {
                $q->where('ecom_id', (string) $id)
                  ->orWhere('erp_id', (string) $id);
            })
            ->first();

        if (!$mapping) {
            return back()->with('error', "No product mapping found for ID #{$id}.");
        }

        $syncLog = SyncLog::where('entity_type', 'product')
            ->where('entity_id', $mapping->ecom_id)
            ->where('direction', 'ecom_to_erp')
            ->latest()
            ->first();

        // Reuse products-detail view with ecom_to_erp data shape
        return view('dashboard.products-detail', [
            'erpId'          => $mapping->erp_id,
            'odooId'         => $mapping->erp_id,
            'data'           => ['ecom_id' => $mapping->ecom_id, 'ecom_handle' => $mapping->ecom_handle],
            'productCache'   => null,
            'syncLog'        => $syncLog,
            'ecomPayload'    => [],
            'shopifyPayload' => [],
            'ecomResponse'   => $syncLog?->response_payload ? json_decode($syncLog->response_payload, true) : null,
            'shopifyResponse'=> $syncLog?->response_payload ? json_decode($syncLog->response_payload, true) : null,
            'mapping'        => $mapping,
        ]);
    }

    // ── Single product actions ─────────────────────────────────────────────────

    public function fetchSingle(int $erpId)
    {
        try {
            $cacheService = app(\App\Services\ProductCacheService::class);
            $erpIdCol     = ProductCache::erpIdColumn();
            $before       = ProductCache::where($erpIdCol, $erpId)->value('fetched_at');

            $cache = $cacheService->fetchAndCacheSingle($erpId);

            // Detect if the cache was actually updated (fetched_at changed) or skipped
            $after = $cache->fresh()->fetched_at;
            $wasSkipped = $before && $after && \Carbon\Carbon::parse($before)->eq(\Carbon\Carbon::parse($after));

            if ($wasSkipped) {
                return back()->with('info', "Product #{$erpId} is up to date — no changes in " . $this->settings->erpDisplayName() . ".");
            }

            return back()->with('success', "Product #{$erpId} fetched from " . $this->settings->erpDisplayName() . '.');
        } catch (\Throwable $e) {
            return back()->with('error', 'Fetch failed: ' . $e->getMessage());
        }
    }

    public function postSingle(int $erpId)
    {
        $ecomDriver   = $this->settings->ecomDriver();
        $ecomJobClass = app(\App\Services\ConnectorRegistry::class)->job($ecomDriver, 'push_product');

        if (!$ecomJobClass) {
            return back()->with('error', "No push job registered for driver [{$ecomDriver}].");
        }

        // Skip if already sent and ERP write_date hasn't changed since last push
        $erpIdCol = ProductCache::erpIdColumn();
        $cache    = ProductCache::where($erpIdCol, $erpId)->first();

        if ($cache && $cache->ecom_status === ProductCache::STATUS_SENT) {
            $product      = app(\App\Services\Erp\ErpInterface::class)->getProductById($erpId);
            $erpWriteDate = $product['write_date'] ?? null;

            if ($erpWriteDate && $cache->fetched_at) {
                $erpWrittenAt = \Carbon\Carbon::parse($erpWriteDate);
                $fetchedAt    = \Carbon\Carbon::parse($cache->fetched_at);

                if (!$erpWrittenAt->isAfter($fetchedAt)) {
                    return back()->with('info',
                        "Product #{$erpId} already pushed and unchanged — skipped."
                    );
                }
            }
        }

        $ecomJobClass::dispatchSync($erpId);

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

    // ── Pull single product from Ecom → store as pending (ecom_to_erp) ────
    public function pullSingle(string $ecomId)
    {
        try {
            $ecom    = app(\App\Services\Ecom\EcomInterface::class);
            $product = $ecom->getProduct($ecomId);

            if (empty($product)) {
                return back()->with('error', "Product #{$ecomId} not found in " . $this->settings->ecomDisplayName() . '.');
            }

            $updatedAt = $product['updated_at'] ?? null;

            // Skip if already stored and unchanged
            $existing = SyncMapping::where('entity_type', 'product')
                ->where('ecom_id', (string) $ecomId)
                ->first();

            if ($existing) {
                // Already in Odoo — skip entirely, don't reset to pending
                if ($existing->erp_id && $existing->erp_id !== '0') {
                    return back()->with('info', "Product #{$ecomId} already synced to " . $this->settings->erpDisplayName() . " — no action needed.");
                }
                $prevMeta      = is_array($existing->metadata) ? $existing->metadata : json_decode($existing->metadata ?? '{}', true);
                $prevUpdatedAt = $prevMeta['updated_at'] ?? null;
                if ($prevUpdatedAt && $updatedAt && $prevUpdatedAt === $updatedAt && $existing->ecom_status === 'pending') {
                    return back()->with('info', "Product #{$ecomId} already fetched and pending push.");
                }
            }

            SyncMapping::updateOrCreate(
                ['entity_type' => 'product', 'ecom_id' => (string) $ecomId, 'ecom_driver' => $this->settings->ecomDriver()],
                [
                    'ecom_handle'         => $product['handle'] ?? null,
                    'last_sync_direction' => 'ecom_to_erp',
                    'ecom_status'         => 'pending',
                    'metadata'            => $product,
                    'last_synced_at'      => now(),
                ]
            );

            return back()->with('success', "Product #{$ecomId} fetched. Click Push to " . $this->settings->erpDisplayName() . ' to create it.');
        } catch (\Throwable $e) {
            return back()->with('error', 'Fetch failed: ' . $e->getMessage());
        }
    }

    // ── Push single product from local → ERP (ecom_to_erp) ────────────────
    public function pushSingleToErp(string $ecomId)
    {
        try {
            $mapping = SyncMapping::where('entity_type', 'product')
                ->where('ecom_id', (string) $ecomId)
                ->whereNotNull('metadata')
                ->first();

            if (!$mapping) {
                return back()->with('error', "No data for product #{$ecomId}. Run Fetch first.");
            }

            // Already in ERP — block re-push entirely
            if ($mapping->erp_id && $mapping->erp_id !== '0') {
                return back()->with('info', "Product #{$ecomId} already in " . $this->settings->erpDisplayName() . " (ID: #{$mapping->erp_id}).");
            }

            $ecomProduct = is_array($mapping->metadata) ? $mapping->metadata : json_decode($mapping->metadata, true);
            $erp         = app(\App\Services\Erp\ErpInterface::class);

            $log = \App\Models\SyncLog::create([
                'direction'       => \App\Models\SyncLog::DIRECTION_ECOM_TO_ERP,
                'entity_type'     => 'product',
                'entity_id'       => (string) $ecomId,
                'action'          => 'create',
                'status'          => \App\Models\SyncLog::STATUS_PROCESSING,
                'request_payload' => json_encode($ecomProduct),
            ]);

            $erpId = $erp->createProduct($ecomProduct);

            if ($erpId) {
                $mapping->update(['erp_id' => (string) $erpId, 'ecom_status' => 'posted', 'last_synced_at' => now(), 'last_sync_direction' => 'ecom_to_erp']);
                $log->markSuccess(json_encode(['erp_id' => $erpId]));
            } else {
                $log->markFailed('ERP createProduct returned no ID');
            }

            return back()->with('success', "Product #{$ecomId} pushed to " . $this->settings->erpDisplayName() . " (ID: #{$erpId}).");
        } catch (\Throwable $e) {
            if (isset($log)) $log->markFailed($e->getMessage());
            return back()->with('error', 'Push failed: ' . $e->getMessage());
        }
    }
	
}