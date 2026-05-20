<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\SyncLog;
use App\Models\SyncMapping;
use App\Models\WebhookLog;
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

        $query = SyncMapping::whereIn('entity_type', $entityTypes)
            ->orderByDesc('last_synced_at');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('odoo_id', 'like', "%{$search}%")
                  ->orWhere('shopify_id', 'like', "%{$search}%")
                  ->orWhere('shopify_handle', 'like', "%{$search}%");
            });
        }

        $orders = $query->paginate(50)->withQueryString();

        // Stats
        $stats = [
            'ecom_total' => SyncMapping::where('entity_type', 'order')->count(),
            'amazon_total'  => SyncMapping::where('entity_type', 'amazon_order')->count(),
            'today'         => SyncMapping::whereIn('entity_type', ['order', 'amazon_order'])
                                ->whereDate('last_synced_at', today())->count(),
        ];

        // Recent order sync logs
        $recentLogs = SyncLog::whereIn('entity_type', ['order', 'amazon_order'])
            ->whereIn('direction', ['ecom_to_erp', 'shopify_to_odoo'])  // Support both generic and legacy
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $ecomDriver = app(\App\Services\SettingsService::class)->ecomDriver();

        return view('dashboard.orders', compact('orders', 'search', 'channel', 'stats', 'recentLogs', 'ecomDriver'));
    }
}