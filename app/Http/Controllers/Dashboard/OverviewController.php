<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\SyncLog;
use App\Models\SyncMapping;
use App\Models\SyncQueueState;
use App\Models\WebhookLog;
use Illuminate\Support\Facades\DB;

class OverviewController extends Controller
{
    public function index()
    {
        // ── Channel counts ────────────────────────────────────────
        // Built from the connector registry so a new channel appears here
        // automatically — no hardcoded 'shopify' / 'amazon'.
        $registry = app(\App\Services\ConnectorRegistry::class);
        $stats    = [];

        foreach ($registry->ecomDrivers() as $slug => $cfg) {
            $stats[$slug] = [
                'products'  => SyncMapping::whereIn('entity_type', $registry->entityTypes($slug, 'product'))->count(),
                'orders'    => SyncMapping::whereIn('entity_type', $registry->entityTypes($slug, 'order'))->count(),
                'customers' => SyncMapping::whereIn('entity_type', $registry->entityTypes($slug, 'customer'))->count(),
            ];
        }

        // ── Sync health (last 24h) ────────────────────────────────
        $since = now()->subDay();

        $health = [
            'success' => SyncLog::where('status', 'success')->where('created_at', '>=', $since)->count(),
            'failed'  => SyncLog::where('status', 'failed')->where('created_at', '>=', $since)->count(),
            'pending' => SyncLog::where('status', 'pending')->count(),
        ];

        // ── Queue depths ──────────────────────────────────────────
        try {
            $queues = [
                'sync'     => DB::table('jobs')->where('queue', 'sync')->count(),
                'webhooks' => DB::table('jobs')->where('queue', 'webhooks')->count(),
                'failed'   => DB::table('failed_jobs')->count(),
            ];
        } catch (\Throwable) {
            $queues = ['sync' => '?', 'webhooks' => '?', 'failed' => '?'];
        }

        // ── Sync cursor state ─────────────────────────────────────
        $syncState = SyncQueueState::all()->keyBy('sync_type');

        // ── Recent failures ───────────────────────────────────────
        $recentFailures = SyncLog::where('status', 'failed')
            ->orderByDesc('created_at')
            ->limit(8)
            ->get();

        // ── Recent webhooks ───────────────────────────────────────
        $recentWebhooks = WebhookLog::orderByDesc('created_at')
            ->limit(6)
            ->get();

        // ── Sync activity chart data (last 7 days) ────────────────
        $chartData = SyncLog::select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('SUM(CASE WHEN status = "success" THEN 1 ELSE 0 END) as success'),
                DB::raw('SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed')
            )
            ->where('created_at', '>=', now()->subDays(7))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return view('dashboard.index', compact(
            'stats', 'health', 'queues', 'syncState', 'recentFailures', 'recentWebhooks', 'chartData'
        ));
    }
}
