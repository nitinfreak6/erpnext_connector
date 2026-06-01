<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Jobs\Ecom\FetchEcomOrdersJob;
use App\Jobs\Erp\FetchErpOrdersJob;
use App\Models\SyncLog;
use App\Models\SyncMapping;
use App\Models\SyncQueueState;
use App\Services\SettingsService;
use Illuminate\Http\Request;

class OrdersController extends Controller
{
    public function __construct(private readonly SettingsService $settings) {}

    public function index(Request $request)
    {
        $search  = $request->input('search');
        $channel = $request->input('channel', 'all');
        $status  = $request->input('status');

        // FIX: include 'sales_order' — that's what UniversalSyncService saves
        $entityTypes = match ($channel) {
            'shopify' => ['order', 'sales_order'],
            'amazon'  => ['amazon_order'],
            default   => ['order', 'sales_order', 'amazon_order'],
        };

        $query = SyncMapping::whereIn('entity_type', $entityTypes)
            ->orderByDesc('last_synced_at');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('erp_id', 'like', "%{$search}%")
                  ->orWhere('ecom_id', 'like', "%{$search}%")
                  ->orWhere('ecom_handle', 'like', "%{$search}%");
            });
        }

        $orders = $query->paginate(50)->withQueryString();

        $syncMode  = $this->settings->salesOrderSyncMode();
        $erpDriver = $this->settings->erpDriver();
        $ecomDriver= $this->settings->ecomDriver();

        $stats = [
            'ecom_total'   => SyncMapping::whereIn('entity_type', ['order', 'sales_order'])->count(),
            'amazon_total' => SyncMapping::where('entity_type', 'amazon_order')->count(),
            'today'        => SyncMapping::whereIn('entity_type', ['order', 'sales_order', 'amazon_order'])
                                ->whereDate('last_synced_at', today())->count(),
            'total'        => SyncMapping::whereIn('entity_type', ['order', 'sales_order', 'amazon_order'])->count(),
        ];

        $recentLogs = SyncLog::whereIn('entity_type', ['order', 'sales_order', 'amazon_order'])
            ->whereIn('direction', ['ecom_to_erp', 'erp_to_ecom', 'shopify_to_odoo', 'odoo_to_shopify'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        return view('dashboard.orders', compact(
            'orders', 'search', 'channel', 'stats', 'recentLogs',
            'syncMode', 'erpDriver', 'ecomDriver'
        ));
    }

    public function fetch(Request $request)
    {
        FetchErpOrdersJob::dispatchSync();

        $state = SyncQueueState::forType('orders')->fresh();
        $notes = $state->notes ?? '';

        if (str_contains($notes, 'Synced: 0') && str_contains($notes, 'Failed: 0')) {
            return redirect()->route('dashboard.orders')
                ->with('info', 'No new or updated orders in ' . $this->settings->erpDisplayName() . ' since last sync.');
        }

        return redirect()->route('dashboard.orders')
            ->with('success', 'Orders fetched from ' . $this->settings->erpDisplayName() . '. ' . $notes);
    }

    public function pull(Request $request)
	{
		FetchEcomOrdersJob::dispatchSync();

		$state = SyncQueueState::forType('orders')->fresh();
		$notes = $state->notes ?? '';

		if ($notes) {
			preg_match('/Synced: (\d+)/', $notes, $syncedMatch);
			preg_match('/Skipped: (\d+)/', $notes, $skippedMatch);
			preg_match('/Failed: (\d+)/', $notes, $failedMatch);

			$synced  = (int) ($syncedMatch[1]  ?? 0);
			$skipped = (int) ($skippedMatch[1] ?? 0);
			$failed  = (int) ($failedMatch[1]  ?? 0);

			if ($synced === 0 && $failed === 0) {
				return redirect()->route('dashboard.orders')
					->with('info', 'No new orders from ' . $this->settings->ecomDisplayName() . '. ' . $skipped . ' order(s) already synced.');
			}

			$parts = [];
			if ($synced === 0 && $failed === 0) {
				$msg = $skipped > 0
					? "No new orders from {$this->settings->ecomDisplayName()}. {$skipped} already synced."
					: "No new or updated orders from {$this->settings->ecomDisplayName()} since last sync.";

				return redirect()->route('dashboard.orders')->with('info', $msg);
			}
		}

		return redirect()->route('dashboard.orders')
			->with('success', 'Orders pulled from ' . $this->settings->ecomDisplayName() . '.');
	}
}