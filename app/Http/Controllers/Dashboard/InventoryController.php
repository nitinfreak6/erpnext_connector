<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\ProductCache;
use App\Services\SettingsService;
use App\Jobs\Erp\FetchErpInventoryJob;
use App\Jobs\Ecom\PushInventoryToEcomJob;
use App\Models\SyncLog;
use App\Models\SyncMapping;
use App\Models\SyncQueueState;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function __construct(private readonly SettingsService $settings) {}

    public function index(Request $request)
	{
		$search  = $request->input('search');
		$channel = $request->input('channel', 'all');
		$status  = $request->input('status');

		$entityTypes = match($channel) {
			'shopify' => ['inventory'],
			'amazon'  => ['amazon_inventory'],
			default   => ['inventory', 'amazon_inventory'],
		};

		// ── PRIMARY SOURCE: SyncMapping (written by Fetch Stock) ──────────────
		$logsQuery = SyncMapping::where('entity_type', 'inventory')
			->orderByDesc('last_synced_at');

		if ($status) {
			$logsQuery->where('ecom_status', $status);
		}

		if ($search) {
			$logsQuery->where(function ($q) use ($search) {
				$q->where('erp_id', 'like', "%{$search}%")
				  ->orWhere('ecom_id', 'like', "%{$search}%");
			});
		}

		$logs = $logsQuery->paginate(50)->withQueryString();

		// ── ENRICH: product name from cache + latest push log per row ─────────
		$logs->getCollection()->transform(function ($mapping) {
			$erpCol = ProductCache::erpIdColumn();

			// Try ProductCache by erp_id first (erp_to_ecom mode).
			// In ecom_to_erp mode erp_id is null — resolve via product_ecom_id stored in metadata.
			$cache = null;
			if ($mapping->erp_id) {
				$cache = ProductCache::where($erpCol, $mapping->erp_id)
					->orWhere('odoo_id', $mapping->erp_id)
					->first();
			}

			if (!$cache) {
				$meta           = is_array($mapping->metadata) ? $mapping->metadata : json_decode($mapping->metadata ?? '{}', true);
				$productEcomId  = $meta['product_ecom_id'] ?? null;

				if ($productEcomId) {
					// Look up the product SyncMapping to get erp_id, then hit ProductCache
					$productMapping = SyncMapping::where('entity_type', 'product')
						->where('ecom_id', (string) $productEcomId)
						->first();

					if ($productMapping?->erp_id) {
						$cache = ProductCache::where($erpCol, $productMapping->erp_id)
							->orWhere('odoo_id', $productMapping->erp_id)
							->first();
					}

					// Also try name/sku from the product metadata directly
					if (!$cache && $productMapping?->metadata) {
						$pmMeta = is_array($productMapping->metadata)
							? $productMapping->metadata
							: json_decode($productMapping->metadata ?? '{}', true);
						$mapping->product_name = $pmMeta['title'] ?? $pmMeta['name'] ?? 'Product #' . $productEcomId;
						$mapping->sku          = $meta['sku'] ?? ($pmMeta['variants'][0]['sku'] ?? '—');
					}
				}
			}

			if ($cache) {
				$mapping->product_name = $cache->name ?? '—';
				$mapping->sku          = $cache->default_code ?? '—';
			} elseif (!isset($mapping->product_name)) {
				$mapping->product_name = 'Product #' . ($mapping->erp_id ?? $mapping->ecom_id);
				$mapping->sku          = '—';
			}

			// Pull qty/location from stored metadata
			$meta = is_array($mapping->metadata) ? $mapping->metadata : json_decode($mapping->metadata ?? '{}', true);
			$mapping->erp_qty             = $meta['available'] ?? $meta['quantity'] ?? $meta['qty'] ?? null;
			$mapping->shopify_location_id = $meta['shopify_location_id'] ?? null;
			$mapping->erp_location_id     = $meta['location_id'][0] ?? $meta['erp_location_id'] ?? null;

			// Latest push log — entity_id is inventory_item_id (ecom_id) in ecom_to_erp mode
			$logEntityId = $mapping->erp_id ?? $mapping->ecom_id;
			$mapping->latest_log = SyncLog::where('entity_type', 'inventory')
				->where('entity_id', (string) $logEntityId)
				->latest()
				->first();

			return $mapping;
		});

		// Recent push logs for bottom section (unchanged — still from SyncLog)
		$recentLogs = SyncLog::whereIn('entity_type', ['inventory', 'amazon_inventory'])
			->orderByDesc('created_at')
			->limit(10)
			->get();

		$syncState = [
			'inventory'        => SyncQueueState::where('sync_type', 'inventory')->first()
				?? (object)['is_running' => false, 'last_poll_at' => null, 'last_erp_write_date' => null],
			'amazon_inventory' => SyncQueueState::where('sync_type', 'amazon_inventory')->first()
				?? (object)['is_running' => false, 'last_poll_at' => null, 'last_erp_write_date' => null],
		];

		$stats = [
			'synced_today' => SyncLog::whereIn('entity_type', $entityTypes)
				->where('status', 'success')
				->whereDate('created_at', today())->count(),
			'failed_today' => SyncLog::whereIn('entity_type', $entityTypes)
				->where('status', 'failed')
				->whereDate('created_at', today())->count(),
			'total_synced' => SyncLog::where('entity_type', 'inventory')
				->where('status', 'success')->count(),
			'total_skus'   => ProductCache::count(),
		];

		$variants = $logs;  // blade uses $variants as the paginator

		$erpDisplayName  = $this->settings->erpDisplayName();
		$ecomDisplayName = $this->settings->ecomDisplayName();
		$syncMode        = $this->settings->inventorySyncMode();

		return view('dashboard.inventory', compact(
			'variants', 'logs', 'search', 'channel',
			'recentLogs', 'syncState', 'stats',
			'erpDisplayName', 'ecomDisplayName', 'syncMode'
		));
	}


    // ── Fetch Stock (all): pull from correct source based on mode ─────────
    public function fetchStock(Request $request)
    {
        $syncMode = $this->settings->inventorySyncMode();

        try {
            if ($syncMode === 'ecom_to_erp') {
                // Fetch from Shopify — pull inventory levels and store as pending
                \App\Jobs\Ecom\FetchEcomInventoryJob::dispatchSync();
            } else {
                // Fetch from Odoo — autoPush:false stores as pending only
                FetchErpInventoryJob::dispatchSync(autoPush: false);
            }

            $notes = \App\Models\SyncQueueState::forType('inventory')->fresh()->notes ?? '';

            if ($notes === 'nothing_changed' || str_starts_with($notes, 'fetched:0')) {
                $source = $syncMode === 'ecom_to_erp' ? $this->settings->ecomDisplayName() : $this->settings->erpDisplayName();
                return redirect()->route('dashboard.inventory')
                    ->with('info', 'No stock changes in ' . $source . ' since last fetch.');
            }

            if (str_starts_with($notes, 'fetched:')) {
                preg_match('/fetched:(\d+)(?::skipped:(\d+))?/', $notes, $m);
                $fetched  = $m[1] ?? '?';
                $skipped  = isset($m[2]) ? " ({$m[2]} unchanged skipped)" : '';
                $pushTo   = $syncMode === 'ecom_to_erp' ? $this->settings->erpDisplayName() : $this->settings->ecomDisplayName();
                return redirect()->route('dashboard.inventory')
                    ->with('success', "{$fetched} stock update(s) fetched{$skipped}. Click Post Stock to push to {$pushTo}.");
            }

            $source = $syncMode === 'ecom_to_erp' ? $this->settings->ecomDisplayName() : $this->settings->erpDisplayName();
            $dest   = $syncMode === 'ecom_to_erp' ? $this->settings->erpDisplayName() : $this->settings->ecomDisplayName();
            return redirect()->route('dashboard.inventory')
                ->with('success', "Stock fetched from {$source}. Click Post Stock to push to {$dest}.");

        } catch (\Throwable $e) {
            return redirect()->route('dashboard.inventory')
                ->with('error', 'Fetch stock failed: ' . $e->getMessage());
        }
    }

    // ── Post Stock (all): push pending inventory to correct destination ──
    public function postStock(Request $request)
    {
        try {
            $pending = SyncMapping::where('entity_type', 'inventory')
                ->where('ecom_status', 'pending')
                ->whereNotNull('metadata')
                ->get();

            if ($pending->isEmpty()) {
                return redirect()->route('dashboard.inventory')
                    ->with('info', 'No pending stock updates. Run Fetch Stock first.');
            }

            $pushed = 0; $skipped = 0; $failed = 0;

            foreach ($pending as $mapping) {
                $quant = is_array($mapping->metadata)
                    ? $mapping->metadata
                    : json_decode($mapping->metadata, true);

                if (empty($quant)) { $failed++; continue; }

                try {
                    $invSyncMode = $this->settings->inventorySyncMode();
                    if ($invSyncMode === 'ecom_to_erp') {
                        \App\Jobs\Ecom\PushInventoryToErpJob::dispatchSync($quant);
                    } else {
                        PushInventoryToEcomJob::dispatchSync($quant);
                    }
                    $mapping->update(['ecom_status' => 'posted', 'last_synced_at' => now()]);
                    $pushed++;
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error("postStock: failed for erp#{$mapping->erp_id}: " . $e->getMessage());
                    $failed++;
                }
            }

            if ($pushed === 0 && $failed === 0) {
                return redirect()->route('dashboard.inventory')
                    ->with('info', 'No stock updates to push.');
            }

            $dest = $this->settings->inventorySyncMode() === 'ecom_to_erp'
                ? $this->settings->erpDisplayName()
                : $this->settings->ecomDisplayName();
            $msg = "{$pushed} stock update(s) pushed to {$dest}.";
            if ($failed)  $msg .= " {$failed} failed — check logs.";

            return redirect()->route('dashboard.inventory')
                ->with($failed ? 'warning' : 'success', $msg);

        } catch (\Throwable $e) {
            return redirect()->route('dashboard.inventory')
                ->with('error', 'Post stock failed: ' . $e->getMessage());
        }
    }

    // ── Fetch Stock (single SKU) — direction-aware ────────────────────────
    public function fetchStockSingle(Request $request, string $id)
    {
        $syncMode = $this->settings->inventorySyncMode();

        try {
            if ($syncMode === 'ecom_to_erp') {
                return $this->fetchStockSingleFromEcom($id);
            }
            return $this->fetchStockSingleFromErp((int) $id);
        } catch (\Throwable $e) {
            return back()->with('error', 'Fetch stock failed: ' . $e->getMessage());
        }
    }

    private function fetchStockSingleFromErp(int $erpId)
    {
        $erp    = app(\App\Services\Erp\ErpInterface::class);
        $quants = $erp->getInventoryModifiedSince('2000-01-01 00:00:00');
        $quant  = collect($quants)->firstWhere(fn($q) => ($q['product_id'][0] ?? null) == $erpId);

        if (!$quant) {
            return back()->with('error', "No stock data found for product #{$erpId} in " . $this->settings->erpDisplayName() . '.');
        }

        $existing = SyncMapping::where('entity_type', 'inventory')
            ->where('erp_id', (string) $erpId)
            ->where('erp_driver', $this->settings->erpDriver())
            ->first();

        if ($existing) {
            $prevMeta  = is_array($existing->metadata) ? $existing->metadata : json_decode($existing->metadata ?? '{}', true);
            $prevWrite = $prevMeta['write_date'] ?? null;
            $newWrite  = $quant['write_date'] ?? null;
            $prevQty   = $prevMeta['quantity'] ?? $prevMeta['qty'] ?? null;
            $newQty    = $quant['quantity'] ?? $quant['qty'] ?? null;

            if ($prevWrite !== null && $prevWrite === $newWrite && $prevQty == $newQty) {
                return back()->with('info', "Product #{$erpId} stock unchanged — skipped.");
            }
        }

        SyncMapping::updateOrCreate(
            ['entity_type' => 'inventory', 'erp_id' => (string) $erpId, 'erp_driver' => $this->settings->erpDriver()],
            ['ecom_status' => 'pending', 'metadata' => $quant, 'last_synced_at' => now(), 'last_sync_direction' => 'erp_to_ecom']
        );

        return back()->with('success', "Stock fetched for product #{$erpId}. Click Post Stock to push to " . $this->settings->ecomDisplayName() . '.');
    }

    private function fetchStockSingleFromEcom(string $id)
    {
        // Look up the Shopify product mapped to this ID.
        // Blade passes erp_id when set, otherwise ecom_id — handle both.
        $productMapping = SyncMapping::where('entity_type', 'product')
            ->where(function ($q) use ($id) {
                $q->where('erp_id', $id)
                  ->orWhere('ecom_id', $id);
            })
            ->whereIn('last_sync_direction', ['ecom_to_erp', 'shopify_to_erp', 'shopify_to_odoo'])
            ->first();

        if (!$productMapping || !$productMapping->ecom_id) {
            return back()->with('error', "No Shopify product mapped for #{$id}. Fetch the product first.");
        }

        $ecom    = app(\App\Services\Ecom\EcomInterface::class);
        $product = $ecom->getProduct($productMapping->ecom_id);

        if (empty($product)) {
            return back()->with('error', "Product #{$productMapping->ecom_id} not found in " . $this->settings->ecomDisplayName() . '.');
        }

        $inventoryItemIds = collect($product['variants'] ?? [])
            ->pluck('inventory_item_id')
            ->filter()
            ->values()
            ->toArray();

        if (empty($inventoryItemIds)) {
            return back()->with('error', "No tracked inventory items for product #{$productMapping->ecom_id}.");
        }

        $locationId = $this->settings->get('shopify_location_id')
            ?? app(\App\Services\Shopify\ShopifyInventoryService::class)->getFirstLocationId();

        if (!$locationId) {
            return back()->with('error', 'No Shopify location configured. Add shopify_location_id in Settings.');
        }

        $levels = $ecom->getInventoryLevels($inventoryItemIds, $locationId);

        if (empty($levels)) {
            return back()->with('info', "No inventory levels returned for product #{$productMapping->ecom_id}.");
        }

        $stored = 0;
        foreach ($levels as $level) {
            $inventoryItemId = (string) ($level['inventory_item_id'] ?? '');
            if (!$inventoryItemId) continue;

            $existing = SyncMapping::where('entity_type', 'inventory')
                ->where('ecom_id', $inventoryItemId)
                ->where('ecom_driver', $this->settings->ecomDriver())
                ->first();

            if ($existing) {
                $prevMeta = is_array($existing->metadata) ? $existing->metadata : json_decode($existing->metadata ?? '{}', true);
                $prevQty  = $prevMeta['available'] ?? $prevMeta['quantity'] ?? null;
                $newQty   = $level['available'] ?? $level['quantity'] ?? null;
                if ($prevQty !== null && $prevQty == $newQty) continue;
            }

            SyncMapping::updateOrCreate(
                ['entity_type' => 'inventory', 'ecom_id' => $inventoryItemId, 'ecom_driver' => $this->settings->ecomDriver()],
                [
                    'ecom_status'         => 'pending',
                    'metadata'            => array_merge($level, [
                        'shopify_location_id' => $locationId,
                        'product_ecom_id'     => $productMapping->ecom_id,
                    ]),
                    'last_synced_at'      => now(),
                    'last_sync_direction' => 'ecom_to_erp',
                ]
            );
            $stored++;
        }

        if ($stored === 0) {
            return back()->with('info', "Stock unchanged for product #{$productMapping->ecom_id} — skipped.");
        }

        return back()->with('success', "{$stored} inventory item(s) fetched. Click Post Stock to push to " . $this->settings->erpDisplayName() . '.');
    }

    // ── Post Stock (single SKU) — direction-aware ─────────────────────────
    public function postStockSingle(Request $request, string $id)
    {
        $syncMode = $this->settings->inventorySyncMode();

        try {
            if ($syncMode === 'ecom_to_erp') {
                return $this->postStockSingleToErp($id);
            }
            return $this->postStockSingleToEcom((int) $id);
        } catch (\Throwable $e) {
            return back()->with('error', 'Post stock failed: ' . $e->getMessage());
        }
    }

    private function postStockSingleToEcom(int $erpId)
    {
        $mapping = SyncMapping::where('entity_type', 'inventory')
            ->where('erp_id', (string) $erpId)
            ->whereNotNull('metadata')
            ->first();

        if (!$mapping) {
            return back()->with('error', "No pending stock for #{$erpId}. Run Fetch Stock first.");
        }

        $meta      = is_array($mapping->metadata) ? $mapping->metadata : json_decode($mapping->metadata, true);
        $qty       = $meta['quantity'] ?? $meta['qty'] ?? null;
        $writeDate = $meta['write_date'] ?? null;

        // Re-fetch from Odoo to check if still current before pushing
        $erp     = app(\App\Services\Erp\ErpInterface::class);
        $quants  = $erp->getInventoryModifiedSince('2000-01-01 00:00:00');
        $current = collect($quants)->firstWhere(fn($q) => ($q['product_id'][0] ?? null) == $erpId);

        if ($current) {
            $currentWrite = $current['write_date'] ?? null;
            $currentQty   = $current['quantity'] ?? $current['qty'] ?? null;

            if ($mapping->ecom_status === 'posted' && $qty !== null && $qty == $currentQty && $writeDate === $currentWrite) {
                return back()->with('info', "Product #{$erpId} stock unchanged — skipped.");
            }

            // Update stored metadata to latest before pushing
            $mapping->update(['metadata' => $current, 'ecom_status' => 'pending']);
            $quant = $current;
        } else {
            $quant = $meta;
        }

        PushInventoryToEcomJob::dispatchSync($quant);
        $mapping->update(['ecom_status' => 'posted', 'last_synced_at' => now()]);

        return back()->with('success', "Stock pushed for product #{$erpId} to " . $this->settings->ecomDisplayName() . '.');
    }

    private function postStockSingleToErp(string $id)
    {
        $mapping = SyncMapping::where('entity_type', 'inventory')
            ->where(function ($q) use ($id) {
                $q->where('erp_id', $id)
                  ->orWhere('ecom_id', $id);
            })
            ->where('last_sync_direction', 'ecom_to_erp')
            ->whereNotNull('metadata')
            ->first();

        if (!$mapping) {
            return back()->with('error', "No stock data for #{$id}. Run Fetch Stock first.");
        }

        // Already posted and not re-queued as pending — nothing changed since last push
        if ($mapping->ecom_status === 'posted') {
            return back()->with('info', "Stock for #{$id} already pushed — no changes since last sync.");
        }

        $quant = is_array($mapping->metadata) ? $mapping->metadata : json_decode($mapping->metadata, true);

        \App\Jobs\Ecom\PushInventoryToErpJob::dispatchSync($quant);
        $mapping->update(['ecom_status' => 'posted', 'last_synced_at' => now()]);

        return back()->with('success', "Stock pushed for product #{$id} to " . $this->settings->erpDisplayName() . '.');
    }

    // ── Stock Info (single product) ───────────────────────────────────────
    public function stockInfo(string $id)
    {
        // Find the inventory mapping — id may be erp_id or ecom_id (inventory_item_id)
        $mapping = SyncMapping::where('entity_type', 'inventory')
            ->where(function ($q) use ($id) {
                $q->where('erp_id', $id)
                  ->orWhere('ecom_id', $id);
            })
            ->first();

        // Resolve all entity_ids this product's inventory logs could be filed under:
        // PushInventoryToErpJob writes entity_id = inventory_item_id (ecom_id)
        $logIds = array_filter(array_unique([$id, $mapping?->ecom_id, $mapping?->erp_id]));

        $logs = SyncLog::where('entity_type', 'inventory')
            ->whereIn('entity_id', $logIds)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        // Resolve ProductCache — try erp_id directly, then via product SyncMapping
        $cache = null;
        $erpIdColumn = \App\Models\ProductCache::erpIdColumn();

        if ($mapping?->erp_id) {
            $cache = \App\Models\ProductCache::where($erpIdColumn, $mapping->erp_id)
                ->orWhere('odoo_id', $mapping->erp_id)
                ->first();
        }

        if (!$cache && $mapping) {
            $meta          = is_array($mapping->metadata) ? $mapping->metadata : json_decode($mapping->metadata ?? '{}', true);
            $productEcomId = $meta['product_ecom_id'] ?? null;

            if ($productEcomId) {
                $productMapping = SyncMapping::where('entity_type', 'product')
                    ->where('ecom_id', (string) $productEcomId)
                    ->first();

                if ($productMapping?->erp_id) {
                    $cache = \App\Models\ProductCache::where($erpIdColumn, $productMapping->erp_id)
                        ->orWhere('odoo_id', $productMapping->erp_id)
                        ->first();
                }

                // Fall back to product metadata for name display
                if (!$cache && $productMapping?->metadata) {
                    $pmMeta = is_array($productMapping->metadata)
                        ? $productMapping->metadata
                        : json_decode($productMapping->metadata ?? '{}', true);
                    $cache = (object)[
                        'name'         => $pmMeta['title'] ?? $pmMeta['name'] ?? null,
                        'default_code' => $meta['sku'] ?? null,
                    ];
                }
            }
        }

        return view('dashboard.inventory-info', compact('mapping', 'logs', 'cache', 'id'))
            ->with('erpId', $id)
            ->with('erpDisplayName', $this->settings->erpDisplayName())
            ->with('ecomDisplayName', $this->settings->ecomDisplayName());
    }

}