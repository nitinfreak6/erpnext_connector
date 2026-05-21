<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\SyncLog;
use App\Models\SyncMapping;
use App\Models\SyncQueueState;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function index(Request $request)
    {
        $search  = $request->input('search');
        $channel = $request->input('channel', 'all');
        $status  = $request->input('status');

        if ($channel === 'shopify') {
            $entityTypes = [SyncMapping::TYPE_INVENTORY_ITEM];
        } elseif ($channel === 'amazon') {
            $entityTypes = ['amazon_inventory'];
        } else {
            $entityTypes = [SyncMapping::TYPE_INVENTORY_ITEM, 'amazon_inventory'];
        }

        // Get variant mappings (these hold inventory_item_id for ecom platform)
        $variantQuery = SyncMapping::where('entity_type', 'product_variant')
            ->whereNotNull('ecom_secondary_id')
            ->orderByDesc('last_synced_at');

        if ($search) {
            $variantQuery->where(function ($q) use ($search) {
                $q->where('ecom_handle', 'like', "%{$search}%")
                  ->orWhere('erp_id', 'like', "%{$search}%")
                  ->orWhere('ecom_secondary_id', 'like', "%{$search}%");
            });
        }

        $variants = $variantQuery->paginate(50)->withQueryString();

        // Recent inventory sync logs
        $logsQuery = SyncLog::whereIn('entity_type', $entityTypes)
            ->orderByDesc('created_at');

        if ($status) {
            $logsQuery->where('status', $status);
        }

        $recentLogs = $logsQuery->limit(30)->get();

        $syncState = [
            'inventory'        => SyncQueueState::where('sync_type', 'inventory')->first() 
                ?? (object)[
                    'is_running' => false, 
                    'last_poll_at' => null, 
                    'last_erp_write_date' => null,
                    'last_odoo_write_date' => null,  // Backward compat
                ],
            'amazon_inventory' => SyncQueueState::where('sync_type', 'amazon_inventory')->first()
                ?? (object)[
                    'is_running' => false, 
                    'last_poll_at' => null, 
                    'last_erp_write_date' => null,
                    'last_odoo_write_date' => null,  // Backward compat
                ],
        ];

        // Stats
        $stats = [
            'synced_today'  => SyncLog::whereIn('entity_type', $entityTypes)
                                ->where('status', 'success')
                                ->whereDate('created_at', today())->count(),
            'failed_today'  => SyncLog::whereIn('entity_type', $entityTypes)
                                ->where('status', 'failed')
                                ->whereDate('created_at', today())->count(),
            'total_skus'    => SyncMapping::where('entity_type', 'product_variant')->count(),
            'mapped_skus'   => SyncMapping::where('entity_type', 'product_variant')->whereNotNull('ecom_secondary_id')->count(),
        ];

        return view('dashboard.inventory', compact('variants', 'search', 'channel', 'recentLogs', 'syncState', 'stats'));
    }
}