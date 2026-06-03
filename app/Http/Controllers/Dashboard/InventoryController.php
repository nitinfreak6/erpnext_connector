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

        // Inventory logs are saved with entity_type = 'inventory' or 'amazon_inventory'
        $entityTypes = match($channel) {
            'shopify' => ['inventory'],
            'amazon'  => ['amazon_inventory'],
            default   => ['inventory', 'amazon_inventory'],
        };

        // Show inventory sync logs with product name from cache
        $logsQuery = SyncLog::whereIn('entity_type', $entityTypes)
            ->orderByDesc('created_at');

        if ($status) {
            $logsQuery->where('status', $status);
        }

        if ($search) {
            $logsQuery->where(function ($q) use ($search) {
                $q->where('entity_id', 'like', "%{$search}%")
                  ->orWhere('request_payload', 'like', "%{$search}%");
            });
        }

        $logs = $logsQuery->paginate(50)->withQueryString();

        // Build location name map from settings for display
        $locationMap = array_flip($this->settings->odooLocationMap()); // shopify_id => odoo_id

        // Enrich with product name from cache
        $logs->getCollection()->transform(function ($log) use ($locationMap) {
            $erpCol = ProductCache::erpIdColumn();
            $cache  = ProductCache::where($erpCol, $log->entity_id)
                ->orWhere('odoo_id', $log->entity_id)
                ->first();
            $log->product_name = $cache?->name ?? 'Product #' . $log->entity_id;
            $log->sku          = $cache?->default_code ?? '—';

            // Decode payload to surface location IDs for display
            $payload = is_string($log->request_payload)
                ? json_decode($log->request_payload, true)
                : (array) ($log->request_payload ?? []);
            $log->erp_qty            = $payload['qty'] ?? null;
            $log->shopify_location_id = $payload['shopify_location_id'] ?? null;
            $log->erp_location_id    = $payload['erp_location_id'] ?? null;

            return $log;
        });

        // Recent logs for bottom section
        $recentLogs = SyncLog::whereIn('entity_type', $entityTypes)
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

        // Keep $variants for blade compatibility — use logs as the main data
        $variants = $logs;

        $erpDisplayName  = $this->settings->erpDisplayName();
        $ecomDisplayName = $this->settings->ecomDisplayName();

        return view('dashboard.inventory', compact(
            'variants', 'logs', 'search', 'channel',
            'recentLogs', 'syncState', 'stats',
            'erpDisplayName', 'ecomDisplayName'
        ));
    }


    // ── Fetch Stock (all): pull from ERP → store pending ─────────────────
    public function fetchStock(Request $request)
    {
        try {
            FetchErpInventoryJob::dispatchSync();
            return redirect()->route('dashboard.inventory')
                ->with('success', 'Stock fetched from ' . $this->settings->erpDisplayName() . ' and queued for push.');
        } catch (\Throwable $e) {
            return redirect()->route('dashboard.inventory')
                ->with('error', 'Fetch stock failed: ' . $e->getMessage());
        }
    }

    // ── Post Stock (all): push all pending inventory to Ecom ─────────────
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

            $pushed = 0; $failed = 0;
            foreach ($pending as $mapping) {
                $quant = is_array($mapping->metadata) ? $mapping->metadata : json_decode($mapping->metadata, true);
                if (empty($quant)) { $failed++; continue; }
                try {
                    PushInventoryToEcomJob::dispatchSync($quant);
                    $mapping->update(['ecom_status' => 'posted', 'last_synced_at' => now()]);
                    $pushed++;
                } catch (\Throwable $e) {
                    $failed++;
                }
            }

            $msg = "{$pushed} stock update(s) pushed to " . $this->settings->ecomDisplayName() . ".";
            if ($failed) $msg .= " {$failed} failed.";
            return redirect()->route('dashboard.inventory')
                ->with($failed ? 'warning' : 'success', $msg);
        } catch (\Throwable $e) {
            return redirect()->route('dashboard.inventory')
                ->with('error', 'Post stock failed: ' . $e->getMessage());
        }
    }

    // ── Fetch Stock (single SKU) ──────────────────────────────────────────
    public function fetchStockSingle(Request $request, int $erpId)
    {
        try {
            $erp    = app(\App\Services\Erp\ErpInterface::class);
            $quants = $erp->getInventoryModifiedSince('2000-01-01 00:00:00');
            $quant  = collect($quants)->firstWhere(fn($q) => ($q['product_id'][0] ?? null) == $erpId);

            if (!$quant) {
                return back()->with('error', "No stock data found for product #{$erpId} in " . $this->settings->erpDisplayName() . '.');
            }

            SyncMapping::updateOrCreate(
                ['entity_type' => 'inventory', 'erp_id' => (string) $erpId, 'erp_driver' => $this->settings->erpDriver()],
                ['ecom_status' => 'pending', 'metadata' => $quant, 'last_synced_at' => now(), 'last_sync_direction' => 'erp_to_ecom']
            );

            return back()->with('success', "Stock fetched for product #{$erpId}. Click Post Stock to push.");
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

            $quant = is_array($mapping->metadata) ? $mapping->metadata : json_decode($mapping->metadata, true);
            PushInventoryToEcomJob::dispatchSync($quant);
            $mapping->update(['ecom_status' => 'posted', 'last_synced_at' => now()]);

            return back()->with('success', "Stock pushed for product #{$erpId}.");
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