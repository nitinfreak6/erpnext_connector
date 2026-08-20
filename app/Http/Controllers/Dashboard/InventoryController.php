<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\HandlesAjaxSyncResponses;
use App\Jobs\Ecom\PushInventoryToEcomJob;
use App\Jobs\Erp\FetchErpInventoryJob;
use App\Support\ErpId;
use App\Models\ProductCache;
use App\Models\SyncLog;
use App\Models\SyncMapping;
use App\Models\SyncQueueState;
use App\Services\SettingsService;
use App\Services\Sync\InventorySyncService;
use App\Services\Sync\SyncEntityState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    use HandlesAjaxSyncResponses;

    public function __construct(private readonly SettingsService $settings) {}

    public function index(Request $request)
    {
        $ctx = $this->listingContext($request);
        $variants = $this->queryInventory($ctx);

        return view('dashboard.inventory', array_merge($ctx, [
            'variants' => $variants,
        ]));
    }

    public function rows(Request $request): JsonResponse
    {
        $ctx = $this->listingContext($request);

        return response()->json([
            'html' => $this->renderInventoryRowsHtml($this->queryInventory($ctx), $ctx),
        ]);
    }

    public function fetchStock(Request $request)
    {
        $syncMode = $this->settings->inventorySyncMode();

        try {
            if ($syncMode === 'ecom_to_erp') {
                \App\Jobs\Ecom\FetchEcomInventoryJob::dispatchSync();
            } else {
                FetchErpInventoryJob::dispatchSync(autoPush: false);
            }

            $notes = SyncQueueState::forType('inventory')->fresh()->notes ?? '';

            if ($notes === 'error:fetch_products_first') {
                return $this->syncActionResponse(
                    $request,
                    'error',
                    'No products with inventory items found. Fetch products from '
                        . $this->settings->ecomDisplayName()
                        . ' first, then fetch stock again.',
                    redirectRoute: 'dashboard.inventory'
                );
            }

            if ($notes === 'error:no_tracked_inventory') {
                return $this->syncActionResponse(
                    $request,
                    'error',
                    'Products are fetched but none have tracked inventory items in '
                        . $this->settings->ecomDisplayName()
                        . '. Enable inventory tracking on product variants in Shopify, then fetch stock again.',
                    redirectRoute: 'dashboard.inventory'
                );
            }

            if ($notes === 'error:no_location_id') {
                return $this->syncActionResponse(
                    $request,
                    'error',
                    'No Shopify location configured. Add shopify_location_id in Settings or Channel Mapping → Warehouse.',
                    redirectRoute: 'dashboard.inventory'
                );
            }

            if ($notes === 'error:no_synced_products') {
                return $this->syncActionResponse(
                    $request,
                    'error',
                    'No products synced to ' . $this->settings->ecomDisplayName()
                        . ' yet. Push products first, then fetch stock.',
                    redirectRoute: 'dashboard.inventory'
                );
            }

            if ($notes === 'nothing_changed' || str_starts_with($notes, 'nothing_changed:') || str_starts_with($notes, 'fetched:0')) {
                $source = $syncMode === 'ecom_to_erp'
                    ? $this->settings->ecomDisplayName()
                    : $this->settings->erpDisplayName();
                $skippedNote = '';
                if (preg_match('/:skipped:(\d+)/', $notes, $skipMatch) && (int) ($skipMatch[1] ?? 0) > 0) {
                    $skippedNote = ' (' . $skipMatch[1] . ' unchanged skipped)';
                }

                return $this->syncActionResponse(
                    $request,
                    'info',
                    'No stock changes in ' . $source . ' since last fetch.' . $skippedNote,
                    redirectRoute: 'dashboard.inventory'
                );
            }

            if (str_starts_with($notes, 'fetched:')) {
                preg_match('/fetched:(\d+)(?::skipped:(\d+))?/', $notes, $m);
                $fetched = $m[1] ?? '?';
                $skipped = isset($m[2]) ? " ({$m[2]} unchanged skipped)" : '';
                $pushTo  = $syncMode === 'ecom_to_erp'
                    ? $this->settings->erpDisplayName()
                    : $this->settings->ecomDisplayName();

                return $this->syncActionResponse(
                    $request,
                    'success',
                    "{$fetched} stock update(s) fetched{$skipped}. Click Post to push to {$pushTo}.",
                    ['refresh_table' => true],
                    redirectRoute: 'dashboard.inventory'
                );
            }

            $source = $syncMode === 'ecom_to_erp'
                ? $this->settings->ecomDisplayName()
                : $this->settings->erpDisplayName();
            $dest = $syncMode === 'ecom_to_erp'
                ? $this->settings->erpDisplayName()
                : $this->settings->ecomDisplayName();

            return $this->syncActionResponse(
                $request,
                'success',
                "Stock fetched from {$source}. Click Post to push to {$dest}.",
                ['refresh_table' => true],
                redirectRoute: 'dashboard.inventory'
            );
        } catch (\Throwable $e) {
            return $this->syncActionResponse(
                $request,
                'error',
                'Fetch stock failed: ' . $this->syncErrorMessage($e),
                status: 500,
                redirectRoute: 'dashboard.inventory'
            );
        }
    }

    public function postStock(Request $request)
    {
        try {
            $pendingQuery = SyncMapping::where('entity_type', 'inventory')
                ->whereIn('ecom_status', SyncEntityState::PUSHABLE_STATUSES);

            if ($this->settings->inventorySyncMode() === 'ecom_to_erp') {
                $pendingQuery->whereNotNull('ecom_id');
            } else {
                $pendingQuery->whereNotNull('erp_id');
            }

            $pending = $pendingQuery->get();

            if ($pending->isEmpty()) {
                return $this->syncActionResponse(
                    $request,
                    'info',
                    'No stock updates to push. Run Fetch first.',
                    redirectRoute: 'dashboard.inventory'
                );
            }

            $pushed = 0;
            $failed = 0;

            foreach ($pending as $mapping) {
                $quant = $mapping->payload();

                if (empty($quant)) {
                    $failed++;
                    continue;
                }

                try {
                    if ($this->settings->inventorySyncMode() === 'ecom_to_erp') {
                        \App\Jobs\Ecom\PushInventoryToErpJob::dispatchSync($quant);
                    } else {
                        PushInventoryToEcomJob::dispatchSync($quant, $mapping->id);
                    }
                    $pushed++;
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error('postStock: failed: ' . $e->getMessage());
                    $failed++;
                }
            }

            if ($pushed === 0 && $failed === 0) {
                return $this->syncActionResponse(
                    $request,
                    'info',
                    'No stock updates to push.',
                    redirectRoute: 'dashboard.inventory'
                );
            }

            $dest = $this->settings->inventorySyncMode() === 'ecom_to_erp'
                ? $this->settings->erpDisplayName()
                : $this->settings->ecomDisplayName();
            $msg = "{$pushed} stock update(s) pushed to {$dest}.";
            if ($failed) {
                $msg .= " {$failed} failed — check Message column.";
            }

            return $this->syncActionResponse(
                $request,
                $failed ? 'warning' : 'success',
                $msg,
                ['refresh_table' => true],
                redirectRoute: 'dashboard.inventory'
            );
        } catch (\Throwable $e) {
            return $this->syncActionResponse(
                $request,
                'error',
                'Post stock failed: ' . $this->syncErrorMessage($e),
                status: 500,
                redirectRoute: 'dashboard.inventory'
            );
        }
    }

    public function fetchStockSingle(Request $request, string $id)
    {
        $ctx = $this->listingContext($request);

        try {
            if ($this->settings->inventorySyncMode() === 'ecom_to_erp') {
                return $this->fetchStockSingleFromEcom($request, $id, $ctx);
            }

            return $this->fetchStockSingleFromErp($request, ErpId::normalize($id), $ctx);
        } catch (\Throwable $e) {
            return $this->syncActionResponse(
                $request,
                'error',
                'Fetch stock failed: ' . $this->syncErrorMessage($e),
                status: 500,
                redirectRoute: 'dashboard.inventory'
            );
        }
    }

    public function postStockSingle(Request $request, string $id)
    {
        $ctx = $this->listingContext($request);

        try {
            if ($this->settings->inventorySyncMode() === 'ecom_to_erp') {
                return $this->postStockSingleToErp($request, $id, $ctx);
            }

            return $this->postStockSingleToEcom($request, ErpId::normalize($id), $ctx);
        } catch (\Throwable $e) {
            $mapping = $this->findInventoryMapping($id);

            return $this->syncActionResponse(
                $request,
                'error',
                'Push failed: ' . $this->syncErrorMessage($e),
                $mapping ? $this->inventoryRowPayload($ctx, $mapping) : [],
                redirectRoute: 'dashboard.inventory'
            );
        }
    }

    public function stockInfo(string $id)
    {
        return $this->renderStockInfo($id);
    }

    // ── Private helpers ───────────────────────────────────────────────────

    private function listingContext(Request $request): array
    {
        return [
            'search'          => $request->input('search', ''),
            'status'          => $request->input('status', 'all'),
            'perPage'         => (int) $request->input('per_page', 50),
            'syncMode'        => $this->settings->inventorySyncMode(),
            'erpDisplayName'  => $this->settings->erpDisplayName(),
            'ecomDisplayName' => $this->settings->ecomDisplayName(),
        ];
    }

    private function queryInventory(array $ctx)
    {
        $query = SyncMapping::where('entity_type', 'inventory')
            ->orderByDesc('last_synced_at');

        if (($ctx['status'] ?? 'all') !== 'all' && ($ctx['status'] ?? '') !== '') {
            $status = $ctx['status'];
            if (in_array($status, ['sent', 'success', 'synced'], true)) {
                $query->whereIn('ecom_status', SyncEntityState::SYNCED_ALIASES);
            } elseif ($status === 'pending') {
                $query->where(function ($q) {
                    $q->whereIn('ecom_status', [SyncEntityState::STATUS_PENDING, SyncEntityState::STATUS_FAILED])
                      ->orWhereNull('ecom_status');
                });
            } else {
                $query->where('ecom_status', $status);
            }
        }

        if (!empty($ctx['search'])) {
            $search = $ctx['search'];
            $query->where(function ($q) use ($search) {
                $q->where('erp_id', 'like', "%{$search}%")
                  ->orWhere('ecom_id', 'like', "%{$search}%");
            });
        }

        $perPage = max(10, min(100, $ctx['perPage'] ?? 50));

        return $query->paginate($perPage)->withQueryString()->through(
            fn ($mapping) => $this->enrichInventoryMapping($mapping)
        );
    }

    private function enrichInventoryMapping(SyncMapping $mapping): SyncMapping
    {
        $erpCol = ProductCache::erpIdColumn();
        $cache  = null;

        if ($mapping->erp_id) {
            $cache = ProductCache::where($erpCol, $mapping->erp_id)
                ->orWhere('odoo_id', $mapping->erp_id)
                ->first();
        }

        $meta = $mapping->payload() ?? [];

        if (!$cache && !empty($meta['template_erp_id'])) {
            $cache = ProductCache::where($erpCol, (string) $meta['template_erp_id'])
                ->orWhere('odoo_id', (string) $meta['template_erp_id'])
                ->first();
        }

        if (!$cache) {
            $productEcomId = $meta['product_ecom_id'] ?? null;

            if ($productEcomId) {
                $productMapping = SyncMapping::where('entity_type', 'product')
                    ->where('ecom_id', (string) $productEcomId)
                    ->first();

                if ($productMapping?->erp_id) {
                    $cache = ProductCache::where($erpCol, $productMapping->erp_id)
                        ->orWhere('odoo_id', $productMapping->erp_id)
                        ->first();
                }

                if (!$cache && $productMapping?->hasPayload()) {
                    $pmMeta = $productMapping->payload() ?? [];
                    $mapping->product_name = $pmMeta['title'] ?? $pmMeta['name'] ?? 'Product #' . $productEcomId;
                    $mapping->sku          = $meta['sku'] ?? ($pmMeta['variants'][0]['sku'] ?? '—');
                }
            }
        }

        if ($cache) {
            $mapping->product_name = $cache->name ?? '—';
            $mapping->sku          = $cache->default_code ?? '—';
        } elseif (!isset($mapping->product_name)) {
            $displayId = $meta['template_erp_id'] ?? $mapping->erp_id ?? $mapping->ecom_id;
            $mapping->product_name = 'Product #' . $displayId;
            $mapping->sku          = '—';
        }

        $meta = $mapping->payload() ?? [];
        $inventorySyncService = app(InventorySyncService::class);
        $mapping->erp_qty = $this->settings->inventorySyncMode() === 'ecom_to_erp'
            ? $inventorySyncService->qtyFromStoredPayload($meta)
            : SyncEntityState::displayInventoryQty($meta, $this->settings->inventorySyncMode());
        $mapping->shopify_location_id = $this->settings->inventorySyncMode() === 'ecom_to_erp'
            ? $inventorySyncService->shopifyLocationFromStoredPayload($meta)
            : ($meta['shopify_location_id'] ?? null);
        $mapping->erp_location_id     = is_array($meta['location_id'] ?? null)
            ? ($meta['location_id'][0] ?? null)
            : ($meta['erp_location_id'] ?? $meta['location_id'] ?? null);

        $mapping->display_status  = SyncEntityState::displayStatus($mapping);
        $mapping->display_message = $mapping->display_status === SyncEntityState::STATUS_SENT
            ? null
            : $mapping->sync_message;

        return $mapping;
    }

    private function renderInventoryRowsHtml($variants, array $ctx): string
    {
        return view('dashboard.partials.inventory-table-rows', array_merge($ctx, [
            'variants' => $variants,
        ]))->render();
    }

    private function renderInventoryRowHtml(SyncMapping $mapping, array $ctx): string
    {
        $rowId = 'erp-' . ($mapping->erp_id ?? $mapping->ecom_id ?? 'unknown');

        if ($ctx['syncMode'] === 'ecom_to_erp') {
            $rowId = 'ecom-' . ($mapping->ecom_id ?? $mapping->erp_id ?? 'unknown');
        }

        return view('dashboard.partials.inventory-table-row', array_merge($ctx, [
            'mapping'  => $mapping,
            'rowIndex' => abs(crc32($rowId)),
        ]))->render();
    }

    private function inventoryRowPayload(array $ctx, SyncMapping $mapping): array
    {
        $mapping = $this->enrichInventoryMapping($mapping->fresh());

        $rowId = $ctx['syncMode'] === 'ecom_to_erp'
            ? 'ecom-' . ($mapping->ecom_id ?? 'unknown')
            : 'erp-' . ($mapping->erp_id ?? 'unknown');

        return [
            'row_id'   => $rowId,
            'row_html' => $this->renderInventoryRowHtml($mapping, $ctx),
        ];
    }

    private function findInventoryMapping(string $id): ?SyncMapping
    {
        return SyncMapping::where('entity_type', 'inventory')
            ->where(function ($q) use ($id) {
                $q->where('erp_id', $id)
                  ->orWhere('ecom_id', $id);
            })
            ->first();
    }

    private function fetchStockSingleFromErp(Request $request, int|string $erpId, array $ctx)
    {
        $inventorySync = app(InventorySyncService::class);
        $erp           = app(\App\Services\Erp\ErpInterface::class);
        $quants        = $inventorySync->collectQuantsForSyncedErpProducts((string) $erpId);
        $fetchWire     = method_exists($erp, 'takeWireLog') ? $erp->takeWireLog() : [];

        if ($quants === []) {
            $allQuants = $erp->getInventoryModifiedSince('2000-01-01 00:00:00');
            $fetchWire = method_exists($erp, 'takeWireLog') ? $erp->takeWireLog() : $fetchWire;
            $quant     = $inventorySync->resolveQuantForErpProduct($allQuants, $erpId);
        } else {
            $quant = $inventorySync->resolveQuantForErpProduct($quants, $erpId);

            if (!$quant) {
                $quant = $quants[0];
            }
        }

        if (!$quant) {
            return $this->syncActionResponse(
                $request,
                'error',
                "No stock data found for product #{$erpId} in " . $this->settings->erpDisplayName() . '. '
                    . 'Ensure the product is pushed to ' . $this->settings->ecomDisplayName() . ' first.',
                redirectRoute: 'dashboard.inventory'
            );
        }

        $variantErpId = (string) ($quant['product_id'][0] ?? $quant['product_id'] ?? $erpId);

        $existing = SyncMapping::where('entity_type', 'inventory')
            ->where('erp_id', $variantErpId)
            ->where('erp_driver', $this->settings->erpDriver())
            ->first();

        $changed = SyncEntityState::markFetched(
            'inventory',
            ['erp_id' => $variantErpId, 'erp_driver' => $this->settings->erpDriver()],
            array_merge($quant, ['_fetch_wire' => $fetchWire]),
            $existing,
            'erp_to_ecom'
        );

        $mapping = $this->findInventoryMapping($variantErpId);

        if (! $changed) {
            return $this->syncActionResponse(
                $request,
                'info',
                "Product #{$erpId} stock unchanged — skipped.",
                $mapping ? $this->inventoryRowPayload($ctx, $mapping) : [],
                redirectRoute: 'dashboard.inventory'
            );
        }

        return $this->syncActionResponse(
            $request,
            'success',
            "Stock fetched for product #{$erpId}. Click Post to push to " . $this->settings->ecomDisplayName() . '.',
            $mapping ? $this->inventoryRowPayload($ctx, $mapping) : ['refresh_table' => true],
            redirectRoute: 'dashboard.inventory'
        );
    }

    private function fetchStockSingleFromEcom(Request $request, string $id, array $ctx)
    {
        $productMapping = SyncMapping::where('entity_type', 'product')
            ->where(function ($q) use ($id) {
                $q->where('erp_id', $id)
                  ->orWhere('ecom_id', $id);
            })
            ->whereIn('last_sync_direction', ['ecom_to_erp', 'shopify_to_erp', 'shopify_to_odoo'])
            ->first();

        if (!$productMapping || !$productMapping->ecom_id) {
            return $this->syncActionResponse(
                $request,
                'error',
                "No {$this->settings->ecomDisplayName()} product mapped for #{$id}. Fetch the product first.",
                redirectRoute: 'dashboard.inventory'
            );
        }

        $ecom    = app(\App\Services\Ecom\EcomInterface::class);
        $product = $ecom->getProduct($productMapping->ecom_id);

        if (empty($product)) {
            return $this->syncActionResponse(
                $request,
                'error',
                "Product #{$productMapping->ecom_id} not found in " . $this->settings->ecomDisplayName() . '.',
                redirectRoute: 'dashboard.inventory'
            );
        }

        $inventoryItemIds = collect($product['variants'] ?? [])
            ->map(fn (array $v) => \App\Services\Sync\InventoryItemCatalog::variantInventoryItemId($v))
            ->filter()
            ->values()
            ->toArray();

        if (empty($inventoryItemIds)) {
            return $this->syncActionResponse(
                $request,
                'error',
                "No tracked inventory items for product #{$productMapping->ecom_id}.",
                redirectRoute: 'dashboard.inventory'
            );
        }

        $inventorySync = app(InventorySyncService::class);
        $locationId = $inventorySync->resolveShopifyLocationForInventory();

        if (!$locationId) {
            return $this->syncActionResponse(
                $request,
                'error',
                'No Shopify location configured. Add a Warehouse mapping or shopify_location_id in Settings.',
                redirectRoute: 'dashboard.inventory'
            );
        }

        $levels = $ecom->getInventoryLevels($inventoryItemIds, $locationId);
        $fetchWire = method_exists($ecom, 'takeWireLog') ? $ecom->takeWireLog() : [];

        if (empty($levels)) {
            return $this->syncActionResponse(
                $request,
                'info',
                "No inventory levels returned for product #{$productMapping->ecom_id}.",
                redirectRoute: 'dashboard.inventory'
            );
        }

        $variantSkus = collect($product['variants'] ?? [])
            ->mapWithKeys(function (array $v) {
                $itemId = \App\Services\Sync\InventoryItemCatalog::variantInventoryItemId($v);
                return $itemId ? [$itemId => $v['sku'] ?? null] : [];
            })
            ->all();

        $stored      = 0;
        $lastMapping = null;

        $inventorySync = app(InventorySyncService::class);

        foreach ($levels as $level) {
            $inventoryItemId = $inventorySync->resolveSyncEntityEcomId($level);
            if (!$inventoryItemId) {
                continue;
            }

            $existing = SyncMapping::where('entity_type', 'inventory')
                ->where('ecom_id', $inventoryItemId)
                ->where('ecom_driver', $this->settings->ecomDriver())
                ->first();

            $payload = $inventorySync->buildMappingSourcePayload($level, $locationId, [
                'product_ecom_id' => $productMapping->ecom_id,
                'sku'             => $level['sku'] ?? $variantSkus[$inventoryItemId] ?? null,
            ]);

            if ($fetchWire !== []) {
                $payload['_fetch_wire'] = $fetchWire;
            }

            if (SyncEntityState::markFetched(
                'inventory',
                ['ecom_id' => $inventoryItemId, 'ecom_driver' => $this->settings->ecomDriver()],
                $payload,
                $existing,
                'ecom_to_erp'
            )) {
                $stored++;
                $lastMapping = $this->findInventoryMapping($inventoryItemId);
            }
        }

        if ($stored === 0) {
            $existing = $this->findInventoryMapping($id);

            return $this->syncActionResponse(
                $request,
                'info',
                "Stock unchanged for product #{$productMapping->ecom_id} — skipped.",
                $existing ? $this->inventoryRowPayload($ctx, $existing) : [],
                redirectRoute: 'dashboard.inventory'
            );
        }

        return $this->syncActionResponse(
            $request,
            'success',
            "{$stored} inventory item(s) fetched. Click Post to push to " . $this->settings->erpDisplayName() . '.',
            $lastMapping ? $this->inventoryRowPayload($ctx, $lastMapping) : ['refresh_table' => true],
            redirectRoute: 'dashboard.inventory'
        );
    }

    private function postStockSingleToEcom(Request $request, int|string $erpId, array $ctx)
    {
        $mapping = SyncMapping::where('entity_type', 'inventory')
            ->where('erp_id', (string) $erpId)
            ->whereNotNull('erp_id')
            ->first();

        if (!$mapping) {
            return $this->syncActionResponse(
                $request,
                'error',
                "No stock for #{$erpId}. Run Fetch first.",
                redirectRoute: 'dashboard.inventory'
            );
        }

        if (!SyncEntityState::needsPush($mapping)) {
            return $this->syncActionResponse(
                $request,
                'info',
                "Product #{$erpId} stock is already synced and unchanged.",
                $this->inventoryRowPayload($ctx, $mapping),
                redirectRoute: 'dashboard.inventory'
            );
        }

        $meta      = $mapping->payload() ?? [];
        $qty       = $meta['quantity'] ?? $meta['qty'] ?? null;
        $writeDate = $meta['write_date'] ?? null;

        $erp     = app(\App\Services\Erp\ErpInterface::class);
        $quants  = $erp->getInventoryModifiedSince('2000-01-01 00:00:00');
        $inventorySync = app(InventorySyncService::class);
        $current = $inventorySync->resolveQuantForErpProduct($quants, $erpId);

        if ($current) {
            $currentWrite = $current['write_date'] ?? null;
            $currentQty   = $current['quantity'] ?? $current['qty'] ?? null;

            if ($mapping->ecom_status === 'synced' && $qty !== null && $qty == $currentQty && $writeDate === $currentWrite) {
                return $this->syncActionResponse(
                    $request,
                    'info',
                    "Product #{$erpId} stock unchanged — skipped.",
                    $this->inventoryRowPayload($ctx, $mapping),
                    redirectRoute: 'dashboard.inventory'
                );
            }

            $mapping->update(['metadata' => null, 'ecom_status' => SyncEntityState::STATUS_PENDING]);
            \App\Services\Sync\SyncPayloadStore::put('inventory', 'erp', (string) $erpId, $current);
            $quant = $current;
        } else {
            $quant = $meta;
        }

        try {
            app(InventorySyncService::class)->syncInventoryToEcom($quant, $mapping);
        } catch (\Throwable $e) {
            $mapping = $this->findInventoryMapping((string) $erpId);

            return $this->syncActionResponse(
                $request,
                'error',
                'Push failed: ' . $this->syncErrorMessage($e),
                $mapping ? $this->inventoryRowPayload($ctx, $mapping) : [],
                redirectRoute: 'dashboard.inventory'
            );
        }

        $mapping = $this->findInventoryMapping((string) $erpId);

        return $this->syncActionResponse(
            $request,
            'success',
            "Stock pushed for product #{$erpId} to " . $this->settings->ecomDisplayName() . '.',
            $mapping ? $this->inventoryRowPayload($ctx, $mapping) : ['refresh_table' => true],
            redirectRoute: 'dashboard.inventory'
        );
    }

    private function postStockSingleToErp(Request $request, string $id, array $ctx)
    {
        $mapping = SyncMapping::where('entity_type', 'inventory')
            ->where(function ($q) use ($id) {
                $q->where('erp_id', $id)
                  ->orWhere('ecom_id', $id);
            })
            ->where('last_sync_direction', 'ecom_to_erp')
            ->whereNotNull('ecom_id')
            ->first();

        if (!$mapping) {
            return $this->syncActionResponse(
                $request,
                'error',
                "No stock data for #{$id}. Run Fetch first.",
                redirectRoute: 'dashboard.inventory'
            );
        }

        if (!SyncEntityState::needsPush($mapping)) {
            return $this->syncActionResponse(
                $request,
                'info',
                "Stock for #{$id} is already synced and unchanged.",
                $this->inventoryRowPayload($ctx, $mapping),
                redirectRoute: 'dashboard.inventory'
            );
        }

        $quant = $mapping->payload();

        try {
            app(InventorySyncService::class)->syncInventoryToErp($quant, $mapping);
        } catch (\Throwable $e) {
            $mapping = $this->findInventoryMapping($id);

            return $this->syncActionResponse(
                $request,
                'error',
                'Push failed: ' . $this->syncErrorMessage($e),
                $mapping ? $this->inventoryRowPayload($ctx, $mapping) : [],
                redirectRoute: 'dashboard.inventory'
            );
        }

        $mapping = $this->findInventoryMapping($id);

        return $this->syncActionResponse(
            $request,
            'success',
            "Stock pushed for #{$id} to " . $this->settings->erpDisplayName() . '.',
            $mapping ? $this->inventoryRowPayload($ctx, $mapping) : ['refresh_table' => true],
            redirectRoute: 'dashboard.inventory'
        );
    }

    private function renderStockInfo(string $id)
    {
        $syncMode = $this->settings->inventorySyncMode();
        $isEcomToErp = $syncMode === 'ecom_to_erp';

        $mapping = $this->findInventoryMapping($id);

        $logIds = array_filter(array_unique([$id, $mapping?->ecom_id, $mapping?->erp_id]));

        $pushDirections = $isEcomToErp
            ? ['ecom_to_erp', 'shopify_to_erp', 'shopify_to_odoo']
            : ['erp_to_ecom', 'odoo_to_shopify', 'erp_to_shopify'];

        $syncLog = SyncLog::where('entity_type', 'inventory')
            ->whereIn('entity_id', $logIds)
            ->whereIn('direction', $pushDirections)
            ->whereIn('status', ['success', 'failed'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        $cache = null;
        $erpIdColumn = ProductCache::erpIdColumn();

        if ($mapping?->erp_id) {
            $cache = ProductCache::where($erpIdColumn, $mapping->erp_id)
                ->orWhere('odoo_id', $mapping->erp_id)
                ->first();
        }

        if (!$cache && $mapping) {
            $meta          = $mapping->payload() ?? [];
            $productEcomId = $meta['product_ecom_id'] ?? null;

            if ($productEcomId) {
                $productMapping = SyncMapping::where('entity_type', 'product')
                    ->where('ecom_id', (string) $productEcomId)
                    ->first();

                if ($productMapping?->erp_id) {
                    $cache = ProductCache::where($erpIdColumn, $productMapping->erp_id)
                        ->orWhere('odoo_id', $productMapping->erp_id)
                        ->first();
                }

                if (!$cache && $productMapping?->hasPayload()) {
                    $pmMeta = $productMapping->payload() ?? [];
                    $cache = (object) [
                        'name'         => $pmMeta['title'] ?? $pmMeta['name'] ?? null,
                        'default_code' => $meta['sku'] ?? null,
                    ];
                }
            }
        }

        $meta       = $mapping ? ($mapping->payload() ?? []) : [];
        $inventorySync = app(InventorySyncService::class);
        $sourceData = $meta;
        $displayQty = $isEcomToErp
            ? $inventorySync->qtyFromStoredPayload($meta)
            : SyncEntityState::displayInventoryQty($meta, $syncMode);

        $targetPayload  = $this->resolveInventoryDisplayPayload(
            $syncLog?->request_payload,
            $syncLog?->response_payload,
            $isEcomToErp
        );
        $targetPayloadIsMapped = is_array($targetPayload)
            && !(array_is_list($targetPayload) && (
                isset($targetPayload[0]['model'])
                || isset($targetPayload[0]['query'])
                || isset($targetPayload[0]['action'])
            ));
        $targetResponse = $this->resolveInventoryDisplayResponse($syncLog?->response_payload, $isEcomToErp);

        $shopifyStore = $this->settings->shopifyShop();
        $apiVersion   = $this->settings->shopifyVersion() ?: '2024-01';
        $graphqlUrl   = $shopifyStore
            ? "https://{$shopifyStore}.myshopify.com/admin/api/{$apiVersion}/graphql.json"
            : null;
        $erpHost = rtrim(config('odoo.url', config('erp.url', '')), '/');

        $erpDisplayName  = $this->settings->erpDisplayName();
        $ecomDisplayName = $this->settings->ecomDisplayName();
        $erpId           = $mapping?->erp_id ?? $id;
        $displayId       = $isEcomToErp
            ? ($mapping?->ecom_id ?? $id)
            : ($mapping?->erp_id ?? $id);

        return view('dashboard.inventory-info', compact(
            'mapping', 'cache', 'id', 'syncLog', 'syncMode',
            'sourceData', 'displayQty', 'targetPayload', 'targetPayloadIsMapped', 'targetResponse',
            'erpDisplayName', 'ecomDisplayName', 'erpId', 'displayId', 'isEcomToErp',
            'shopifyStore', 'apiVersion', 'graphqlUrl', 'erpHost'
        ));
    }

    private function resolveInventoryLogPayload(?string $payload): mixed
    {
        if (!$payload) {
            return null;
        }

        $decoded = json_decode($payload, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $payload;
    }

    private function resolveInventoryMappedPayload(?string $requestPayload): mixed
    {
        $decoded = $this->resolveInventoryLogPayload($requestPayload);
        if (!is_array($decoded)) {
            return $decoded;
        }

        if (isset($decoded['mapped_payload']) && is_array($decoded['mapped_payload'])) {
            return $decoded['mapped_payload'];
        }

        if ($this->looksLikeInventoryWirePayload($decoded)) {
            return null;
        }

        return $decoded;
    }

    private function looksLikeInventoryWirePayload(array $payload): bool
    {
        if ($payload === [] || !array_is_list($payload)) {
            return false;
        }

        $first = $payload[0] ?? null;

        return is_array($first)
            && (isset($first['query']) || isset($first['model']) || isset($first['action']));
    }

    /** Real API wire sent on post (Odoo XML-RPC / Shopify GraphQL) — not field-config mapped values. */
    private function resolveInventoryDisplayPayload(
        ?string $requestPayload,
        ?string $responsePayload,
        bool $isEcomToErp
    ): mixed {
        $apiCalls = $this->syncLogApiCalls($requestPayload, $responsePayload);

        if (is_array($apiCalls) && $apiCalls !== []) {
            $wire = collect($apiCalls)
                ->map(fn ($call) => array_filter([
                    'endpoint'  => $call['endpoint'] ?? null,
                    'model'     => $call['model'] ?? null,
                    'method'    => $call['method'] ?? null,
                    'args'      => $call['args'] ?? null,
                    'kwargs'    => $call['kwargs'] ?? null,
                    'action'    => $call['action'] ?? null,
                    'query'     => $call['query'] ?? null,
                    'variables' => $call['variables'] ?? null,
                ], fn ($v) => $v !== null && $v !== []))
                ->filter(fn ($call) => $call !== [])
                ->values()
                ->all();

            if ($wire !== []) {
                return $wire;
            }
        }

        $decoded = $this->resolveInventoryLogPayload($requestPayload);
        if (is_array($decoded)) {
            if (!empty($decoded['wire_input']) && is_array($decoded['wire_input'])) {
                return $decoded['wire_input'];
            }

            if (!empty($decoded['api_calls']) && is_array($decoded['api_calls'])) {
                return $decoded['api_calls'];
            }
        }

        $mapped = $this->resolveInventoryMappedPayload($requestPayload);

        return is_array($mapped) && $mapped !== [] ? $mapped : null;
    }

    private function resolveInventoryDisplayResponse(?string $responsePayload, bool $isEcomToErp): mixed
    {
        $decoded = $this->resolveInventoryLogPayload($responsePayload);
        if (!is_array($decoded)) {
            return $decoded;
        }

        if (!empty($decoded['api_calls']) && is_array($decoded['api_calls'])) {
            return collect($decoded['api_calls'])
                ->map(fn ($call) => array_filter([
                    'action'   => $call['action'] ?? null,
                    'model'    => $call['model'] ?? null,
                    'method'   => $call['method'] ?? null,
                    'response' => $call['response'] ?? $call['result'] ?? null,
                ], fn ($v) => $v !== null))
                ->filter(fn ($row) => ($row['response'] ?? null) !== null || ($row['model'] ?? null) !== null)
                ->values()
                ->all() ?: $decoded['api_calls'];
        }

        if (!empty($decoded['mutations']) && is_array($decoded['mutations'])) {
            return $decoded['mutations'];
        }

        return collect($decoded)->except('api_calls', 'mutations')->filter()->all() ?: null;
    }

    private function syncLogApiCalls(?string $requestPayload, ?string $responsePayload): mixed
    {
        $req = $this->resolveInventoryLogPayload($requestPayload);
        $res = $this->resolveInventoryLogPayload($responsePayload);

        $calls = [];

        if (is_array($req)) {
            if (!empty($req['api_calls']) && is_array($req['api_calls'])) {
                $calls = array_merge($calls, $req['api_calls']);
            }
            if (!empty($req['rpc_calls']) && is_array($req['rpc_calls'])) {
                $calls = array_merge($calls, $req['rpc_calls']);
            }
        }

        if (is_array($res) && !empty($res['api_calls']) && $calls === []) {
            $calls = $res['api_calls'];
        }

        return $calls !== [] ? $calls : null;
    }

    /** @param array<int, array<string, mixed>> $apiCalls */
    private function realOutgoingFromApiCalls(array $apiCalls, bool $isEcomToErp): ?array
    {
        if ($isEcomToErp) {
            return $this->realOutgoingFromOdooRpcCalls($apiCalls);
        }

        return $this->realOutgoingFromShopifyCalls($apiCalls);
    }

    /** @param array<int, array<string, mixed>> $apiCalls */
    private function realOutgoingFromShopifyCalls(array $apiCalls): ?array
    {
        foreach (array_reverse($apiCalls) as $call) {
            $action = (string) ($call['action'] ?? '');
            if ($action === 'inventorySetQuantities' || str_contains($action, 'inventorySetQuantities')) {
                return array_filter([
                    'endpoint'  => $call['endpoint'] ?? 'graphql.json',
                    'mutation'  => 'inventorySetQuantities',
                    'variables' => $call['variables'] ?? null,
                ]);
            }
        }

        foreach (array_reverse($apiCalls) as $call) {
            if (!empty($call['variables']) && !empty($call['query'])) {
                return array_filter([
                    'endpoint'  => $call['endpoint'] ?? 'graphql.json',
                    'action'    => $call['action'] ?? null,
                    'variables' => $call['variables'],
                ]);
            }
        }

        return null;
    }

    /** @param array<int, array<string, mixed>> $apiCalls */
    private function realOutgoingFromOdooRpcCalls(array $apiCalls): ?array
    {
        foreach (array_reverse($apiCalls) as $call) {
            if (($call['model'] ?? '') !== 'stock.quant') {
                continue;
            }

            $method = $call['method'] ?? '';
            $args   = $call['args'] ?? [];

            if ($method === 'write' && isset($args[1]) && is_array($args[1])) {
                return [
                    '_rpc' => [
                        'endpoint' => $call['endpoint'] ?? null,
                        'model'    => 'stock.quant',
                        'method'   => 'write',
                        'ids'      => $args[0] ?? [],
                    ],
                    'values' => $args[1],
                ];
            }

            if ($method === 'create' && isset($args[0]) && is_array($args[0])) {
                return [
                    '_rpc' => [
                        'endpoint' => $call['endpoint'] ?? null,
                        'model'    => 'stock.quant',
                        'method'   => 'create',
                    ],
                    'values' => $args[0],
                ];
            }
        }

        return null;
    }

    public function destroy(Request $request, string $id)
    {
        return $this->destroySyncEntity(
            $request,
            app(\App\Services\Sync\UniversalSyncService::class),
            'inventory',
            $id,
            'dashboard.inventory',
            removedRowId: $this->inventoryRemovedRowId($id),
        );
    }

    public function destroyBulk(Request $request)
    {
        return $this->destroySyncEntitiesBulk(
            $request,
            app(\App\Services\Sync\UniversalSyncService::class),
            'inventory',
            'dashboard.inventory',
            fn (string $id) => $this->inventoryRemovedRowId($id),
        );
    }

    private function inventoryRemovedRowId(string $id): string
    {
        return (ctype_digit($id) ? 'erp-' : 'ecom-') . $id;
    }
}
