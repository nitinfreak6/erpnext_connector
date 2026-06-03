<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Jobs\Ecom\FetchEcomOrdersJob;
use App\Jobs\Ecom\FetchEcomOrdersOnlyJob;
use App\Jobs\Ecom\PostEcomOrdersToErpJob;
use App\Jobs\Ecom\PushFulfillmentToEcomJob;
use App\Jobs\Ecom\PushOrderToEcomJob;
use App\Jobs\Erp\FetchErpOrdersJob;
use App\Models\SyncLog;
use App\Models\SyncMapping;
use App\Models\SyncQueueState;
use App\Services\Erp\ErpInterface;
use App\Services\SettingsService;
use Illuminate\Http\Request;

class OrdersController extends Controller
{
    public function __construct(private readonly SettingsService $settings) {}

    // ── Index ─────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $search  = $request->input('search', '');
        $channel = $request->input('channel', 'all');
        $perPage = (int) $request->input('per_page', 25);

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

        $orders = $query->paginate($perPage)->withQueryString();

        $orders->getCollection()->transform(function ($mapping) {
            $mapping->latest_log = SyncLog::whereIn('entity_type', ['order', 'sales_order'])
                ->where(function ($q) use ($mapping) {
                    $q->where('entity_id', $mapping->ecom_id)
                      ->orWhere('entity_id', $mapping->erp_id);
                })
                ->latest()
                ->first();

            $mapping->dispatch_log = SyncLog::where('entity_type', 'dispatch')
                ->where(function ($q) use ($mapping) {
                    $q->where('entity_id', $mapping->ecom_id)
                      ->orWhere('entity_id', $mapping->erp_id);
                })
                ->latest()
                ->first();

            return $mapping;
        });

        $syncMode = $this->settings->salesOrderSyncMode();

        $stats = [
            'ecom_total'   => SyncMapping::whereIn('entity_type', ['order', 'sales_order'])->count(),
            'amazon_total' => SyncMapping::where('entity_type', 'amazon_order')->count(),
            'today'        => SyncMapping::whereIn('entity_type', ['order', 'sales_order', 'amazon_order'])->whereDate('last_synced_at', today())->count(),
            'total'        => SyncMapping::whereIn('entity_type', ['order', 'sales_order', 'amazon_order'])->count(),
        ];

        $recentLogs = SyncLog::whereIn('entity_type', ['order', 'sales_order', 'dispatch', 'amazon_order'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $erpDisplayName  = $this->settings->erpDisplayName();
        $ecomDisplayName = $this->settings->ecomDisplayName();
        $erpDriver       = $this->settings->erpDriver();
        $ecomDriver      = $this->settings->ecomDriver();

        return view('dashboard.orders', compact(
            'orders', 'search', 'channel', 'perPage',
            'stats', 'recentLogs', 'syncMode',
            'erpDisplayName', 'ecomDisplayName', 'erpDriver', 'ecomDriver'
        ));
    }

    // ── Sales Info (order sync detail) ────────────────────────────────────

    public function salesInfo(int $erpId)
    {
        $mapping = SyncMapping::whereIn('entity_type', ['order', 'sales_order'])
            ->where('erp_id', (string) $erpId)
            ->first();

        $logs = SyncLog::whereIn('entity_type', ['order', 'sales_order'])
            ->where(function ($q) use ($erpId, $mapping) {
                $q->where('entity_id', (string) $erpId);
                if ($mapping?->ecom_id) {
                    $q->orWhere('entity_id', $mapping->ecom_id);
                }
            })
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $syncMode        = $this->settings->salesOrderSyncMode();
        $erpDisplayName  = $this->settings->erpDisplayName();
        $ecomDisplayName = $this->settings->ecomDisplayName();

        return view('dashboard.orders-info', compact(
            'mapping', 'logs', 'erpId', 'syncMode',
            'erpDisplayName', 'ecomDisplayName'
        ))->with('infoType', 'sales');
    }

    // ── Dispatch Info ─────────────────────────────────────────────────────

    public function dispatchInfo(int $erpId)
    {
        $mapping = SyncMapping::whereIn('entity_type', ['order', 'sales_order'])
            ->where('erp_id', (string) $erpId)
            ->first();

        $logs = SyncLog::where('entity_type', 'dispatch')
            ->where(function ($q) use ($erpId, $mapping) {
                $q->where('entity_id', (string) $erpId);
                if ($mapping?->ecom_id) {
                    $q->orWhere('entity_id', $mapping->ecom_id);
                }
            })
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $syncMode        = $this->settings->salesOrderSyncMode();
        $erpDisplayName  = $this->settings->erpDisplayName();
        $ecomDisplayName = $this->settings->ecomDisplayName();

        return view('dashboard.orders-info', compact(
            'mapping', 'logs', 'erpId', 'syncMode',
            'erpDisplayName', 'ecomDisplayName'
        ))->with('infoType', 'dispatch');
    }

    // ── Fetch Sales: pull from Ecom → cache only (no ERP post) ─────────────

    public function pull(Request $request)
    {
        FetchEcomOrdersOnlyJob::dispatchSync();

        $state = SyncQueueState::forType('orders')->fresh();
        $notes = $state->notes ?? '';

        preg_match('/Fetched: (\d+)/', $notes, $f);
        preg_match('/Skipped: (\d+)/', $notes, $sk);

        $fetched = (int) ($f[1]  ?? 0);
        $skipped = (int) ($sk[1] ?? 0);

        if ($fetched === 0) {
            return redirect()->route('dashboard.orders')
                ->with('info', 'No new orders from ' . $this->settings->ecomDisplayName() . ' since last sync.'
                    . ($skipped > 0 ? " {$skipped} already synced." : ''));
        }

        return redirect()->route('dashboard.orders')
            ->with('success', "{$fetched} order(s) fetched from " . $this->settings->ecomDisplayName()
                . '. Use <strong>Post Sales</strong> to send them to ' . $this->settings->erpDisplayName() . '.');
    }
	
	

    // ── Post Sales: post pending fetched orders to ERP ───────────────────

    public function postSales(Request $request)
    {
        $pending = SyncMapping::whereIn('entity_type', ['order', 'sales_order'])
            ->where('ecom_status', 'pending')
            ->whereNotNull('metadata')
            ->count();

        if ($pending === 0) {
            return redirect()->route('dashboard.orders')
                ->with('info', 'No pending orders to post. Use <strong>Fetch Sales</strong> first to pull orders from '
                    . $this->settings->ecomDisplayName() . '.');
        }

        PostEcomOrdersToErpJob::dispatchSync();

        return redirect()->route('dashboard.orders')
            ->with('success', "{$pending} order(s) posted to " . $this->settings->erpDisplayName() . '.');
    }
	
	// ── Post single order to ERP (manual) ────────────────────────────────

    public function postSingle(string $ecomId)
    {
		
		$mapping = SyncMapping::whereIn('entity_type', ['order', 'sales_order'])
            ->where('ecom_id', $ecomId)
			->where('ecom_status', 'pending')
            ->first();

        if (!$mapping || empty($mapping->metadata)) {
           return redirect()->route('dashboard.orders')
                ->with('info', 'No pending orders to post. Use <strong>Fetch Sales</strong> first to pull orders from '
                    . $this->settings->ecomDisplayName() . '.');
        }
		
        PostEcomOrdersToErpJob::dispatchSync($ecomId);

        return back()->with('success',
            "Order #{$ecomId} posted to " . $this->settings->erpDisplayName() . '.');
    }

    // ── Fetch Dispatch: ERP → fetch fulfillments ──────────────────────────

    public function fetchDispatch(Request $request)
    {
        $syncMode = $this->settings->salesOrderSyncMode();

        if ($syncMode === 'ecom_to_erp') {
            return redirect()->route('dashboard.orders')
                ->with('error', 'Sync mode is Ecom → ERP. Dispatch flows the other direction.');
        }

        try {
            // Use dispatch cursor for incremental fetching
            $state     = SyncQueueState::firstOrCreate(
                ['sync_type' => 'dispatch'],
                ['last_erp_write_date' => null]
            );
            $sinceDate = $state->last_erp_write_date;

            $erp     = app(ErpInterface::class);
            $orders  = $erp->getFulfilledOrders($sinceDate);
            $pushed  = 0;
            $latest  = null;

            foreach ($orders as $order) {
                PushFulfillmentToEcomJob::dispatch($order);
                $pushed++;
                // Track latest date_done for cursor
                $doneDt = $order['date_done'] ?? null;
                if ($doneDt && (!$latest || $doneDt > $latest)) {
                    $latest = $doneDt;
                }
            }

            if ($latest) {
                $state->update(['last_erp_write_date' => $latest, 'last_poll_at' => now()]);
            }

            if ($pushed === 0) {
                return redirect()->route('dashboard.orders')
                    ->with('info', 'No new dispatched orders in ' . $this->settings->erpDisplayName() . ' since last sync.');
            }

            return redirect()->route('dashboard.orders')
                ->with('success', "{$pushed} dispatch(es) fetched from " . $this->settings->erpDisplayName() . ' and queued for ' . $this->settings->ecomDisplayName() . '.');

        } catch (\Throwable $e) {
            return redirect()->route('dashboard.orders')
                ->with('error', 'Fetch dispatch failed: ' . $e->getMessage());
        }
    }

    // ── Post Dispatch: push all pending fulfillments to Ecom ──────────────

    public function postDispatch(Request $request)
    {
        $syncMode = $this->settings->salesOrderSyncMode();

        if ($syncMode === 'ecom_to_erp') {
            return redirect()->route('dashboard.orders')
                ->with('error', 'Sync mode is Ecom → ERP. Cannot push dispatch to ecom.');
        }

        try {
            // Post dispatch pushes ALL fulfilled orders (full push, not incremental)
            $erp    = app(ErpInterface::class);
            $orders = $erp->getFulfilledOrders();
            $pushed = 0;

            foreach ($orders as $order) {
                PushFulfillmentToEcomJob::dispatch($order);
                $pushed++;
            }

            if ($pushed === 0) {
                return redirect()->route('dashboard.orders')
                    ->with('info', 'No dispatched orders found to push.');
            }

            return redirect()->route('dashboard.orders')
                ->with('success', "{$pushed} fulfillment(s) queued for " . $this->settings->ecomDisplayName() . '.');

        } catch (\Throwable $e) {
            return redirect()->route('dashboard.orders')
                ->with('error', 'Post dispatch failed: ' . $e->getMessage());
        }
    }

    // ── Detail (show) ─────────────────────────────────────────────────────

    public function show(int $erpId)
    {
        return $this->salesInfo($erpId);
    }
}
