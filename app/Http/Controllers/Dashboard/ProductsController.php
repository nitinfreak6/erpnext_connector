<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\HandlesAjaxSyncResponses;
use App\Jobs\Ecom\FetchEcomProductsJob;
use App\Jobs\Erp\FetchErpProductsJob;
use App\Models\ProductCache;
use App\Models\SyncLog;
use App\Models\SyncMapping;
use App\Services\SettingsService;
use App\Services\Sync\EcomToErpProductState;
use App\Services\Sync\ProductSyncService;
use App\Support\ErpId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProductsController extends Controller
{
    use HandlesAjaxSyncResponses;

    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    public function index(Request $request)
    {
        $ctx = $this->listingContext($request);

        $products = $this->queryProducts(
            $ctx['syncMode'],
            $ctx['search'],
            $ctx['status'],
            $ctx['perPage'],
            $ctx['direction']
        );

        $stats = match($ctx['syncMode']) {
            'erp_to_ecom'   => $this->getErpToEcomStats(),
            'ecom_to_erp'   => $this->getEcomToErpStats(),
            'bidirectional' => $this->getBidirectionalStats(),
            default         => [],
        };

        return view('dashboard.products', array_merge($ctx, compact('products', 'stats')));
    }

    public function rows(Request $request): JsonResponse
    {
        $ctx = $this->listingContext($request);

        $products = $this->queryProducts(
            $ctx['syncMode'],
            $ctx['search'],
            $ctx['status'],
            $ctx['perPage'],
            $ctx['direction']
        );

        return response()->json([
            'html' => $this->renderProductsRowsHtml($products, $ctx),
        ]);
    }

    private function listingContext(Request $request): array
    {
        return [
            'syncMode'     => $this->settings->productSyncMode(),
            'search'       => $request->input('search', ''),
            'status'       => $request->input('status', 'all'),
            'perPage'      => (int) $request->input('per_page', 25),
            'direction'    => $request->input('direction', 'erp_to_ecom'),
            'ecomDriver'   => $this->settings->ecomDriver(),
            'shopifyStore' => $this->settings->shopifyShop() ?: config('shopify.shop', '—'),
        ];
    }

    private function queryProducts(string $syncMode, ?string $search, string $status, int $perPage, string $direction)
    {
        return match($syncMode) {
            'erp_to_ecom'   => $this->getErpToEcomProducts($search, $status, $perPage),
            'ecom_to_erp'   => $this->getEcomToErpProducts($search, $status, $perPage),
            'bidirectional' => $this->getBidirectionalProducts($search, $status, $perPage, $direction),
            default         => collect([]),
        };
    }

    private function renderProductsRowsHtml($products, array $ctx): string
    {
        return view('dashboard.partials.products-table-rows', array_merge($ctx, [
            'products' => $products,
        ]))->render();
    }

    private function renderProductRowHtml($product, array $ctx): string
    {
        return view('dashboard.partials.products-table-row', array_merge($ctx, [
            'product'  => $product,
            'rowIndex' => abs(crc32(
                ($product->ecom_id ?? $product->ecom_product_id ?? $product->erp_id ?? $product->odoo_id ?? '0')
            )),
        ]))->render();
    }

    private function findEcomToErpProductRow(string $ecomId)
    {
        $query = SyncMapping::where('sync_mappings.entity_type', 'product')
            ->where('sync_mappings.ecom_id', (string) $ecomId)
            ->whereIn('sync_mappings.last_sync_direction', ['ecom_to_erp', 'shopify_to_erp', 'shopify_to_odoo'])
            ->leftJoin('product_cache', function ($join) {
                $join->on('product_cache.ecom_product_id', '=', 'sync_mappings.ecom_id');
            })
            ->select([
                'sync_mappings.*',
                'product_cache.name as product_name',
                'product_cache.default_code as sku',
                'product_cache.ecom_status as cache_ecom_status',
                'product_cache.ecom_message as cache_ecom_message',
            ]);

        $product = $query->first();

        if (!$product) {
            return null;
        }

        $product->display_status = EcomToErpProductState::displayStatus($product);
        $product->sync_message   = $product->display_status === \App\Services\Sync\SyncEntityState::STATUS_SENT
            ? null
            : ($product->sync_message ?: ($product->cache_ecom_message ?? null));

        return $product;
    }

    // ── Fetch: ERP → cache only (no push) ───────────────────────────────────
    public function fetch(Request $request)
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
                return $this->productActionResponse(
                    $request,
                    'info',
                    'No new or updated products in ' . $this->settings->erpDisplayName() . ' since last sync.',
                    ['fetched' => 0]
                );
            }

            $fetched         = 0;
            $latestWriteDate = $writeDate;

            foreach ($products as $product) {
                $cache->cacheProduct($product);
                $fetched++;
                if (($product['write_date'] ?? '') > $latestWriteDate) {
                    $latestWriteDate = $product['write_date'];
                }
            }

            // Advance cursor by 1 second
            $cursor = date('Y-m-d H:i:s', strtotime($latestWriteDate) + 1);
            $state->markComplete($cursor, "synced:{$fetched}");

            return $this->productActionResponse(
                $request,
                'success',
                "{$fetched} product(s) fetched from " . $this->settings->erpDisplayName() . '. Use "Push to ' . $this->settings->ecomDisplayName() . '" to sync.',
                ['fetched' => $fetched, 'refresh_table' => true]
            );

        } catch (\Throwable $e) {
            return $this->productActionResponse(
                $request,
                'error',
                'Fetch from ERP failed: ' . $e->getMessage(),
                status: 500
            );
        }
    }

    // ── Pull: Ecom → local (fetch only, no push to ERP) ─────────────────────
    public function pull(Request $request)
    {
        try {
            FetchEcomProductsJob::dispatchSync(fullSync: false);

            $notes = \App\Models\SyncQueueState::forType('products')->fresh()->notes ?? '';

            if ($notes === 'nothing_changed' || str_starts_with($notes, 'fetched:0')) {
                return $this->productActionResponse(
                    $request,
                    'info',
                    'No new or updated products in ' . $this->settings->ecomDisplayName() . ' since last sync.',
                    ['fetched' => 0]
                );
            }

            if (str_starts_with($notes, 'fetched:')) {
                preg_match('/fetched:(\d+)(?::skipped:(\d+))?/', $notes, $m);
                $fetched = (int) ($m[1] ?? 0);
                $skipped = isset($m[2]) ? (int) $m[2] : 0;
                $skipNote = $skipped > 0 ? " ({$skipped} unchanged skipped)" : '';
                return $this->productActionResponse(
                    $request,
                    'success',
                    "{$fetched} product(s) updated{$skipNote}. Click Push to " . $this->settings->erpDisplayName() . ' to sync.',
                    ['fetched' => $fetched, 'skipped' => $skipped, 'refresh_table' => true]
                );
            }

            return $this->productActionResponse(
                $request,
                'success',
                'Products fetched from ' . $this->settings->ecomDisplayName() . '. Click Push to ' . $this->settings->erpDisplayName() . '.',
                ['refresh_table' => true]
            );
        } catch (\Throwable $e) {
            return $this->productActionResponse(
                $request,
                'error',
                'Fetch from ' . $this->settings->ecomDisplayName() . ' failed: ' . $e->getMessage(),
                status: 500
            );
        }
    }

    // ── Post all: direction-aware push ───────────────────────────────────────
    // ecom_to_erp: push pulled Shopify products → Odoo
    // erp_to_ecom: push cached Odoo products → Shopify
    public function postAll(Request $request)
    {
        $syncMode   = $this->settings->productSyncMode();
        $ecomDriver = $this->settings->ecomDriver();
        $pushDirection = $syncMode === 'bidirectional'
            ? ($request->input('direction') === 'ecom_to_erp' ? 'ecom_to_erp' : 'erp_to_ecom')
            : $syncMode;

        // ── Shopify → Odoo (ecom_to_erp) ─────────────────────────────────
        if ($pushDirection === 'ecom_to_erp') {
            $pending = $this->ecomToErpPushableQuery()->get();

            if ($pending->isEmpty()) {
                return $this->productActionResponse(
                    $request,
                    'info',
                    'No products pending push to ' . $this->settings->erpDisplayName() . '. Run "Fetch from ' . $this->settings->ecomDisplayName() . '" first, or retry failed products.',
                    ['pushed' => 0]
                );
            }

            $pushed      = 0;
            $failed      = 0;
            $ecom        = app(\App\Services\Ecom\EcomInterface::class);
            $syncService = app(ProductSyncService::class);

            foreach ($pending as $mapping) {
                try {
                    // Fetch fresh product data from ecom
                    $ecomProduct = $ecom->getProduct($mapping->ecom_id);

                    if (empty($ecomProduct)) {
                        $meta = is_array($mapping->metadata)
                            ? $mapping->metadata
                            : json_decode($mapping->metadata ?? '{}', true);
                        $ecomProduct = $meta['product'] ?? $meta;
                    }

                    if (empty($ecomProduct)) {
                        \Illuminate\Support\Facades\Log::warning("postAll ecom_to_erp: no data for ecom#{$mapping->ecom_id}");
                        $failed++;
                        continue;
                    }

                    // Config-driven push — same path as webhooks (product_field_configs)
                    $syncService->syncEcomProductToErp($ecomProduct);

                    $pushed++;
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error("postAll ecom_to_erp: failed for ecom#{$mapping->ecom_id}: " . $e->getMessage());
                    EcomToErpProductState::markFailed($mapping->ecom_id, $e->getMessage());
                    $failed++;
                }
            }

            $msg = "{$pushed} product(s) pushed to " . $this->settings->erpDisplayName() . ".";
            if ($failed) {
                $msg .= " {$failed} failed — check logs.";
            }

            return $this->productActionResponse(
                $request,
                $failed ? 'warning' : 'success',
                $msg,
                ['pushed' => $pushed, 'failed' => $failed, 'refresh_table' => true]
            );
        }

        // ── ERP → Ecom (erp_to_ecom or bidirectional) ───────────────────
        $ecomJobClass = app(\App\Services\ConnectorRegistry::class)->job($ecomDriver, 'push_product');

        if (!$ecomJobClass) {
            return $this->productActionResponse(
                $request,
                'error',
                "No push job registered for ecom driver [{$ecomDriver}]."
            );
        }

        $amazonEnabled = $this->settings->isAmazonChannelEnabled();

        // Push pending, updated, and legacy failed rows
        $erpIdCol = ProductCache::erpIdColumn();
        $records  = ProductCache::where(function ($q) {
                $col = ProductCache::ecomStatusColumn();
                $q->whereIn($col, [
                    ProductCache::STATUS_PENDING,
                    ProductCache::STATUS_UPDATED,
                    ProductCache::STATUS_FAILED,
                ])->orWhereNull($col);
            })->get();

        if ($records->isEmpty()) {
            return $this->productActionResponse(
                $request,
                'info',
                'No products pending push. Fetch from ' . $this->settings->erpDisplayName() . ' first.',
                ['queued' => 0]
            );
        }

        $queued  = 0;
        $failed  = 0;
        $skipped = 0;
        $lastError = null;

        foreach ($records as $record) {
            $erpId = ErpId::normalize($record->$erpIdCol);
            if ($erpId === 0 || $erpId === '0' || $erpId === '') {
                continue;
            }

            // Skip if sent and write_date unchanged since last fetch
            if ($record->ecom_status === ProductCache::STATUS_SENT && $record->fetched_at) {
                $product      = app(\App\Services\Erp\ErpInterface::class)->getProductById($erpId);
                $erpWriteDate = $product['write_date'] ?? null;
                if ($erpWriteDate && !\Carbon\Carbon::parse($erpWriteDate)->isAfter(\Carbon\Carbon::parse($record->fetched_at))) {
                    $skipped++;
                    continue;
                }
            }

            try {
                $ecomJobClass::dispatchSync($erpId);
                $queued++;
            } catch (\Throwable $e) {
                $failed++;
                $lastError = $e->getMessage();
                \Illuminate\Support\Facades\Log::error(
                    "postAll erp_to_ecom: failed for erp#{$erpId}: " . $e->getMessage()
                );
            }

            if ($amazonEnabled) {
                try {
                    \App\Jobs\Amazon\PushProductToAmazonJob::dispatchSync($erpId);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error(
                        "postAll amazon: failed for erp#{$erpId}: " . $e->getMessage()
                    );
                }
            }
        }

        if ($queued === 0 && $failed === 0) {
            return $this->productActionResponse(
                $request,
                'info',
                "All products already pushed and unchanged." . ($skipped > 0 ? " {$skipped} skipped." : ''),
                ['queued' => 0, 'skipped' => $skipped, 'refresh_table' => true]
            );
        }

        $skipNote = $skipped > 0 ? " ({$skipped} already up to date skipped)" : '';
        $msg      = "{$queued} product(s) pushed to " . $this->settings->ecomDisplayName() . ".{$skipNote}";
        if ($failed > 0) {
            $msg .= " {$failed} failed.";
            if ($lastError) {
                $msg .= ' ' . \Illuminate\Support\Str::limit($lastError, 240);
            }
        }

        $level = $failed > 0 ? ($queued > 0 ? 'warning' : 'error') : 'success';

        return $this->productActionResponse(
            $request,
            $level,
            $msg,
            ['queued' => $queued, 'failed' => $failed, 'skipped' => $skipped, 'refresh_table' => true]
        );
    }

    // ── Show product detail ───────────────────────────────────────────────────

    public function show(string $id)
    {
        $syncMode = $this->settings->productSyncMode();

        if ($syncMode === 'ecom_to_erp') {
            return $this->showEcomToErp($id);
        }

        return $this->showErpToEcom(is_numeric($id) ? (int) $id : $id);
    }

    private function showErpToEcom(int|string $erpId)
    {
        $lookup = (string) $erpId;
        $productCache = ProductCache::where('erp_id', $lookup)
            ->orWhere('odoo_id', $lookup)
            ->first();

        if (!$productCache || !$productCache->cacheExists()) {
            return redirect()->route('dashboard.products')
                ->with('error', "No cached data for product #{$erpId}.");
        }

        $data = $productCache->readCache();

        $syncLog = SyncLog::where('entity_type', 'product')
            ->where('entity_id', (string) $erpId)
            ->whereIn('direction', ['erp_to_ecom', 'odoo_to_shopify', 'erp_to_shopify'])
            ->latest()
            ->first();

        // Only build the outgoing payload when the product has actually been
        // pushed at least once. Before that the Shopify tab shows a placeholder.
        $ecomPayload = null;
        if ($syncLog) {
            // Prefer the exact payload that was sent (stored on the log row).
            // Fall back to rebuilding it live so the tab is never blank on old logs.
            if ($syncLog->request_payload) {
                $decoded     = json_decode($syncLog->request_payload, true);
                $ecomPayload = $decoded ?? [];
            }

            if (empty($ecomPayload)) {
                try {
                    $shopifyService = app(\App\Services\Shopify\ShopifyProductService::class);
                    $ecomPayload    = $shopifyService->buildPayload(
                        $data['template']         ?? [],
                        $data['variants']         ?? [],
                        $data['attribute_values'] ?? [],
                        ['vendors' => $data['vendors'] ?? []],
                    );
                } catch (\Throwable $e) {
                    $ecomPayload = ['_error' => $e->getMessage()];
                }
            }
        }

        $ecomResponse = null;
        if ($syncLog?->response_payload) {
            $ecomResponse = json_decode($syncLog->response_payload, true) ?? $syncLog->response_payload;
        }

        $odooId = $erpId;
        $shopifyStore = $this->settings->shopifyShop();
        $apiVersion  = $this->settings->shopifyVersion() ?: '2024-01';

        $syncMode = 'erp_to_ecom';
        return view('dashboard.products-detail', compact(
            'syncMode', 'erpId', 'odooId', 'data', 'shopifyStore', 'apiVersion', 'productCache', 'syncLog',
            'ecomPayload', 'ecomResponse'
        ));
    }

    private function showEcomToErp(string $id)
    {
        // ID may be ecom_id (Shopify) or erp_id (Odoo)
        $mapping = SyncMapping::where('entity_type', 'product')
            ->whereIn('last_sync_direction', ['ecom_to_erp', 'shopify_to_erp', 'shopify_to_odoo'])
            ->where(function ($q) use ($id) {
                $q->where('ecom_id', $id)->orWhere('erp_id', $id);
            })
            ->first();

        if (!$mapping) {
            return redirect()->route('dashboard.products')->with('error', "No product mapping found for ID #{$id}.");
        }

        // ── Load cached JSON from storage (mirrors erp_to_ecom readCache) ──
        $filePath  = 'ecom_products/' . $mapping->ecom_id . '.json';
        $cacheData = null;

        if (Storage::disk('local')->exists($filePath)) {
            $raw       = Storage::disk('local')->get($filePath);
            $cacheData = $raw ? json_decode($raw, true) : null;
        }

        // Fallback: rebuild from SyncMapping metadata if file missing
        if (!$cacheData) {
            $meta      = is_array($mapping->metadata) ? $mapping->metadata : json_decode($mapping->metadata ?? '{}', true);
            $cacheData = [
                'fetched_at'  => $mapping->last_synced_at?->toISOString(),
                'ecom_id'     => $mapping->ecom_id,
                'ecom_driver' => $mapping->ecom_driver ?? $this->settings->ecomDriver(),
                'product'     => $meta,
            ];
        }

        // ── Push log (create/update) — includes failed attempts ──
        $pushLog = SyncLog::where('entity_type', 'product')
            ->where('entity_id', $mapping->ecom_id)
            ->whereIn('direction', ['ecom_to_erp', 'shopify_to_erp', 'shopify_to_odoo'])
            ->whereIn('action', ['create', 'update'])
            ->whereIn('status', ['success', 'failed'])
            ->latest()
            ->first();

        // What was sent TO the ERP and what ERP returned (only present after an actual push)
        $erpPayload = null;
        if ($pushLog?->request_payload) {
            $decoded    = json_decode($pushLog->request_payload, true) ?? [];
            $erpPayload = $this->resolveEcomToErpDisplayPayload($decoded);
            $erpPayload = $this->formatErpPayloadForDisplay($erpPayload);
        }

        // Rebuild from field config when no push log payload yet (preview or failed before RPC)
        if (empty($erpPayload) && !empty($cacheData['product'])) {
            try {
                $erpPayload = app(\App\Services\FieldMappingService::class)->buildErpProductPayload(
                    $cacheData['product'],
                    $this->settings->ecomDriver(),
                    $this->settings->erpDriver()
                );
                $erpPayload = $this->formatErpPayloadForDisplay(['values' => $erpPayload]);
            } catch (\Throwable $e) {
                $erpPayload = ['_error' => $e->getMessage()];
            }
        }

        $erpResponse = $pushLog?->response_payload
            ? (json_decode($pushLog->response_payload, true) ?? $pushLog->response_payload)
            : null;

        $shopifyStore = $this->settings->shopifyShop() ?: config('shopify.shop', '—');
        $apiVersion   = $this->settings->shopifyVersion() ?: '2024-01';

        return view('dashboard.products-detail', [
            'syncMode'     => 'ecom_to_erp',
            'erpId'        => $mapping->erp_id ?: null,
            'odooId'       => $mapping->erp_id ?: null,
            'ecomId'       => $mapping->ecom_id,
            'data'         => $cacheData,
            'productCache' => null,
            'syncLog'      => $pushLog,      // null until actually pushed to ERP
            'ecomPayload'  => $erpPayload,   // payload sent to ERP
            'ecomResponse' => $erpResponse,  // ERP response
            'mapping'      => $mapping,
            'shopifyStore' => $shopifyStore,
            'apiVersion'   => $apiVersion,
        ]);
    }

    // ── Single product actions ─────────────────────────────────────────────────

    public function fetchSingle(string $erpId)
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
                return redirect()->route('dashboard.products.show', $erpId)->with('info', "Product #{$erpId} is up to date — no changes in " . $this->settings->erpDisplayName() . ".");
            }

            return redirect()->route('dashboard.products.show', $erpId)->with('success', "Product #{$erpId} fetched from " . $this->settings->erpDisplayName() . '.');
        } catch (\Throwable $e) {
            return redirect()->route('dashboard.products.show', $erpId)->with('error', 'Fetch failed: ' . $e->getMessage());
        }
    }

    public function postSingle(Request $request, string $erpId)
    {
        $erpIdCol = ProductCache::erpIdColumn();
        $ctx      = $this->listingContext($request);

        try {
            $ecomDriver   = $this->settings->ecomDriver();
            $ecomJobClass = app(\App\Services\ConnectorRegistry::class)->job($ecomDriver, 'push_product');

            if (!$ecomJobClass) {
                return $this->productActionResponse(
                    $request,
                    'error',
                    "No push job registered for driver [{$ecomDriver}]."
                );
            }

            $cache = ProductCache::where($erpIdCol, $erpId)->first();

            if ($cache && $cache->ecom_status === ProductCache::STATUS_SENT) {
                $product      = app(\App\Services\Erp\ErpInterface::class)->getProductById($erpId);
                $erpWriteDate = $product['write_date'] ?? null;

                if ($erpWriteDate && $cache->fetched_at) {
                    $erpWrittenAt = \Carbon\Carbon::parse($erpWriteDate);
                    $fetchedAt    = \Carbon\Carbon::parse($cache->fetched_at);

                    if (!$erpWrittenAt->isAfter($fetchedAt)) {
                        return $this->productActionResponse(
                            $request,
                            'info',
                            "Product #{$erpId} already pushed and unchanged — skipped.",
                            array_merge(
                                $this->erpRowPayload($ctx, $erpId, $cache),
                                ['product_id' => $erpId, 'skipped' => true]
                            )
                        );
                    }
                }
            }

            $ecomJobClass::dispatchSync($erpId);

            $cache = ProductCache::where($erpIdCol, $erpId)->first();

            return $this->productActionResponse(
                $request,
                'success',
                "Product #{$erpId} pushed to " . $this->settings->ecomDisplayName() . '.',
                array_merge(
                    $this->erpRowPayload($ctx, $erpId, $cache),
                    ['product_id' => $erpId]
                )
            );
        } catch (\Throwable $e) {
            $cache = ProductCache::where($erpIdCol, $erpId)->first();

            return $this->productActionResponse(
                $request,
                'error',
                'Push failed: ' . $e->getMessage(),
                array_merge(
                    $cache ? $this->erpRowPayload($ctx, $erpId, $cache) : [],
                    ['product_id' => $erpId, 'refresh_table' => true]
                ),
                status: 500
            );
        }
    }

    public function refresh(string $erpId)
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

        if (in_array($status, ['sent', 'updated', 'pending'])) {
            if ($status === 'sent') {
                $query->ecomStatus(ProductCache::STATUS_SENT);
            } elseif ($status === 'updated') {
                $query->ecomStatus(ProductCache::STATUS_UPDATED);
            } else {
                $col = ProductCache::ecomStatusColumn();
                $query->where(function ($q) use ($col) {
                    $q->whereIn($col, [ProductCache::STATUS_PENDING, ProductCache::STATUS_FAILED])
                      ->orWhereNull($col);
                });
            }
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
            // Ecom → ERP cache rows are keyed by ecom_product_id (the fetch job
            // never sets erp_id on them), so join on the ecom id — the stable
            // shared key in this direction. Joining on erp_id silently misses
            // and the view falls back to the handle, showing a stale name.
            $join->on('product_cache.ecom_product_id', '=', 'sync_mappings.ecom_id');
        })->select([
            'sync_mappings.*',
            'product_cache.name as product_name',
            'product_cache.default_code as sku',
            'product_cache.ecom_status as cache_ecom_status',
            'product_cache.ecom_message as cache_ecom_message',
        ]);

        if ($status !== 'all') {
            if ($status === 'sent' || $status === 'success') {
                $query->whereIn('sync_mappings.ecom_status', EcomToErpProductState::SYNCED_STATUSES);
            } elseif ($status === 'updated') {
                $query->where('sync_mappings.ecom_status', EcomToErpProductState::STATUS_UPDATED);
            } elseif ($status === 'pending') {
                $query->where(function ($q) {
                    $q->whereIn('sync_mappings.ecom_status', [
                        EcomToErpProductState::STATUS_PENDING,
                        EcomToErpProductState::STATUS_FAILED,
                    ])->orWhereNull('sync_mappings.ecom_status');
                });
            }
        }

        $results = $query->paginate($perPage)->withQueryString();

        $results->getCollection()->transform(function ($product) {
            $product->display_status = EcomToErpProductState::displayStatus($product);

            if ($product->display_status === \App\Services\Sync\SyncEntityState::STATUS_SENT) {
                $product->sync_message = null;
            } else {
                $product->sync_message = $product->sync_message ?: ($product->cache_ecom_message ?? null);
            }

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
        $col = ProductCache::ecomStatusColumn();

        return [
            'total'   => ProductCache::count(),
            'sent'    => ProductCache::countEcomStatus(ProductCache::STATUS_SENT),
            'updated' => ProductCache::where($col, ProductCache::STATUS_UPDATED)->count(),
            'pending' => ProductCache::where(function ($q) use ($col) {
                $q->whereIn($col, [ProductCache::STATUS_PENDING, ProductCache::STATUS_FAILED])
                  ->orWhereNull($col);
            })->count(),
        ];
    }

    private function getEcomToErpStats(): array
    {
        $base = SyncMapping::where('entity_type', 'product')
            ->whereIn('last_sync_direction', ['ecom_to_erp', 'shopify_to_erp', 'shopify_to_odoo']);

        $total = (clone $base)->count();

        $sent = (clone $base)->whereIn('ecom_status', EcomToErpProductState::SYNCED_STATUSES)->count();

        $updated = (clone $base)->where('ecom_status', EcomToErpProductState::STATUS_UPDATED)->count();

        $pending = (clone $base)->where(function ($q) {
            $q->whereIn('ecom_status', [
                EcomToErpProductState::STATUS_PENDING,
                EcomToErpProductState::STATUS_FAILED,
            ])->orWhereNull('ecom_status');
        })->count();

        return [
            'total'   => $total,
            'sent'    => $sent,
            'updated' => $updated,
            'pending' => $pending,
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
                return redirect()->route('dashboard.products')->with('error', "Product #{$ecomId} not found in " . $this->settings->ecomDisplayName() . '.');
            }

            $existing = SyncMapping::where('entity_type', 'product')
                ->where('ecom_id', (string) $ecomId)
                ->first();

            if ($existing && !EcomToErpProductState::productChangedSinceLastSync($existing, $product)) {
                return redirect()->route('dashboard.products')->with('info',
                    "Product #{$ecomId} is up to date in " . $this->settings->ecomDisplayName() . ' — no changes to fetch.'
                );
            }

            $cacheData = [
                'fetched_at'  => now()->toISOString(),
                'ecom_id'     => (string) $ecomId,
                'ecom_driver' => $this->settings->ecomDriver(),
                'product'     => $product,
            ];
            $filePath = 'ecom_products/' . $ecomId . '.json';
            Storage::disk('local')->put($filePath, json_encode($cacheData, JSON_PRETTY_PRINT));

            EcomToErpProductState::markFetched(
                (string) $ecomId,
                $product,
                $existing,
                $filePath,
                $cacheData,
                $this->settings->ecomDriver()
            );

            SyncLog::create([
                'direction'       => SyncLog::DIRECTION_ECOM_TO_ERP,
                'entity_type'     => 'product',
                'entity_id'       => (string) $ecomId,
                'action'          => 'fetch',
                'status'          => SyncLog::STATUS_SUCCESS,
                'request_payload' => json_encode($product),
                'synced_at'       => now(),
            ]);

            $fetchStatus = EcomToErpProductState::fetchStatus($existing, $product);
            $statusLabel = $fetchStatus === EcomToErpProductState::STATUS_UPDATED ? 'marked updated' : 'fetched';

            return redirect()->route('dashboard.products')->with('success',
                "Product #{$ecomId} {$statusLabel}. Click Push to " . $this->settings->erpDisplayName() . ' to sync.'
            );
        } catch (\Throwable $e) {
            return redirect()->route('dashboard.products')->with('error', 'Fetch failed: ' . $e->getMessage());
        }
    }

    // ── Push single product from local → ERP (ecom_to_erp) ────────────────
    public function pushSingleToErp(Request $request, string $ecomId)
    {
        $ctx = $this->listingContext($request);

        try {
            $mapping = SyncMapping::where('entity_type', 'product')
                ->where('ecom_id', (string) $ecomId)
                ->first();

            if (!$mapping) {
                return $this->productActionResponse(
                    $request,
                    'error',
                    "No data for product #{$ecomId}. Run Fetch first.",
                    ['product_id' => $ecomId]
                );
            }

            if (!EcomToErpProductState::needsPush($mapping)) {
                $product = $this->findEcomToErpProductRow($ecomId);

                return $this->productActionResponse(
                    $request,
                    'info',
                    "Product #{$ecomId} is already synced and unchanged. Fetch from " . $this->settings->ecomDisplayName() . ' first if you edited it.',
                    array_merge(
                        $product ? $this->ecomRowPayload($ctx, $ecomId, $product) : [],
                        ['product_id' => $ecomId, 'skipped' => true]
                    )
                );
            }

            $ecom = app(\App\Services\Ecom\EcomInterface::class);
            $ecomProduct = $ecom->getProduct($ecomId);

            if (empty($ecomProduct)) {
                $meta = is_array($mapping->metadata)
                    ? $mapping->metadata
                    : json_decode($mapping->metadata ?? '{}', true);
                $ecomProduct = $meta;
            }

            if (empty($ecomProduct)) {
                return $this->productActionResponse(
                    $request,
                    'error',
                    "No product data for #{$ecomId}. Run Fetch first.",
                    ['product_id' => $ecomId]
                );
            }

            $wasCreate   = !$mapping->erp_id || $mapping->erp_id === '0';
            $syncService = app(ProductSyncService::class);
            $erpId       = $syncService->syncEcomProductToErp($ecomProduct);

            $action  = $wasCreate ? 'created in' : 'updated in';
            $product = $this->findEcomToErpProductRow($ecomId);

            return $this->productActionResponse(
                $request,
                'success',
                "Product #{$ecomId} {$action} " . $this->settings->erpDisplayName() . " (ID: #{$erpId}).",
                array_merge(
                    $product ? $this->ecomRowPayload($ctx, $ecomId, $product) : [],
                    ['product_id' => $ecomId, 'erp_id' => $erpId]
                )
            );
        } catch (\Throwable $e) {
            EcomToErpProductState::markFailed($ecomId, $e->getMessage());

            $message = $e->getMessage();
            if (strlen($message) > 400) {
                $message = substr($message, 0, 400) . '…';
            }

            $product = $this->findEcomToErpProductRow($ecomId);

            return $this->productActionResponse(
                $request,
                'error',
                'Push failed: ' . $message,
                array_merge(
                    $product ? $this->ecomRowPayload($ctx, $ecomId, $product) : [],
                    ['product_id' => $ecomId]
                ),
                status: 500
            );
        }
    }

    private function ecomToErpPushableQuery()
    {
        return SyncMapping::where('entity_type', 'product')
            ->whereIn('last_sync_direction', ['ecom_to_erp', 'shopify_to_erp', 'shopify_to_odoo'])
            ->where(function ($q) {
                $q->whereIn('ecom_status', [
                    EcomToErpProductState::STATUS_PENDING,
                    EcomToErpProductState::STATUS_UPDATED,
                    EcomToErpProductState::STATUS_FAILED,
                ])->orWhereNull('ecom_status');
            });
    }

    /**
     * Flatten stored Odoo wire logs into the values actually written, or pass through previews.
     */
    private function resolveEcomToErpDisplayPayload(array $logged): array
    {
        if (!empty($logged['mapped_payload']) && is_array($logged['mapped_payload']) && empty($logged['values'])) {
            $logged['values'] = $logged['mapped_payload'];
        }

        if (!empty($logged['values']) && is_array($logged['values'])) {
            return $logged;
        }

        if (empty($logged['rpc_calls'])) {
            return $logged;
        }

        foreach (array_reverse($logged['rpc_calls']) as $call) {
            $method = $call['method'] ?? '';
            $args   = $call['args'] ?? [];

            if ($method === 'create' && isset($args[0]) && is_array($args[0])) {
                return [
                    '_rpc'   => [
                        'endpoint' => $call['endpoint'] ?? null,
                        'model'    => $call['model'] ?? 'product.template',
                        'method'   => 'create',
                    ],
                    'values' => $args[0],
                ];
            }

            if ($method === 'write' && isset($args[1]) && is_array($args[1])) {
                return [
                    '_rpc'   => [
                        'endpoint' => $call['endpoint'] ?? null,
                        'model'    => $call['model'] ?? 'product.template',
                        'method'   => 'write',
                        'ids'      => $args[0] ?? [],
                    ],
                    'values' => $args[1],
                ];
            }
        }

        return $logged;
    }

    /**
     * Show Odoo many2one fields in read-style [id, "label"] on the info page.
     * Actual XML-RPC write still sends integer IDs only.
     */
    private function formatErpPayloadForDisplay(?array $payload): ?array
    {
        if (empty($payload)) {
            return $payload;
        }

        if (isset($payload['values']) && is_array($payload['values'])) {
            $payload['values'] = $this->formatOdooMany2OneFields($payload['values']);
            return $payload;
        }

        return $this->formatOdooMany2OneFields($payload);
    }

    private function formatOdooMany2OneFields(array $values): array
    {
        return app(\App\Services\Odoo\OdooFieldNormalizer::class)
            ->formatMany2OneForDisplay('product.template', $values);
    }

    private function erpRowPayload(array $ctx, int|string $erpId, ?ProductCache $cache): array
    {
        if (!$cache) {
            return ['row_id' => 'erp-' . $erpId];
        }

        return [
            'row_id'   => 'erp-' . $erpId,
            'row_html' => $this->renderProductRowHtml($cache->fresh(), $ctx),
        ];
    }

    private function ecomRowPayload(array $ctx, string $ecomId, $product): array
    {
        return [
            'row_id'   => 'ecom-' . $ecomId,
            'row_html' => $this->renderProductRowHtml($product, $ctx),
        ];
    }

    private function productActionResponse(
        Request $request,
        string $level,
        string $message,
        array $data = [],
        int $status = 422,
    ): RedirectResponse|JsonResponse {
        if ($request->expectsJson()) {
            $httpStatus = $level === 'error' ? $status : 200;

            return response()->json(array_merge([
                'level'   => $level,
                'message' => $message,
            ], $data), $httpStatus);
        }

        return redirect()->route('dashboard.products')->with($level, $message);
    }

    public function destroy(Request $request, string $id)
    {
        $removedRowId = $this->productRemovedRowId($id);

        return $this->destroySyncEntity(
            $request,
            app(\App\Services\Sync\UniversalSyncService::class),
            'product',
            $id,
            'dashboard.products',
            removedRowId: $removedRowId,
        );
    }

    public function destroyBulk(Request $request)
    {
        return $this->destroySyncEntitiesBulk(
            $request,
            app(\App\Services\Sync\UniversalSyncService::class),
            'product',
            'dashboard.products',
            fn (string $id) => $this->productRemovedRowId($id),
        );
    }

    private function productRemovedRowId(string $id): string
    {
        if (ctype_digit($id)) {
            return 'erp-' . $id;
        }

        return 'ecom-' . $id;
    }
}