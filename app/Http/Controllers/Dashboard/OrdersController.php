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

        // Deduplicate: when both a 'sales_order' and an 'order' row exist for the same
        // erp_id (created by the old SyncMapping::create bug), suppress the 'order' duplicate.
        $seenErpIds = [];
        $orders->getCollection()->transform(function ($mapping) use (&$seenErpIds) {
            if ($mapping->erp_id) {
                if (isset($seenErpIds[$mapping->erp_id])) {
                    $mapping->_duplicate = true;
                    return $mapping;
                }
                $seenErpIds[$mapping->erp_id] = true;
            }
            $mapping->_duplicate = false;
            return $mapping;
        });

        $orders->setCollection(
            $orders->getCollection()->filter(fn($m) => !$m->_duplicate)->values()
        );

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

    // ── Sales Info by Ecom ID (for orders not yet pushed to ERP) ─────────

    public function salesInfoByEcom(string $ecomId)
    {
        $mapping = SyncMapping::whereIn('entity_type', ['order', 'sales_order'])
            ->where('ecom_id', $ecomId)
            ->first();

        $logs = SyncLog::whereIn('entity_type', ['order', 'sales_order'])
            ->where(function ($q) use ($ecomId, $mapping) {
                $q->where('entity_id', $ecomId);
                if ($mapping?->erp_id) {
                    $q->orWhere('entity_id', $mapping->erp_id);
                }
            })
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $erpId           = $mapping?->erp_id ? (int) $mapping->erp_id : 0;
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

    // ── Fetch Orders from ERP (erp_to_ecom mode: Odoo → local → Shopify) ────
    // Fetch only — stores orders as pending. Use Post Sales to push to Shopify.

    public function fetch(Request $request)
    {
        try {
            $erp       = app(ErpInterface::class);
            $state     = SyncQueueState::forType('orders');
            $writeDate = $state->getErpWriteDate();

            $orders = $erp->getOrdersModifiedSince($writeDate, true); // onlyErpOrigin=true

            if (empty($orders)) {
                $cursor = date('Y-m-d H:i:s', strtotime($writeDate) + 1);
                $state->update(['last_erp_write_date' => $cursor, 'last_poll_at' => now(), 'notes' => 'nothing_changed']);
                return redirect()->route('dashboard.orders')
                    ->with('info', 'No new or updated orders in ' . $this->settings->erpDisplayName() . ' since last sync.');
            }

            $fetched         = 0;
            $skipped         = 0;
            $latestWriteDate = $writeDate;

            foreach ($orders as $order) {
                $erpId     = (string) $order['id'];
                $writeDate = $order['write_date'] ?? null;

                // Skip cancelled orders
                if ($order['state'] === 'cancel') {
                    $skipped++;
                    continue;
                }

                // Skip if already pushed to Shopify and write_date unchanged
                $existing = SyncMapping::whereIn('entity_type', ['order', 'sales_order'])
                    ->where('erp_id', $erpId)
                    ->first();

                if ($existing) {
                    if ($existing->erp_id && $existing->ecom_id && $existing->ecom_status === 'posted') {
                        $prevMeta      = is_array($existing->metadata) ? $existing->metadata : json_decode($existing->metadata ?? '{}', true);
                        $prevWriteDate = $prevMeta['write_date'] ?? null;
                        if ($prevWriteDate && $prevWriteDate === $order['write_date']) {
                            $skipped++;
                            if ($order['write_date'] > $latestWriteDate) $latestWriteDate = $order['write_date'];
                            continue;
                        }
                    }
                    // Already pending and unchanged
                    if ($existing->ecom_status === 'pending') {
                        $prevMeta      = is_array($existing->metadata) ? $existing->metadata : json_decode($existing->metadata ?? '{}', true);
                        $prevWriteDate = $prevMeta['write_date'] ?? null;
                        if ($prevWriteDate && $prevWriteDate === $order['write_date']) {
                            $skipped++;
                            if ($order['write_date'] > $latestWriteDate) $latestWriteDate = $order['write_date'];
                            continue;
                        }
                    }
                }

                // Store as pending — Post Sales will push to Shopify
                SyncMapping::updateOrCreate(
                    [
                        'entity_type' => 'sales_order',
                        'erp_id'      => $erpId,
                        'erp_driver'  => $this->settings->erpDriver(),
                    ],
                    [
                        'ecom_status'         => 'pending',
                        'last_sync_direction' => 'erp_to_ecom',
                        'metadata'            => $order,
                        'last_synced_at'      => now(),
                    ]
                );

                $fetched++;
                if ($order['write_date'] > $latestWriteDate) {
                    $latestWriteDate = $order['write_date'];
                }
            }

            // Advance cursor
            if ($latestWriteDate !== $state->getErpWriteDate()) {
                $cursor = date('Y-m-d H:i:s', strtotime($latestWriteDate) + 1);
                $state->update(['last_erp_write_date' => $cursor, 'last_poll_at' => now()]);
            }

            if ($fetched === 0) {
                return redirect()->route('dashboard.orders')
                    ->with('info', 'No new orders in ' . $this->settings->erpDisplayName() .
                        ($skipped > 0 ? " ({$skipped} unchanged skipped)." : '.'));
            }

            $skipNote = $skipped > 0 ? " ({$skipped} unchanged skipped)" : '';
            return redirect()->route('dashboard.orders')
                ->with('success', "{$fetched} order(s) fetched{$skipNote} from " . $this->settings->erpDisplayName() .
                    '. Click <strong>Post to Shopify</strong> to push.');

        } catch (\Throwable $e) {
            return redirect()->route('dashboard.orders')
                ->with('error', 'Fetch orders failed: ' . $e->getMessage());
        }
    }

    // ── Fetch Sales: pull from Ecom → cache only (no ERP post) ─────────────

    public function pull(Request $request)
    {
        FetchEcomOrdersOnlyJob::dispatchSync();

        // Read fresh from DB — job updates this row
        $notes = SyncQueueState::forType('orders')->fresh()->notes ?? '';

        if ($notes === 'nothing_changed') {
            return redirect()->route('dashboard.orders')
                ->with('info', 'No new or updated orders from ' . $this->settings->ecomDisplayName() . ' since last sync.');
        }

        preg_match('/Fetched: (\d+)/', $notes, $f);
        preg_match('/Skipped: (\d+)/', $notes, $sk);

        $fetched = (int) ($f[1] ?? 0);
        $skipped = (int) ($sk[1] ?? 0);

        if ($fetched === 0) {
            return redirect()->route('dashboard.orders')
                ->with('info', 'No new orders from ' . $this->settings->ecomDisplayName() . ' since last sync.'
                    . ($skipped > 0 ? " ({$skipped} already synced)" : ''));
        }

        $skipNote = $skipped > 0 ? " ({$skipped} already synced skipped)" : '';
        return redirect()->route('dashboard.orders')
            ->with('success', "{$fetched} order(s) fetched{$skipNote} from " . $this->settings->ecomDisplayName()
                . '. Use <strong>Post Sales</strong> to send them to ' . $this->settings->erpDisplayName() . '.');
    }
	
	

    // ── Post Sales: direction-aware push ─────────────────────────────────
    // ecom_to_erp: push pending Shopify orders → Odoo
    // erp_to_ecom: push pending Odoo orders → Shopify

    public function postSales(Request $request)
    {
        $syncMode = $this->settings->salesOrderSyncMode();

        $pending = SyncMapping::whereIn('entity_type', ['order', 'sales_order'])
            ->where('ecom_status', 'pending')
            ->whereNotNull('metadata')
            ->get();

        if ($pending->isEmpty()) {
            $fetchFrom = $syncMode === 'erp_to_ecom'
                ? $this->settings->erpDisplayName()
                : $this->settings->ecomDisplayName();
            return redirect()->route('dashboard.orders')
                ->with('info', "No pending orders to post. Use <strong>Fetch</strong> first to pull orders from {$fetchFrom}.");
        }

        if ($syncMode === 'erp_to_ecom') {
            // Push Odoo orders → Shopify
            $pushed = 0; $failed = 0;

            foreach ($pending as $mapping) {
                $order = is_array($mapping->metadata) ? $mapping->metadata : json_decode($mapping->metadata, true);
                if (empty($order)) { $failed++; continue; }

                try {
                    \App\Jobs\Ecom\PushOrderToEcomJob::dispatchSync((int) $mapping->erp_id);
                    $mapping->update(['ecom_status' => 'posted', 'last_synced_at' => now()]);
                    $pushed++;
                } catch (\Throwable $e) {
                    Log::error("postSales erp_to_ecom: failed for erp#{$mapping->erp_id}: " . $e->getMessage());
                    $failed++;
                }
            }

            $msg = "{$pushed} order(s) pushed to " . $this->settings->ecomDisplayName() . '.';
            if ($failed) $msg .= " {$failed} failed — check logs.";
            return redirect()->route('dashboard.orders')
                ->with($failed ? 'warning' : 'success', $msg);
        }

        // ecom_to_erp: push Shopify orders → Odoo (existing path)
        PostEcomOrdersToErpJob::dispatchSync();

        return redirect()->route('dashboard.orders')
            ->with('success', $pending->count() . " order(s) posted to " . $this->settings->erpDisplayName() . '.');
    }
	
	// ── Push single ERP order → Ecom (erp_to_ecom, called from Tools button) ──
    public function push(int $erpId)
    {
        $mapping = SyncMapping::whereIn('entity_type', ['order', 'sales_order'])
            ->where('erp_id', (string) $erpId)
            ->first();

        // Already pushed — has a Shopify order ID
        if ($mapping && $mapping->ecom_id) {
            return back()->with('info', "Order #{$erpId} already pushed to " . $this->settings->ecomDisplayName() . " (#{$mapping->ecom_id}).");
        }

        try {
            \App\Jobs\Ecom\PushOrderToEcomJob::dispatchSync($erpId);
            if ($mapping) {
                $mapping->refresh();
                $mapping->update(['ecom_status' => 'posted', 'last_synced_at' => now()]);
            }
            return back()->with('success', "Order #{$erpId} pushed to " . $this->settings->ecomDisplayName() . '.');
        } catch (\Throwable $e) {
            return back()->with('error', 'Push failed: ' . $e->getMessage());
        }
    }

	// ── Post single order to ERP (manual) ────────────────────────────────

    // ── Post single order — direction-aware ─────────────────────────────
    public function postSingle(string $ecomId)
    {
        $syncMode = $this->settings->salesOrderSyncMode();
        $mapping  = SyncMapping::whereIn('entity_type', ['order', 'sales_order'])
            ->where('ecom_id', $ecomId)
            ->first();

        if (!$mapping || empty($mapping->metadata)) {
            return redirect()->route('dashboard.orders')
                ->with('info', 'No data for this order. Use <strong>Fetch Sales</strong> first.');
        }

        // Skip if already posted and order unchanged
        if ($mapping->ecom_status === 'posted' && $mapping->erp_id) {
            $meta      = is_array($mapping->metadata) ? $mapping->metadata : json_decode($mapping->metadata, true);
            $prevUpd   = $meta['updated_at'] ?? $meta['write_date'] ?? null;
            $currentUpd = $prevUpd; // Can't re-fetch cheaply; trust stored state

            // If already has erp_id and was posted, consider it done unless re-fetched
            return back()->with('info', "Order #{$ecomId} already posted to " . $this->settings->erpDisplayName() . ".");
        }

        if ($syncMode === 'erp_to_ecom') {
            // Push Odoo order → Shopify
            if (!$mapping->erp_id) {
                return back()->with('error', "Order has no ERP ID. Cannot push to " . $this->settings->ecomDisplayName() . ".");
            }
            try {
                \App\Jobs\Ecom\PushOrderToEcomJob::dispatchSync((int) $mapping->erp_id);
                $mapping->update(['ecom_status' => 'posted', 'last_synced_at' => now()]);
                return back()->with('success', "Order posted to " . $this->settings->ecomDisplayName() . ".");
            } catch (\Throwable $e) {
                return back()->with('error', "Push failed: " . $e->getMessage());
            }
        }

        // ecom_to_erp: push Shopify order → Odoo
        PostEcomOrdersToErpJob::dispatchSync($ecomId);

        return back()->with('success', "Order #{$ecomId} posted to " . $this->settings->erpDisplayName() . '.');
    }

    // ── Fetch Dispatch: ERP → fetch fulfillments ──────────────────────────

    public function fetchDispatch(Request $request)
    {
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

                // Skip if already dispatched and picking date_done unchanged
                $pickingId   = (string) $picking['id'];
                $dateDone    = $picking['date_done'] ?? null;
                $existingMap = SyncMapping::where('entity_type', 'dispatch')
                    ->where('erp_id', $pickingId)
                    ->where('erp_driver', $this->settings->erpDriver())
                    ->first();

                if ($existingMap && $existingMap->ecom_status === 'dispatched') {
                    $prevMeta   = is_array($existingMap->metadata) ? $existingMap->metadata : json_decode($existingMap->metadata ?? '{}', true);
                    $prevDate   = $prevMeta['date_done'] ?? null;
                    if ($prevDate && $prevDate === $dateDone) {
                        $skipped++;
                        if ($dateDone && (!$latest || $dateDone > $latest)) {
                            $latest = $dateDone;
                        }
                        continue;
                    }
                }

                // Also skip if pending_dispatch already stored (fetched but not yet pushed)
                if ($existingMap && $existingMap->ecom_status === 'pending_dispatch') {
                    $skipped++;
                    if ($dateDone && (!$latest || $dateDone > $latest)) {
                        $latest = $dateDone;
                    }
                    continue;
                }

                // Store picking as pending_dispatch — ready for Post Dispatch
                SyncMapping::updateOrCreate(
                    [
                        'entity_type' => 'dispatch',
                        'erp_id'      => $pickingId,
                        'erp_driver'  => $this->settings->erpDriver(),
                    ],
                    [
                        'ecom_id'             => $orderMapping->ecom_id,
                        'ecom_driver'         => $this->settings->ecomDriver(),
                        'ecom_status'         => 'pending_dispatch',
                        'last_sync_direction' => 'erp_to_ecom',
                        'metadata'            => $picking,
                        'last_synced_at'      => now(),
                    ]
                );

                $fetched++;

                $doneDt = $picking['date_done'] ?? null;
                if ($doneDt && (!$latest || $doneDt > $latest)) {
                    $latest = $doneDt;
                }
            }

            if ($latest) {
                // Advance by 1 second — query uses strict > so this excludes last-seen record
                $cursorDate = date('Y-m-d H:i:s', strtotime($latest) + 1);
                $state->update(['last_erp_write_date' => $cursorDate, 'last_poll_at' => now()]);
            }

            if ($fetched === 0) {
                $reason = $skipped > 0
                    ? " ({$skipped} already dispatched or pending.)"
                    : '.';
                return redirect()->route('dashboard.orders')
                    ->with('info', 'No new fulfillments to fetch from ' . $this->settings->erpDisplayName() . $reason);
            }

            $skipNote = $skipped > 0 ? " ({$skipped} already dispatched skipped)" : '';
            return redirect()->route('dashboard.orders')
                ->with('success', "{$fetched} dispatch(es) fetched{$skipNote}. Click Post Dispatch to push to " . $this->settings->ecomDisplayName() . '.');

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

    // ── Fetch Dispatch (single order) ─────────────────────────────────────
    public function fetchDispatchSingle(int $erpId)
    {
        try {
            $erp      = app(ErpInterface::class);
            $pickings = $erp->getFulfilledOrders();

            // Filter to pickings belonging to this sale order
            $matched = collect($pickings)->filter(function ($picking) use ($erpId) {
                $saleId = $picking['erp_order_id']
                    ?? (is_array($picking['sale_id'] ?? null) ? $picking['sale_id'][0] : ($picking['sale_id'] ?? null));
                return (int) $saleId === $erpId;
            });

            if ($matched->isEmpty()) {
                return back()->with('info', "No fulfilled deliveries found for order #{$erpId} in " . $this->settings->erpDisplayName() . '.');
            }

            $orderMapping = SyncMapping::whereIn('entity_type', ['order', 'sales_order'])
                ->where('erp_id', (string) $erpId)
                ->first();

            if (!$orderMapping) {
                return back()->with('error', "No Shopify mapping found for order #{$erpId}. Post the sale first.");
            }

            $stored = 0;
            foreach ($matched as $picking) {
                $pickingId = (string) $picking['id'];
                $dateDone  = $picking['date_done'] ?? null;

                $existing = SyncMapping::where('entity_type', 'dispatch')
                    ->where('erp_id', $pickingId)
                    ->first();

                if ($existing && $existing->ecom_status === 'dispatched') {
                    $prevMeta = is_array($existing->metadata) ? $existing->metadata : json_decode($existing->metadata ?? '{}', true);
                    if (($prevMeta['date_done'] ?? null) === $dateDone) {
                        continue; // already dispatched, unchanged
                    }
                }

                if ($existing && $existing->ecom_status === 'pending_dispatch') {
                    continue; // already pending
                }

                SyncMapping::updateOrCreate(
                    ['entity_type' => 'dispatch', 'erp_id' => $pickingId, 'erp_driver' => $this->settings->erpDriver()],
                    [
                        'ecom_id'             => $orderMapping->ecom_id,
                        'ecom_driver'         => $this->settings->ecomDriver(),
                        'ecom_status'         => 'pending_dispatch',
                        'last_sync_direction' => 'erp_to_ecom',
                        'metadata'            => $picking,
                        'last_synced_at'      => now(),
                    ]
                );
                $stored++;
            }

            if ($stored === 0) {
                return back()->with('info', "Dispatch for order #{$erpId} already fetched — no changes.");
            }

            return back()->with('success', "Dispatch fetched for order #{$erpId}. Click Post Dispatch to push to " . $this->settings->ecomDisplayName() . '.');
        } catch (\Throwable $e) {
            return back()->with('error', 'Fetch dispatch failed: ' . $e->getMessage());
        }
    }

    // ── Post Dispatch (single order) ──────────────────────────────────────
    public function postDispatchSingle(int $erpId)
    {
        try {
            $pending = SyncMapping::where('entity_type', 'dispatch')
                ->where('ecom_status', 'pending_dispatch')
                ->whereNotNull('metadata')
                ->whereRaw("JSON_EXTRACT(metadata, '$.erp_order_id') = ?", [$erpId])
                ->get();

            // Fallback: match via ecom_id of the order mapping
            if ($pending->isEmpty()) {
                $orderMapping = SyncMapping::whereIn('entity_type', ['order', 'sales_order'])
                    ->where('erp_id', (string) $erpId)
                    ->first();

                if ($orderMapping) {
                    $pending = SyncMapping::where('entity_type', 'dispatch')
                        ->where('ecom_status', 'pending_dispatch')
                        ->where('ecom_id', $orderMapping->ecom_id)
                        ->whereNotNull('metadata')
                        ->get();
                }
            }

            if ($pending->isEmpty()) {
                return back()->with('info', "No pending dispatch for order #{$erpId}. Run Fetch Dispatch first.");
            }

            $pushed = 0; $failed = 0;
            foreach ($pending as $mapping) {
                $picking = is_array($mapping->metadata) ? $mapping->metadata : json_decode($mapping->metadata, true);
                if (empty($picking)) { $failed++; continue; }

                $picking['_ecom_order_id'] = $mapping->ecom_id;

                try {
                    \App\Jobs\Ecom\PushFulfillmentToEcomJob::dispatchSync($picking);
                    $mapping->update(['ecom_status' => 'dispatched', 'last_synced_at' => now()]);
                    $pushed++;
                } catch (\Throwable $e) {
                    Log::error("postDispatchSingle: picking#{$mapping->erp_id} failed: " . $e->getMessage());
                    $failed++;
                }
            }

            $msg = "{$pushed} fulfillment(s) pushed to " . $this->settings->ecomDisplayName() . '.';
            if ($failed) $msg .= " {$failed} failed — check logs.";
            return back()->with($failed ? 'warning' : 'success', $msg);

        } catch (\Throwable $e) {
            return back()->with('error', 'Post dispatch failed: ' . $e->getMessage());
        }
    }
	
	}