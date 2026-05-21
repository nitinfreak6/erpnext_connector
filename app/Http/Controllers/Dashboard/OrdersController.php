<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\SyncLog;
use App\Models\SyncMapping;
use Illuminate\Http\Request;

class OrdersController extends Controller
{
    public function index(Request $request)
    {
        $search  = $request->input('search');
        $channel = $request->input('channel', 'all');
        $status  = $request->input('status');

        $entityTypes = match ($channel) {
            'shopify' => ['order'],
            'amazon'  => ['amazon_order'],
            default   => ['order', 'amazon_order'],
        };

        // Generic column query
        $query = SyncMapping::whereIn('entity_type', $entityTypes)
            ->orderByDesc('last_synced_at');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('erp_id', 'like', "%{$search}%")
                  ->orWhere('ecom_id', 'like', "%{$search}%")
                  ->orWhere('ecom_handle', 'like', "%{$search}%")
                  // Backward compatibility
                  ->orWhere('odoo_id', 'like', "%{$search}%")
                  ->orWhere('shopify_id', 'like', "%{$search}%")
                  ->orWhere('shopify_handle', 'like', "%{$search}%");
            });
        }

        $orders = $query->paginate(50)->withQueryString();

        // Get settings
        $settings = app(\App\Services\SettingsService::class);
        $syncMode = $settings->salesOrderSyncMode() ?? 'ecom_to_erp';  // Default: orders come FROM ecom
        $erpDriver = $settings->erpDriver();
        $ecomDriver = $settings->ecomDriver();

        // Stats
        $stats = [
            'ecom_total'    => SyncMapping::where('entity_type', 'order')->count(),
            'amazon_total'  => SyncMapping::where('entity_type', 'amazon_order')->count(),
            'today'         => SyncMapping::whereIn('entity_type', ['order', 'amazon_order'])
                                    ->whereDate('last_synced_at', today())->count(),
            'total'         => SyncMapping::whereIn('entity_type', ['order', 'amazon_order'])->count(),
        ];

        // Recent order sync logs
        $recentLogs = SyncLog::whereIn('entity_type', ['order', 'amazon_order'])
            ->whereIn('direction', ['ecom_to_erp', 'erp_to_ecom', 'shopify_to_odoo', 'odoo_to_shopify'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        return view('dashboard.orders', compact(
            'orders', 'search', 'channel', 'stats', 'recentLogs',
            'syncMode', 'erpDriver', 'ecomDriver'
        ));
    }

    /**
     * Fetch orders FROM ERP, create them in Ecom
     */
    public function fetch(Request $request)
    {
        // This dispatches job that:
        // 1. Gets orders from ERP (Odoo)
        // 2. Creates/updates them in Ecom (Shopify)
        \App\Jobs\Erp\FetchErpOrdersJob::dispatch();
        
        $erpDriver = app(\App\Services\SettingsService::class)->erpDriver();
        return redirect()->route('dashboard.orders')
            ->with('success', 'Started fetching orders from ' . ucfirst($erpDriver));
    }

    /**
     * Pull orders FROM Ecom, create them in ERP
     */
    public function pull(Request $request)
    {
        // This dispatches job that:
        // 1. Gets orders from Ecom (Shopify)
        // 2. Creates/updates them in ERP (Odoo)
        \App\Jobs\Ecom\FetchEcomOrdersJob::dispatch();
        
        $ecomDriver = app(\App\Services\SettingsService::class)->ecomDriver();
        return redirect()->route('dashboard.orders')
            ->with('success', 'Started pulling orders from ' . ucfirst($ecomDriver));
    }
}