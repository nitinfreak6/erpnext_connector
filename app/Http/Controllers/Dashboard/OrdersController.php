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
use Illuminate\Support\Facades\Log;
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

            // Find dispatch log by ecom_id, erp_id (sale order), or any linked picking IDs
            $dispatchPickingIds = SyncMapping::where('entity_type', 'dispatch')
                ->where(function ($q) use ($mapping) {
                    $q->where('ecom_id', $mapping->ecom_id);
                    if ($mapping->erp_id) {
                        $q->orWhereRaw("JSON_EXTRACT(metadata, '$.erp_order_id') = ?", [$mapping->erp_id]);
                    }
                })
                ->pluck('erp_id')
                ->toArray();

            $mapping->dispatch_log = SyncLog::where('entity_type', 'dispatch')
                ->where(function ($q) use ($mapping, $dispatchPickingIds) {
                    $q->where('entity_id', $mapping->ecom_id)
                      ->orWhere('entity_id', (string) $mapping->erp_id);
                    if (!empty($dispatchPickingIds)) {
                        $q->orWhereIn('entity_id', array_map('strval', $dispatchPickingIds));
                    }
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

        // Also find logs by any dispatch SyncMapping erp_id (picking IDs) linked to this order.
        // The job may log with picking ID (legacy) or sale order ID (current).
        $pickingIds = SyncMapping::where('entity_type', 'dispatch')
            ->where(function ($q) use ($erpId, $mapping) {
                $q->whereRaw("JSON_EXTRACT(metadata, '$.erp_order_id') = ?", [$erpId]);
                if ($mapping?->ecom_id) {
                    $q->orWhere('ecom_id', $mapping->ecom_id);
                }
            })
            ->pluck('erp_id')
            ->toArray();

        $logs = SyncLog::where('entity_type', 'dispatch')
            ->where(function ($q) use ($erpId, $mapping, $pickingIds) {
                $q->where('entity_id', (string) $erpId);
                if ($mapping?->ecom_id) {
                    $q->orWhere('entity_id', $mapping->ecom_id);
                }
                if (!empty($pickingIds)) {
                    $q->orWhereIn('entity_id', array_map('strval', $pickingIds));
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
            // ── FETCH ONLY: pull fulfilled pickings from ERP and store locally.
            // Does NOT push to Shopify. Use Post Dispatch for that.
            $state     = SyncQueueState::firstOrCreate(
                ['sync_type' => 'dispatch'],
                ['last_erp_write_date' => null]
            );
            $sinceDate = $state->last_erp_write_date;

            $erp      = app(ErpInterface::class);
            $pickings = $erp->getFulfilledOrders($sinceDate);
            $fetched  = 0;
            $skipped  = 0;
            $latest   = null;

            foreach ($pickings as $picking) {
                $saleOrderId = (string) (
                    $picking['erp_order_id']
                    ?? (is_array($picking['sale_id'] ?? null) ? $picking['sale_id'][0] : ($picking['sale_id'] ?? null))
                );

                if (!$saleOrderId) { $skipped++; continue; }

                // Find the ecom order mapping to get the Shopify order ID
                $orderMapping = SyncMapping::whereIn('entity_type', ['order', 'sales_order'])
                    ->where('erp_id', $saleOrderId)
                    ->first();

                if (!$orderMapping) {
                    Log::debug("fetchDispatch: no ecom mapping for sale#{$saleOrderId}, skipping picking#{$picking['id']}");
                    $skipped++;
                    continue;
                }

                // Store picking as a pending dispatch mapping — ready for Post Dispatch
                SyncMapping::updateOrCreate(
                    [
                        'entity_type' => 'dispatch',
                        'erp_id'      => (string) $picking['id'],   // picking ID
                        'erp_driver'  => $this->settings->erpDriver(),
                    ],
                    [
                        'ecom_id'              => $orderMapping->ecom_id,  // Shopify order ID
                        'ecom_driver'          => $this->settings->ecomDriver(),
                        'ecom_status'          => 'pending_dispatch',
                        'last_sync_direction'  => 'erp_to_ecom',
                        'metadata'             => $picking,   // full picking data for Post Dispatch
                        'last_synced_at'       => now(),
                    ]
                );

                $fetched++;

                $doneDt = $picking['date_done'] ?? null;
                if ($doneDt && (!$latest || $doneDt > $latest)) {
                    $latest = $doneDt;
                }
            }

            if ($latest) {
                $state->update(['last_erp_write_date' => $latest, 'last_poll_at' => now()]);
            }

            if ($fetched === 0) {
                return redirect()->route('dashboard.orders')
                    ->with('info', 'No new fulfilled orders found in ' . $this->settings->erpDisplayName() .
                        ($skipped > 0 ? " ({$skipped} skipped — no ecom mapping)." : '.'));
            }

            return redirect()->route('dashboard.orders')
                ->with('success', "{$fetched} dispatch(es) fetched from " . $this->settings->erpDisplayName() .
                    '. Click Post Dispatch to push to ' . $this->settings->ecomDisplayName() . '.');

        } catch (\Throwable $e) {
            return redirect()->route('dashboard.orders')
                ->with('error', 'Fetch dispatch failed: ' . $e->getMessage());
        }
    }

    // ── Post Dispatch: push pending_dispatch mappings to Ecom ──────────────
    // Reads locally stored dispatch mappings (set by Fetch Dispatch) and
    // pushes each one to Shopify as a fulfillment. Independent of Odoo.

    public function postDispatch(Request $request)
    {
        $syncMode = $this->settings->salesOrderSyncMode();

        if ($syncMode === 'ecom_to_erp') {
            return redirect()->route('dashboard.orders')
                ->with('error', 'Sync mode is Ecom → ERP. Cannot push dispatch to ecom.');
        }

        try {
            $pending = SyncMapping::where('entity_type', 'dispatch')
                ->where('ecom_status', 'pending_dispatch')
                ->whereNotNull('metadata')
                ->get();

            if ($pending->isEmpty()) {
                return redirect()->route('dashboard.orders')
                    ->with('info', 'No pending dispatches. Run Fetch Dispatch first.');
            }

            $pushed  = 0;
            $failed  = 0;

            foreach ($pending as $mapping) {
                // metadata cast='array' on SyncMapping — Eloquent decodes it automatically.
                // Guard both cases in case of legacy double-encoded rows.
                $picking = is_array($mapping->metadata)
                    ? $mapping->metadata
                    : json_decode($mapping->metadata, true);

                // If still a string after decode, it was double-encoded
                if (is_string($picking)) {
                    $picking = json_decode($picking, true);
                }

                if (empty($picking) || !is_array($picking)) {
                    Log::warning("postDispatch: invalid metadata for dispatch mapping#{$mapping->id}, skipping.");
                    $failed++;
                    continue;
                }

                // Inject erp_order_id and ecom order ID for PushFulfillmentToEcomJob
                $picking['erp_order_id'] = $picking['erp_order_id'] ?? null;
                $picking['_ecom_order_id'] = $mapping->ecom_id;

                try {
                    PushFulfillmentToEcomJob::dispatchSync($picking);
                    $mapping->update(['ecom_status' => 'dispatched', 'last_synced_at' => now()]);
                    $pushed++;
                } catch (\Throwable $e) {
                    Log::error("postDispatch: failed for picking#{$mapping->erp_id}: " . $e->getMessage());
                    $failed++;
                }
            }

            $msg = "{$pushed} fulfillment(s) pushed to " . $this->settings->ecomDisplayName() . ".";
            if ($failed > 0) $msg .= " {$failed} failed — check logs.";

            return redirect()->route('dashboard.orders')
                ->with($failed > 0 ? 'warning' : 'success', $msg);

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