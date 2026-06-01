<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\ProductCache;
use App\Models\SyncLog;
use App\Models\SyncMapping;
use App\Models\SyncQueueState;
use App\Services\SettingsService;
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

        // Enrich with product name from cache
        $logs->getCollection()->transform(function ($log) {
            $erpCol = ProductCache::erpIdColumn();
            $cache  = ProductCache::where($erpCol, $log->entity_id)
                ->orWhere('odoo_id', $log->entity_id)
                ->first();
            $log->product_name = $cache?->name ?? 'Product #' . $log->entity_id;
            $log->sku          = $cache?->default_code ?? '—';
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

        return view('dashboard.inventory', compact(
            'variants', 'logs', 'search', 'channel',
            'recentLogs', 'syncState', 'stats'
        ));
    }
}