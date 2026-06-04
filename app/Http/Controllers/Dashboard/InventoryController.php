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
			$cache  = ProductCache::where($erpCol, $mapping->erp_id)
				->orWhere('odoo_id', $mapping->erp_id)
				->first();

			$mapping->product_name = $cache?->name ?? 'Product #' . $mapping->erp_id;
			$mapping->sku          = $cache?->default_code ?? '—';

			// Pull qty/location from stored metadata
			$meta = is_array($mapping->metadata) ? $mapping->metadata : json_decode($mapping->metadata ?? '{}', true);
			$mapping->erp_qty            = $meta['quantity'] ?? $meta['qty'] ?? null;
			$mapping->shopify_location_id = $meta['shopify_location_id'] ?? null;
			$mapping->erp_location_id    = $meta['location_id'][0] ?? $meta['erp_location_id'] ?? null;

			// Latest push log (SyncLog only written by PushInventoryToEcomJob)
			$mapping->latest_log = SyncLog::where('entity_type', 'inventory')
				->where('entity_id', (string) $mapping->erp_id)
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


    // ── Fetch Stock (all): pull from ERP → store pending ─────────────────
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

    // ── Post Stock (all): push pending inventory to Ecom ────────────────
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
                        \App\Jobs\Erp\PushInventoryToErpJob::dispatchSync($quant);
                    } else {
                        PushInventoryToEcomJob::dispatchSync($quant);
                    }
                    $mapping->update(['ecom_status' => 'posted', 'last_synced_at' => now()]);
                    $pushed++;
                } catch (\Throwable $e) {
                    Log::error("postStock: failed for erp#{$mapping->erp_id}: " . $e->getMessage());
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

    // ── Fetch Stock (single SKU) — direction-aware, store as pending ──────
    public function fetchStockSingle(Request $request, int $erpId)
    {
        $syncMode = $this->settings->inventorySyncMode();

        try {
            $erp    = app(\App\Services\Erp\ErpInterface::class);
            $quants = $erp->getInventoryModifiedSince('2000-01-01 00:00:00');
            $quant  = collect($quants)->firstWhere(fn($q) => ($q['product_id'][0] ?? null) == $erpId);

            if (!$quant) {
                return back()->with('error', "No stock data found for product #{$erpId} in " . $this->settings->erpDisplayName() . '.');
            }

            // Skip if unchanged
            $existing = SyncMapping::where('entity_type', 'inventory')
                ->where('erp_id', (string) $erpId)
                ->where('erp_driver', $this->settings->erpDriver())
                ->first();

            if ($existing) {
                $prevMeta  = is_array($existing->metadata) ? $existing->metadata : json_decode($existing->metadata ?? '{}', true);
                $prevQty   = $prevMeta['quantity'] ?? $prevMeta['qty'] ?? null;
                $newQty    = $quant['quantity'] ?? $quant['qty'] ?? null;
                $prevWrite = $prevMeta['write_date'] ?? null;
                $newWrite  = $quant['write_date'] ?? null;

                if ($prevWrite !== null && $prevWrite === $newWrite && $prevQty == $newQty) {
                    return back()->with('info', "Product #{$erpId} stock unchanged — skipped.");
                }
            }

            $direction = $syncMode === 'ecom_to_erp' ? 'ecom_to_erp' : 'erp_to_ecom';
            SyncMapping::updateOrCreate(
                ['entity_type' => 'inventory', 'erp_id' => (string) $erpId, 'erp_driver' => $this->settings->erpDriver()],
                ['ecom_status' => 'pending', 'metadata' => $quant, 'last_synced_at' => now(), 'last_sync_direction' => $direction]
            );

            $dest = $syncMode === 'ecom_to_erp' ? $this->settings->erpDisplayName() : $this->settings->ecomDisplayName();
            return back()->with('success', "Stock fetched for product #{$erpId}. Click Post Stock to push to {$dest}.");
        } catch (\Throwable $e) {
            return back()->with('error', 'Fetch stock failed: ' . $e->getMessage());
        }
    }

    // ── Post Stock (single SKU) ───────────────────────────────────────────
    public function postStockSingle(Request $request, int $erpId)
    {
        try {
            $mapping = SyncMapping::where('entity_type', 'inventory')
                ->where('erp_id', (string) $erpId)
                ->whereNotNull('metadata')
                ->first();

            if (!$mapping) {
                return back()->with('error', "No pending stock for #{$erpId}. Run Fetch Stock first.");
            }

            // Skip if already posted and quantity/write_date unchanged
            // Check if current Odoo quantity matches stored — skip if unchanged
            if ($mapping->ecom_status === 'posted' || $mapping->ecom_status === 'pending') {
                $meta     = is_array($mapping->metadata) ? $mapping->metadata : json_decode($mapping->metadata, true);
                $qty      = $meta['quantity'] ?? $meta['qty'] ?? null;
                $writeDate = $meta['write_date'] ?? null;

                // Re-fetch current quant to compare
                $erp      = app(\App\Services\Erp\ErpInterface::class);
                $quants   = $erp->getInventoryModifiedSince('2000-01-01 00:00:00');
                $current  = collect($quants)->firstWhere(fn($q) => ($q['product_id'][0] ?? null) == $erpId);

                if ($current) {
                    $currentQty   = $current['quantity'] ?? $current['qty'] ?? null;
                    $currentWrite = $current['write_date'] ?? null;

                    if ($qty !== null && $qty == $currentQty && $writeDate === $currentWrite) {
                        return back()->with('info', "Product #{$erpId} stock unchanged — skipped.");
                    }

                    // Quantity changed — update stored metadata before pushing
                    $mapping->update(['metadata' => $current, 'ecom_status' => 'pending']);
                    $quant = $current;
                } else {
                    $quant = $meta;
                }
            } else {
                $quant = is_array($mapping->metadata) ? $mapping->metadata : json_decode($mapping->metadata, true);
            }

            $invMode = $this->settings->inventorySyncMode();
            if ($invMode === 'ecom_to_erp') {
                \App\Jobs\Erp\PushInventoryToErpJob::dispatchSync($quant);
            } else {
                PushInventoryToEcomJob::dispatchSync($quant);
            }
            $mapping->update(['ecom_status' => 'posted', 'last_synced_at' => now()]);

            $dest = $invMode === 'ecom_to_erp' ? $this->settings->erpDisplayName() : $this->settings->ecomDisplayName();
            return back()->with('success', "Stock pushed for product #{$erpId} to {$dest}.");
        } catch (\Throwable $e) {
            return back()->with('error', 'Post stock failed: ' . $e->getMessage());
        }
    }

    // ── Stock Info (single product) ───────────────────────────────────────
    public function stockInfo(int $erpId)
    {
        $mapping = SyncMapping::where('entity_type', 'inventory')
            ->where('erp_id', (string) $erpId)
            ->first();

        $logs = SyncLog::where('entity_type', 'inventory')
            ->where('entity_id', (string) $erpId)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $cache = \App\Models\ProductCache::where(\App\Models\ProductCache::erpIdColumn(), $erpId)
            ->orWhere('odoo_id', $erpId)
            ->first();

        return view('dashboard.inventory-info', compact('mapping', 'logs', 'cache', 'erpId'))
            ->with('erpDisplayName', $this->settings->erpDisplayName())
            ->with('ecomDisplayName', $this->settings->ecomDisplayName());
    }

}