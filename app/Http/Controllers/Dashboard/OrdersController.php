<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\HandlesAjaxSyncResponses;
use App\Jobs\Ecom\FetchEcomOrdersJob;
use App\Jobs\Ecom\FetchEcomOrdersOnlyJob;
use App\Jobs\Ecom\PostEcomOrdersToErpJob;
use App\Jobs\Ecom\PushFulfillmentToEcomJob;
use App\Jobs\Erp\FetchErpOrdersJob;
use App\Jobs\Erp\PushFulfillmentToErpJob;
use App\Models\SyncLog;
use Illuminate\Support\Facades\Log;
use App\Models\SyncMapping;
use App\Models\SyncQueueState;
use App\Services\Ecom\EcomInterface;
use App\Services\Erp\ErpInterface;
use App\Services\SettingsService;
use App\Services\Sync\OrderSyncService;
use App\Services\Sync\SyncEntityState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrdersController extends Controller
{
    use HandlesAjaxSyncResponses;

    public function __construct(private readonly SettingsService $settings) {}

    // ── Index ─────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $ctx = $this->listingContext($request);
        $orders = $this->queryOrders($ctx);

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

        return view('dashboard.orders', array_merge($ctx, compact('orders', 'stats', 'recentLogs')));
    }

    public function rows(Request $request): JsonResponse
    {
        $ctx = $this->listingContext($request);

        return response()->json([
            'html' => $this->renderOrdersRowsHtml($this->queryOrders($ctx), $ctx),
        ]);
    }

    private function listingContext(Request $request): array
    {
        return [
            'syncMode'        => $this->settings->salesOrderSyncMode(),
            'search'          => $request->input('search', ''),
            'status'          => $request->input('status', 'all'),
            'channel'         => $request->input('channel', 'all'),
            'perPage'         => (int) $request->input('per_page', 25),
            'direction'       => $request->input('direction', 'erp_to_ecom'),
            'erpDisplayName'  => $this->settings->erpDisplayName(),
            'ecomDisplayName' => $this->settings->ecomDisplayName(),
            'erpDriver'       => $this->settings->erpDriver(),
            'ecomDriver'      => $this->settings->ecomDriver(),
            'dispatchFlow'    => $this->settings->dispatchFlowForListing($request->input('direction')),
        ];
    }

    private function queryOrders(array $ctx)
    {
        $registry    = app(\App\Services\ConnectorRegistry::class);
        $entityTypes = $ctx['channel'] === 'all'
            ? $registry->allEntityTypesForCategory('order')
            : $registry->entityTypes($ctx['channel'], 'order');

        if (empty($entityTypes)) {
            $entityTypes = $registry->allEntityTypesForCategory('order');
        }

        $query = SyncMapping::whereIn('entity_type', $entityTypes)
            ->orderByDesc('last_synced_at');

        if ($ctx['syncMode'] === 'ecom_to_erp') {
            $query->where('last_sync_direction', 'ecom_to_erp');
        } elseif ($ctx['syncMode'] === 'erp_to_ecom') {
            $query->where('last_sync_direction', 'erp_to_ecom');
        } elseif ($ctx['direction'] === 'ecom_to_erp') {
            $query->where('last_sync_direction', 'ecom_to_erp');
        } else {
            $query->where('last_sync_direction', 'erp_to_ecom');
        }

        $this->applyOrderStatusFilter($query, $ctx['status']);

        if ($ctx['search']) {
            $search = $ctx['search'];
            $query->where(function ($q) use ($search) {
                $q->where('erp_id', 'like', "%{$search}%")
                  ->orWhere('ecom_id', 'like', "%{$search}%")
                  ->orWhere('ecom_handle', 'like', "%{$search}%");
            });
        }

        $orders = $query->paginate($ctx['perPage'])->withQueryString();

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
            $orders->getCollection()->filter(fn ($m) => !($m->_duplicate ?? false))->values()
        );

        $orders->getCollection()->transform(function ($mapping) {
            return $this->enrichOrderMappingExtras($mapping);
        });

        return $orders;
    }

    private function enrichOrderMappingExtras(SyncMapping $mapping): SyncMapping
    {
        $dispatchMappings = SyncMapping::where('entity_type', 'dispatch')
            ->where(function ($q) use ($mapping) {
                $clauses = 0;
                if ($mapping->ecom_id) {
                    $q->where('ecom_id', $mapping->ecom_id);
                    $clauses++;
                }
                if ($mapping->erp_id) {
                    $method = $clauses > 0 ? 'orWhere' : 'where';
                    $q->{$method}('erp_reference', (string) $mapping->erp_id);
                }
            })
            ->get();

        $dispatchPickingIds = $dispatchMappings->pluck('erp_id')->filter()->toArray();
        $dispatchEcomIds    = $dispatchMappings->pluck('ecom_id')->filter()->toArray();

        $mapping->dispatch_status = SyncEntityState::aggregateDispatchStatus($dispatchMappings);

        $mapping->dispatch_log = SyncLog::where('entity_type', 'dispatch')
            ->where(function ($q) use ($mapping, $dispatchPickingIds, $dispatchEcomIds) {
                $q->where('entity_id', $mapping->ecom_id)
                    ->orWhere('entity_id', (string) $mapping->erp_id);
                if (!empty($dispatchPickingIds)) {
                    $q->orWhereIn('entity_id', array_map('strval', $dispatchPickingIds));
                }
                if (!empty($dispatchEcomIds)) {
                    $q->orWhereIn('entity_id', array_map('strval', $dispatchEcomIds));
                }
            })
            ->orderByDesc('id')
            ->first();

        $mapping->display_status  = SyncEntityState::displayStatus($mapping);
        $mapping->display_message = $this->resolveOrderDisplayMessage($mapping);

        return $mapping;
    }

    private function clearOrderDispatchMessage(?string $erpOrderId): void
    {
        if ($erpOrderId === null || $erpOrderId === '') {
            return;
        }

        SyncMapping::whereIn('entity_type', ['order', 'sales_order'])
            ->where('erp_id', (string) $erpOrderId)
            ->update(['sync_message' => null]);
    }

    private function resolveOrderDisplayMessage(SyncMapping $mapping): ?string
    {
        if ($mapping->dispatch_log?->status === 'failed' && filled($mapping->dispatch_log->error_message)) {
            return $mapping->dispatch_log->error_message;
        }

        if (filled($mapping->sync_message)) {
            if ($mapping->sync_message === SyncEntityState::DISPATCH_MSG_NO_UPDATE) {
                return $mapping->sync_message;
            }

            // Latest dispatch succeeded — do not show a stale dispatch error in Message
            if ($mapping->dispatch_log?->status === 'success') {
                return null;
            }

            if ($mapping->display_status !== SyncEntityState::STATUS_SENT) {
                return $mapping->sync_message;
            }
        }

        return null;
    }

    private function applyOrderStatusFilter($query, string $status): void
    {
        if ($status === 'all' || $status === '') {
            return;
        }

        if (in_array($status, ['sent', 'success'], true)) {
            $query->whereIn('ecom_status', SyncEntityState::SYNCED_ALIASES);
        } elseif ($status === 'updated') {
            $query->where('ecom_status', SyncEntityState::STATUS_UPDATED);
        } elseif ($status === 'pending') {
            $query->where(function ($q) {
                $q->whereIn('ecom_status', [SyncEntityState::STATUS_PENDING, SyncEntityState::STATUS_FAILED])
                  ->orWhereNull('ecom_status');
            });
        }
    }

    private function renderOrdersRowsHtml($orders, array $ctx): string
    {
        return view('dashboard.partials.orders-table-rows', array_merge($ctx, [
            'orders' => $orders,
        ]))->render();
    }

    private function renderOrderRowHtml($mapping, array $ctx): string
    {
        $isErpToEcom = $ctx['syncMode'] === 'erp_to_ecom'
            || ($ctx['syncMode'] === 'bidirectional' && ($ctx['direction'] ?? 'erp_to_ecom') === 'erp_to_ecom');

        $rowId = $isErpToEcom
            ? 'erp-' . ($mapping->erp_id ?? 'unknown')
            : 'ecom-' . ($mapping->ecom_id ?? 'unknown');

        return view('dashboard.partials.orders-table-row', array_merge($ctx, [
            'mapping'  => $mapping,
            'rowIndex' => abs(crc32($rowId)),
        ]))->render();
    }

    private function orderRowPayload(array $ctx, SyncMapping $mapping): array
    {
        $mapping = $this->enrichOrderMappingExtras($mapping->fresh());

        $isErpToEcom = $ctx['syncMode'] === 'erp_to_ecom'
            || ($ctx['syncMode'] === 'bidirectional' && ($ctx['direction'] ?? 'erp_to_ecom') === 'erp_to_ecom');

        $rowId = $isErpToEcom
            ? 'erp-' . ($mapping->erp_id ?? 'unknown')
            : 'ecom-' . ($mapping->ecom_id ?? 'unknown');

        return [
            'row_id'   => $rowId,
            'row_html' => $this->renderOrderRowHtml($mapping, $ctx),
        ];
    }

    /** @return list<string> */
    private function normalizeEcomOrderIds(string $ecomId): array
    {
        $candidates = [trim($ecomId)];

        if (str_contains($ecomId, 'gid://')) {
            $candidates[] = (string) last(explode('/', trim($ecomId)));
        }

        if (preg_match('/^#(\d+)$/', trim($ecomId), $matches)) {
            $candidates[] = $matches[1];
        }

        return array_values(array_unique(array_filter(
            $candidates,
            fn ($v) => $v !== '' && $v !== '0'
        )));
    }

    /** Odoo many2one values arrive as [id, "Label"] — never cast the array directly. */
    private function resolveOdooScalarReference(mixed $value): string
    {
        if ($value === null || $value === false || $value === '') {
            return '';
        }

        if (is_array($value)) {
            if (isset($value[0]) && $value[0] !== false && $value[0] !== '') {
                return (string) $value[0];
            }

            return (string) ($value[1] ?? '');
        }

        return (string) $value;
    }

    /** @param array<string, mixed> $picking */
    private function resolvePickingSaleOrderId(array $picking): ?string
    {
        $id = $this->resolveOdooScalarReference(
            $picking['erp_order_id'] ?? $picking['sale_id'] ?? null
        );

        return $id !== '' ? $id : null;
    }

    /** Compare ERP primary keys — Odoo uses int ids, ERPNext uses string names. */
    private function erpIdsMatch(int|string $left, int|string|null $right): bool
    {
        if ($right === null || $right === '') {
            return false;
        }

        return (string) $left === (string) $right;
    }

    /** Flatten {fulfillment, message} wire log for display. */
    private function flattenDispatchWireInput(mixed $wireInput): ?array
    {
        if (!is_array($wireInput)) {
            return null;
        }

        if (isset($wireInput['fulfillment']) && is_array($wireInput['fulfillment'])) {
            $flat = $wireInput['fulfillment'];
            if (!empty($wireInput['message'])) {
                $flat['message'] = $wireInput['message'];
            }

            return $flat;
        }

        return $wireInput;
    }

    /**
     * Inject linked e-commerce order id onto picking payload (not an Odoo ORM field).
     *
     * @param  array<string, mixed>  $picking
     * @return array<string, mixed>
     */
    private function enrichDispatchPickingPayload(array $picking, ?string $ecomOrderId): array
    {
        if ($ecomOrderId !== null && $ecomOrderId !== '') {
            $picking['_ecom_order_id'] = $ecomOrderId;
        }

        return $picking;
    }

    /** @param array<string, mixed> $picking */
    private function dispatchPickingUnchanged(SyncMapping $existing, array $picking): bool
    {
        if (!in_array($existing->ecom_status, [SyncEntityState::DISPATCH_SENT, SyncEntityState::DISPATCH_UPDATED], true)) {
            return false;
        }

        $prevMeta = $existing->payload() ?? [];
        $prevDate = $prevMeta['date_done'] ?? null;
        $dateDone = $picking['date_done'] ?? null;

        return $prevDate && $dateDone && $prevDate === $dateDone;
    }

    /**
     * @param  array<string, mixed>  $picking
     */
    private function persistDispatchPicking(array $picking, SyncMapping $orderMapping, string $ecomStatus): SyncMapping
    {
        $pickingId   = (string) $picking['id'];
        $saleOrderId = $this->resolvePickingSaleOrderId($picking) ?? $orderMapping->erp_id;

        $mapping = SyncMapping::updateOrCreate(
            [
                'entity_type' => 'dispatch',
                'erp_id'      => $pickingId,
                'erp_driver'  => $this->settings->erpDriver(),
            ],
            [
                'ecom_id'             => $orderMapping->ecom_id,
                'ecom_driver'         => $this->settings->ecomDriver(),
                'ecom_status'         => $ecomStatus,
                'last_sync_direction' => 'erp_to_ecom',
                'erp_reference'       => (string) $saleOrderId,
                'metadata'            => null,
                'last_synced_at'      => now(),
                'sync_message'        => null,
            ]
        );

        \App\Services\Sync\SyncPayloadStore::put(
            'dispatch',
            'erp',
            $pickingId,
            $this->enrichDispatchPickingPayload($picking, $orderMapping->ecom_id)
        );

        return $mapping;
    }

    /** @param array<string, mixed> $fulfillment */
    private function dispatchFulfillmentUnchanged(SyncMapping $existing, array $fulfillment): bool
    {
        if (!in_array($existing->ecom_status, [SyncEntityState::DISPATCH_SENT, SyncEntityState::DISPATCH_UPDATED], true)) {
            return false;
        }

        $prevMeta = $existing->payload() ?? [];
        $prevCreated = $prevMeta['createdAt'] ?? null;
        $created     = $fulfillment['createdAt'] ?? null;
        $prevTracking = $prevMeta['trackingInfo']['number'] ?? null;
        $tracking     = $fulfillment['trackingInfo']['number'] ?? null;

        return $prevCreated && $created && $prevCreated === $created && $prevTracking === $tracking;
    }

    /**
     * @param  array<string, mixed>  $fulfillment
     */
    private function persistDispatchFulfillment(array $fulfillment, SyncMapping $orderMapping, string $ecomStatus): SyncMapping
    {
        $fulfillmentId = (string) ($fulfillment['id'] ?? '');
        $saleOrderId   = (string) $orderMapping->erp_id;

        $mapping = SyncMapping::firstOrNew(
            [
                'entity_type' => 'dispatch',
                'ecom_id'     => $fulfillmentId,
                'ecom_driver' => $this->settings->ecomDriver(),
            ]
        );

        $mapping->fill([
            'erp_driver'          => $this->settings->erpDriver(),
            'ecom_status'         => $ecomStatus,
            'last_sync_direction' => 'ecom_to_erp',
            'erp_reference'       => $saleOrderId,
            'metadata'            => null,
            'last_synced_at'      => now(),
            'sync_message'        => null,
        ]);

        if (!$mapping->exists) {
            $mapping->erp_id = null;
        }

        $mapping->save();

        \App\Services\Sync\SyncPayloadStore::put(
            'dispatch',
            'ecom',
            $fulfillmentId,
            $this->enrichDispatchFulfillmentPayload($fulfillment, $orderMapping)
        );

        return $mapping;
    }

    /** @param array<string, mixed> $fulfillment */
    private function enrichDispatchFulfillmentPayload(array $fulfillment, SyncMapping $orderMapping): array
    {
        $defaultLocation = app(\App\Services\ChannelMappingService::class)->defaultShopifyWarehouseLocationId();

        return array_merge($fulfillment, [
            '_ecom_order_id'      => $orderMapping->ecom_id,
            'erp_order_id'        => $orderMapping->erp_id,
            'shopify_location_id' => $fulfillment['shopify_location_id']
                ?? $fulfillment['location_id']
                ?? $defaultLocation,
        ]);       
    }

    /** @param array<string, mixed> $fulfillment */
    private function refreshDispatchFulfillmentPayload(array $fulfillment, SyncMapping $orderMapping): void
    {
        \App\Services\Sync\SyncPayloadStore::put(
            'dispatch',
            'ecom',
            (string) ($fulfillment['id'] ?? ''),
            $this->enrichDispatchFulfillmentPayload($fulfillment, $orderMapping)
        );
    }

    /** @param array<string, mixed> $picking */
    private function refreshDispatchPickingPayload(array $picking, SyncMapping $orderMapping): void
    {
        \App\Services\Sync\SyncPayloadStore::put(
            'dispatch',
            'erp',
            (string) $picking['id'],
            $this->enrichDispatchPickingPayload($picking, $orderMapping->ecom_id)
        );
    }

    private function setOrderDispatchInfoMessage(?string $erpOrderId, string $message): void
    {
        if ($erpOrderId === null || $erpOrderId === '') {
            return;
        }

        SyncMapping::whereIn('entity_type', ['order', 'sales_order'])
            ->where('erp_id', (string) $erpOrderId)
            ->update(['sync_message' => $message]);
    }

    private function findOrderMappingByEcomId(string $ecomId): ?SyncMapping
    {
        $ids = $this->normalizeEcomOrderIds($ecomId);
        if ($ids === []) {
            return null;
        }

        $mappings = SyncMapping::whereIn('entity_type', ['order', 'sales_order'])
            ->whereIn('ecom_id', $ids)
            ->orderByDesc('last_synced_at')
            ->get();

        if ($mappings->isEmpty()) {
            return null;
        }

        return $mappings->first(fn (SyncMapping $mapping) => $mapping->hasPayload())
            ?? $mappings->first();
    }

    /** @return array<string, mixed> */
    private function fetchEcomOrderForPush(string $ecomId): array
    {
        $ecom      = app(EcomInterface::class);
        $lastError = null;

        foreach ($this->normalizeEcomOrderIds($ecomId) as $id) {
            try {
                $order = $ecom->getOrder($id);
                if (!empty($order) && is_array($order)) {
                    return $order;
                }
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                Log::warning("OrdersController: getOrder({$id}) failed: " . $e->getMessage());
            }
        }

        throw new \RuntimeException(
            $lastError
                ? 'Could not load order from ' . $this->settings->ecomDisplayName() . ': ' . $lastError
                : 'Could not load order from ' . $this->settings->ecomDisplayName() . " (ID: {$ecomId})."
        );
    }

    /** @param array<string, mixed> $order */
    private function persistFetchedOrder(string $ecomId, array $order): ?SyncMapping
    {
        $driver     = $this->settings->ecomDriver();
        $resolvedId = (string) ($order['id'] ?? $ecomId);

        $existing = SyncMapping::whereIn('entity_type', ['order', 'sales_order'])
            ->where(function ($q) use ($ecomId, $resolvedId) {
                $q->whereIn('ecom_id', $this->normalizeEcomOrderIds($ecomId));
                if ($resolvedId !== '') {
                    $q->orWhere('ecom_id', $resolvedId);
                }
            })
            ->orderByDesc('last_synced_at')
            ->first();

        SyncEntityState::markFetched(
            'sales_order',
            [
                'ecom_id'     => $resolvedId,
                'ecom_driver' => $driver,
            ],
            $order,
            $existing,
            'ecom_to_erp',
            fn (array $d) => $d['updated_at'] ?? $d['updatedAt'] ?? null,
        );

        SyncMapping::whereIn('entity_type', ['order', 'sales_order'])
            ->where('ecom_id', $resolvedId)
            ->update(['ecom_handle' => $order['name'] ?? null]);

        return $this->findOrderMappingByEcomId($resolvedId)
            ?? $this->findOrderMappingByEcomId($ecomId);
    }

    /** @return array<string, mixed>|null */
    private function resolveOrderPushPayload(SyncMapping $mapping): ?array
    {
        $payload = $mapping->payload();
        if (!empty($payload) && is_array($payload)) {
            return $payload;
        }

        $ecomId = (string) ($mapping->ecom_id ?? '');
        if ($ecomId === '') {
            return null;
        }

        try {
            $fresh = $this->fetchEcomOrderForPush($ecomId);

            SyncEntityState::markFetched(
                'sales_order',
                array_filter([
                    'ecom_id'     => $ecomId,
                    'ecom_driver' => $mapping->ecom_driver ?: $this->settings->ecomDriver(),
                ]),
                $fresh,
                $mapping->fresh(),
                $mapping->last_sync_direction ?: 'ecom_to_erp'
            );

            return $this->findOrderMappingByEcomId($ecomId)?->payload() ?? $fresh;
        } catch (\Throwable $e) {
            Log::warning("OrdersController: could not reload order payload for ecom#{$ecomId}: " . $e->getMessage());

            return null;
        }
    }

    private function markOrderPushFailed(SyncMapping $mapping, string $message): void
    {
        SyncEntityState::markFailed('sales_order', array_filter([
            'erp_id'      => $mapping->erp_id,
            'erp_driver'  => $mapping->erp_driver ?? $this->settings->erpDriver(),
            'ecom_id'     => $mapping->ecom_id,
            'ecom_driver' => $mapping->ecom_driver,
        ]), $message);
    }

    // ── Sales Info (order sync detail) ────────────────────────────────────

    public function salesInfo(int|string $erpId)
    {
        $mapping = SyncMapping::whereIn('entity_type', ['order', 'sales_order'])
            ->where('erp_id', (string) $erpId)
            ->first();

        return $this->renderSalesInfo($mapping, (string) $erpId);
    }

    // ── Sales Info by Ecom ID (for orders not yet pushed to ERP) ─────────

    public function salesInfoByEcom(string $ecomId)
    {
        $mapping = SyncMapping::whereIn('entity_type', ['order', 'sales_order'])
            ->where('ecom_id', $ecomId)
            ->first();

        return $this->renderSalesInfo($mapping, $mapping?->erp_id ?? $ecomId);
    }

    /** @param  SyncMapping|null  $mapping */
    private function renderSalesInfo($mapping, string $displayId)
    {
        $syncMode = $this->settings->salesOrderSyncMode();
        $isEcomToErp = $syncMode === 'ecom_to_erp'
            || ($syncMode === 'bidirectional' && ($mapping?->last_sync_direction ?? 'ecom_to_erp') === 'ecom_to_erp');

        $logIds = array_filter(array_unique([
            $displayId,
            $mapping?->ecom_id,
            $mapping?->erp_id,
        ]));

        $pushDirections = $isEcomToErp
            ? ['ecom_to_erp', 'shopify_to_erp', 'shopify_to_odoo']
            : ['erp_to_ecom', 'odoo_to_shopify', 'erp_to_shopify'];

        $syncLog = SyncLog::whereIn('entity_type', ['order', 'sales_order'])
            ->whereIn('entity_id', $logIds)
            ->whereIn('direction', $pushDirections)
            ->whereIn('status', ['success', 'failed'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        $meta       = $mapping ? ($mapping->payload() ?? []) : [];
        $sourceData = is_array($meta) ? $meta : [];

        $mappedPayload = null;
        $wirePayload   = null;
        $ecomPayload   = null;
        $ecomResponse  = null;
        if ($syncLog) {
            $mappedPayload = $this->resolveOrderMappedPayload($syncLog->request_payload);
            $wirePayload   = $this->resolveOrderWirePayload($syncLog->request_payload);
            $ecomPayload   = $wirePayload ?? $mappedPayload;
            $ecomResponse  = $this->resolveOrderDisplayResponse($syncLog->response_payload, $isEcomToErp);
        }

        $erpHost = rtrim(config('odoo.url', config('erp.url', '')), '/');

        $erpDisplayName  = $this->settings->erpDisplayName();
        $ecomDisplayName = $this->settings->ecomDisplayName();
        $erpId           = $mapping?->erp_id ?? $displayId;
        $canPost         = $mapping && $isEcomToErp && SyncEntityState::needsPush($mapping);

        return view('dashboard.orders-info', compact(
            'mapping', 'erpId', 'syncMode', 'syncLog', 'displayId',
            'sourceData', 'ecomPayload', 'ecomResponse', 'mappedPayload', 'wirePayload',
            'erpHost', 'canPost', 'erpDisplayName', 'ecomDisplayName', 'isEcomToErp'
        ));
    }

    // ── Dispatch Info ─────────────────────────────────────────────────────

    public function dispatchInfo(int|string $erpId)
    {
        return $this->renderDispatchInfo((string) $erpId);
    }

    private function renderDispatchInfo(string $erpId)
    {
        $orderMapping = SyncMapping::whereIn('entity_type', ['order', 'sales_order'])
            ->where('erp_id', $erpId)
            ->first();

        $dispatchMappings = SyncMapping::where('entity_type', 'dispatch')
            ->where(function ($q) use ($erpId, $orderMapping) {
                $q->where('erp_reference', $erpId);
                if ($orderMapping?->ecom_id) {
                    $q->orWhere('ecom_id', $orderMapping->ecom_id);
                }
            })
            ->orderByDesc('last_synced_at')
            ->get();

        $dispatchMapping = $dispatchMappings->first(fn (SyncMapping $m) => $m->hasPayload())
            ?? $dispatchMappings->first();

        $sourceData = $dispatchMapping?->payload() ?? [];
        if (!is_array($sourceData)) {
            $sourceData = [];
        }

        $pickingId = $dispatchMapping?->erp_id;
        $fulfillmentId = $dispatchMapping?->ecom_id;
        $dispatchDirection = $dispatchMapping?->last_sync_direction
            ?? ($dispatchMapping?->payloadSide() === 'ecom' ? 'ecom_to_erp' : 'erp_to_ecom');

        $syncLog = null;
        if ($pickingId || $fulfillmentId) {
            $candidates = SyncLog::where('entity_type', 'dispatch')
                ->where(function ($q) use ($pickingId, $fulfillmentId, $erpId) {
                    if ($pickingId) {
                        $q->where('entity_id', (string) $pickingId);
                    }
                    if ($fulfillmentId) {
                        $method = $pickingId ? 'orWhere' : 'where';
                        $q->{$method}('entity_id', (string) $fulfillmentId);
                    }
                    $q->orWhere('entity_id', (string) $erpId);
                })
                ->whereIn('status', ['success', 'failed'])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(20)
                ->get();

            $syncLog = $candidates->first(function (SyncLog $log) use ($pickingId, $fulfillmentId) {
                if ($pickingId && (string) $log->entity_id === (string) $pickingId) {
                    return true;
                }

                if ($fulfillmentId && (string) $log->entity_id === (string) $fulfillmentId) {
                    return true;
                }

                $decoded = json_decode($log->request_payload ?? '', true);

                if (!is_array($decoded)) {
                    return false;
                }

                if ($pickingId && (string) ($decoded['picking_id'] ?? '') === (string) $pickingId) {
                    return true;
                }

                return $fulfillmentId
                    && (string) ($decoded['fulfillment_id'] ?? '') === (string) $fulfillmentId;
            });
        }

        $mappedPayload = null;
        $wirePayload   = null;
        $outgoingPayload = null;
        $targetResponse  = null;
        if ($syncLog?->request_payload) {
            $decoded = json_decode($syncLog->request_payload, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $mappedPayload = $decoded['mapped_payload'] ?? null;
                if ($dispatchDirection === 'ecom_to_erp') {
                    $wirePayload = $decoded['wire_payload'] ?? null;
                } else {
                    $wirePayload = $this->flattenDispatchWireInput($decoded['wire_input'] ?? null);
                }
                $outgoingPayload = $wirePayload ?? $mappedPayload ?? $decoded;
            } else {
                $outgoingPayload = $syncLog->request_payload;
            }
        }
        if ($syncLog?->response_payload) {
            $decoded = json_decode($syncLog->response_payload, true);
            $targetResponse = json_last_error() === JSON_ERROR_NONE ? $decoded : $syncLog->response_payload;
        }

        if ($wirePayload === null && is_array($targetResponse) && !empty($targetResponse['odoo_calls'])) {
            $wirePayload = $targetResponse['odoo_calls'];
        }

        $erpDisplayName  = $this->settings->erpDisplayName();
        $ecomDisplayName = $this->settings->ecomDisplayName();
        $displayId       = $dispatchDirection === 'ecom_to_erp'
            ? ($fulfillmentId ?? $erpId)
            : ($dispatchMapping?->erp_id ?? $erpId);
        $title           = ($orderMapping?->ecom_handle ?? 'Order #' . $erpId) . ' — Dispatch';

        return view('dashboard.dispatch-info', compact(
            'orderMapping', 'dispatchMapping', 'dispatchMappings', 'syncLog',
            'sourceData', 'outgoingPayload', 'targetResponse', 'mappedPayload', 'wirePayload',
            'erpDisplayName', 'ecomDisplayName', 'displayId', 'title', 'erpId', 'dispatchDirection'
        ));
    }

    // ── Fetch Orders from ERP (erp_to_ecom mode: Odoo → local → Shopify) ────
    // Fetch only — stores orders as pending. Use Post Sales to push to Shopify.

    public function fetch(Request $request)
    {
        if (!$this->settings->allowsFetchFromErp('sales_order')) {
            return $this->syncActionResponse(
                $request,
                'error',
                'Sales order sync is set to ' . $this->settings->ecomDisplayName() . ' → ' . $this->settings->erpDisplayName()
                    . '. Use Fetch from ' . $this->settings->ecomDisplayName() . ' (pull), not Fetch from ' . $this->settings->erpDisplayName() . '.',
                redirectRoute: 'dashboard.orders'
            );
        }

        try {
            $erp       = app(ErpInterface::class);
            $state     = SyncQueueState::forType('orders');
            $writeDate = $state->getErpWriteDate();

            $orders = $erp->getOrdersModifiedSince($writeDate, true); // onlyErpOrigin=true

            if (empty($orders)) {
                $cursor = date('Y-m-d H:i:s', strtotime($writeDate) + 1);
                $state->update(['last_erp_write_date' => $cursor, 'last_poll_at' => now(), 'notes' => 'nothing_changed']);
                return $this->syncActionResponse(
                    $request,
                    'info',
                    'No new or updated orders in ' . $this->settings->erpDisplayName() . ' since last sync.',
                    ['fetched' => 0],
                    redirectRoute: 'dashboard.orders'
                );
            }

            $fetched         = 0;
            $skipped         = 0;
            $latestWriteDate = $writeDate;

            foreach ($orders as $orderSummary) {
                $erpId = (string) ($orderSummary['id'] ?? '');

                if ($erpId === '' || ($orderSummary['state'] ?? '') === 'cancel') {
                    $skipped++;
                    continue;
                }

                $existing = SyncMapping::whereIn('entity_type', ['order', 'sales_order'])
                    ->where('erp_id', $erpId)
                    ->where('erp_driver', $this->settings->erpDriver())
                    ->first();

                $writeDateReader = fn (array $d) => $d['write_date'] ?? null;

                if ($existing
                    && !SyncEntityState::changedSinceLastSync($existing, $orderSummary, $writeDateReader)
                    && $existing->hasPayload()) {
                    $skipped++;
                    if (($orderSummary['write_date'] ?? null) > $latestWriteDate) {
                        $latestWriteDate = $orderSummary['write_date'];
                    }
                    continue;
                }

                $order = app(OrderSyncService::class)->prepareErpOrderForSync($erpId, $orderSummary);

                if ($existing && SyncEntityState::isOdooDeliveryOnlyRefresh($existing, $order)) {
                    \App\Services\Sync\SyncPayloadStore::put(
                        'sales_order',
                        'erp',
                        $erpId,
                        $order
                    );
                    SyncMapping::whereIn('entity_type', ['order', 'sales_order'])
                        ->where('erp_id', $erpId)
                        ->update([
                            'last_synced_at'  => now(),
                            'ecom_updated_at' => SyncEntityState::normalizeTimestamp($orderSummary['write_date'] ?? null),
                            'ecom_handle'     => $order['name'] ?? null,
                        ]);
                    $skipped++;
                    if (($orderSummary['write_date'] ?? null) > $latestWriteDate) {
                        $latestWriteDate = $orderSummary['write_date'];
                    }
                    continue;
                }

                SyncEntityState::markFetched(
                    'sales_order',
                    [
                        'erp_id'     => $erpId,
                        'erp_driver' => $this->settings->erpDriver(),
                    ],
                    $order,
                    $existing,
                    'erp_to_ecom',
                    $writeDateReader
                );

                SyncMapping::whereIn('entity_type', ['order', 'sales_order'])
                    ->where('erp_id', $erpId)
                    ->update(['ecom_handle' => $order['name'] ?? null]);

                $fetched++;
                if (($order['write_date'] ?? null) > $latestWriteDate) {
                    $latestWriteDate = $order['write_date'];
                }
            }

            // Advance cursor
            if ($latestWriteDate !== $state->getErpWriteDate()) {
                $cursor = date('Y-m-d H:i:s', strtotime($latestWriteDate) + 1);
                $state->update(['last_erp_write_date' => $cursor, 'last_poll_at' => now()]);
            }

            if ($fetched === 0) {
                return $this->syncActionResponse(
                    $request,
                    'info',
                    'No new orders in ' . $this->settings->erpDisplayName()
                        . ($skipped > 0 ? " ({$skipped} unchanged skipped)." : '.'),
                    ['fetched' => 0, 'skipped' => $skipped],
                    redirectRoute: 'dashboard.orders'
                );
            }

            $skipNote = $skipped > 0 ? " ({$skipped} unchanged skipped)" : '';
            return $this->syncActionResponse(
                $request,
                'success',
                "{$fetched} order(s) fetched{$skipNote} from " . $this->settings->erpDisplayName()
                    . '. Click Post to ' . $this->settings->ecomDisplayName() . ' to push.',
                ['fetched' => $fetched, 'skipped' => $skipped, 'refresh_table' => true],
                redirectRoute: 'dashboard.orders'
            );

        } catch (\Throwable $e) {
            return $this->syncActionResponse(
                $request,
                'error',
                'Fetch orders failed: ' . $e->getMessage(),
                status: 500,
                redirectRoute: 'dashboard.orders'
            );
        }
    }

    // ── Fetch Sales: pull from Ecom → cache only (no ERP post) ─────────────

    public function pull(Request $request)
    {
        if (!$this->settings->allowsFetchFromEcom('sales_order')) {
            return $this->syncActionResponse(
                $request,
                'error',
                'Sales order sync is set to ' . $this->settings->erpDisplayName() . ' → ' . $this->settings->ecomDisplayName()
                    . '. Use Fetch from ' . $this->settings->erpDisplayName() . ', not Fetch from ' . $this->settings->ecomDisplayName() . '.',
                redirectRoute: 'dashboard.orders'
            );
        }

        try {
            FetchEcomOrdersOnlyJob::dispatchSync();

            $notes = SyncQueueState::forType('orders')->fresh()->notes ?? '';

            if ($notes === 'nothing_changed') {
                return $this->syncActionResponse(
                    $request,
                    'info',
                    'No new or updated orders from ' . $this->settings->ecomDisplayName() . ' since last sync.',
                    ['fetched' => 0],
                    redirectRoute: 'dashboard.orders'
                );
            }

            preg_match('/Fetched: (\d+)/', $notes, $f);
            preg_match('/Skipped: (\d+)/', $notes, $sk);

            $fetched = (int) ($f[1] ?? 0);
            $skipped = (int) ($sk[1] ?? 0);

            if ($fetched === 0) {
                return $this->syncActionResponse(
                    $request,
                    'info',
                    'No new orders from ' . $this->settings->ecomDisplayName() . ' since last sync.'
                        . ($skipped > 0 ? " ({$skipped} already synced)" : ''),
                    ['fetched' => 0, 'skipped' => $skipped],
                    redirectRoute: 'dashboard.orders'
                );
            }

            $skipNote = $skipped > 0 ? " ({$skipped} already synced skipped)" : '';
            return $this->syncActionResponse(
                $request,
                'success',
                "{$fetched} order(s) fetched{$skipNote} from " . $this->settings->ecomDisplayName()
                    . '. Click Post to ' . $this->settings->erpDisplayName() . ' to push.',
                ['fetched' => $fetched, 'skipped' => $skipped, 'refresh_table' => true],
                redirectRoute: 'dashboard.orders'
            );
        } catch (\Throwable $e) {
            return $this->syncActionResponse(
                $request,
                'error',
                'Fetch from ' . $this->settings->ecomDisplayName() . ' failed: ' . $e->getMessage(),
                status: 500,
                redirectRoute: 'dashboard.orders'
            );
        }
    }
	
	

    // ── Post Sales: direction-aware push ─────────────────────────────────
    // ecom_to_erp: push pending Shopify orders → Odoo
    // erp_to_ecom: push pending Odoo orders → Shopify

    public function postSales(Request $request)
    {
        $syncMode      = $this->settings->salesOrderSyncMode();
        $pushDirection = $syncMode === 'bidirectional'
            ? ($request->input('direction') === 'ecom_to_erp' ? 'ecom_to_erp' : 'erp_to_ecom')
            : $syncMode;

        $pending = SyncMapping::whereIn('entity_type', ['order', 'sales_order'])
            ->where('last_sync_direction', $pushDirection)
            ->whereIn('ecom_status', SyncEntityState::PUSHABLE_STATUSES)
            ->when($pushDirection === 'ecom_to_erp', fn ($q) => $q->whereNotNull('ecom_id'))
            ->when($pushDirection === 'erp_to_ecom', fn ($q) => $q->whereNotNull('erp_id'))
            ->get();

        if ($pending->isEmpty()) {
            $fetchFrom = $pushDirection === 'erp_to_ecom'
                ? $this->settings->erpDisplayName()
                : $this->settings->ecomDisplayName();
            $pushTo = $pushDirection === 'erp_to_ecom'
                ? $this->settings->ecomDisplayName()
                : $this->settings->erpDisplayName();

            return $this->syncActionResponse(
                $request,
                'info',
                "No orders to push to {$pushTo}. Run Fetch from {$fetchFrom} first.",
                ['pushed' => 0],
                redirectRoute: 'dashboard.orders'
            );
        }

        if ($pushDirection === 'erp_to_ecom') {
            $orderSync = app(OrderSyncService::class);
            $pushed    = 0;
            $failed    = 0;
            $lastError = null;

            foreach ($pending as $mapping) {
                $order = $mapping->payload();
                if (empty($order)) {
                    $lastError = 'No fetched order data. Run Fetch first.';
                    $this->markOrderPushFailed($mapping, $lastError);
                    $failed++;
                    continue;
                }

                try {
                    $orderSync->syncErpOrderToEcom($order);
                    $pushed++;
                } catch (\Throwable $e) {
                    $lastError = $this->syncErrorMessage($e);
                    $this->markOrderPushFailed($mapping, $lastError);
                    Log::error("postSales erp_to_ecom: failed for erp#{$mapping->erp_id}: " . $e->getMessage());
                    $failed++;
                }
            }

            $msg = "{$pushed} order(s) pushed to " . $this->settings->ecomDisplayName() . '.';
            if ($failed) {
                $msg .= " {$failed} failed — check Message column.";
                if ($pushed === 0 && $lastError) {
                    $msg .= ' ' . $lastError;
                }
            }

            $level = $pushed > 0
                ? ($failed > 0 ? 'warning' : 'success')
                : ($failed > 0 ? 'error' : 'success');

            return $this->syncActionResponse(
                $request,
                $level,
                $msg,
                ['pushed' => $pushed, 'failed' => $failed, 'refresh_table' => true],
                status: $level === 'error' ? 422 : 200,
                redirectRoute: 'dashboard.orders'
            );
        }

        $orderSync = app(OrderSyncService::class);
        $pushed    = 0;
        $failed    = 0;
        $skipped   = 0;
        $lastError = null;

        foreach ($pending as $mapping) {
            $order = $mapping->payload();
            if (empty($order) || !is_array($order)) {
                $this->markOrderPushFailed($mapping, 'No fetched order data. Run Fetch from ' . $this->settings->ecomDisplayName() . ' first.');
                $failed++;
                continue;
            }

            if (SyncEntityState::isShopifyFulfillmentOnlyRefresh($mapping, $order)) {
                SyncEntityState::markSynced('sales_order', array_filter([
                    'erp_id'      => $mapping->erp_id,
                    'erp_driver'  => $mapping->erp_driver ?? $this->settings->erpDriver(),
                    'ecom_id'     => $mapping->ecom_id,
                    'ecom_driver' => $mapping->ecom_driver,
                ]), $order['updated_at'] ?? $order['updatedAt'] ?? null);
                $skipped++;
                continue;
            }

            try {
                $orderSync->syncEcomOrderToErp($order);
                $pushed++;
            } catch (\Throwable $e) {
                $lastError = $this->syncErrorMessage($e);
                $this->markOrderPushFailed($mapping, $lastError);
                Log::error("postSales ecom_to_erp: failed for ecom#{$mapping->ecom_id}: " . $e->getMessage());
                $failed++;
            }
        }

        if ($pushed === 0 && $failed > 0) {
            return $this->syncActionResponse(
                $request,
                'error',
                "{$failed} order(s) failed to post — open Sales Info for details."
                    . ($lastError ? ' ' . $lastError : ''),
                ['pushed' => 0, 'failed' => $failed, 'skipped' => $skipped, 'refresh_table' => true, 'last_error' => $lastError],
                redirectRoute: 'dashboard.orders'
            );
        }

        $msg = "{$pushed} order(s) posted to " . $this->settings->erpDisplayName() . '.';
        if ($skipped > 0) {
            $msg .= " {$skipped} already synced (fulfilled on Shopify — skipped).";
        }
        if ($failed) {
            $msg .= " {$failed} failed — check Sales Info.";
        }

        return $this->syncActionResponse(
            $request,
            $failed ? 'warning' : 'success',
            $msg,
            ['pushed' => $pushed, 'failed' => $failed, 'skipped' => $skipped, 'refresh_table' => true],
            redirectRoute: 'dashboard.orders'
        );
    }
	
	// ── Push single ERP order → Ecom (erp_to_ecom, called from Tools button) ──
    public function push(Request $request, int|string $erpId)
    {
        $ctx     = $this->listingContext($request);
        $mapping = SyncMapping::whereIn('entity_type', ['order', 'sales_order'])
            ->where('erp_id', (string) $erpId)
            ->first();

        if ($mapping && $mapping->ecom_id && !SyncEntityState::needsPush($mapping)) {
            return $this->syncActionResponse(
                $request,
                'info',
                "Order #{$erpId} already pushed to " . $this->settings->ecomDisplayName() . " (#{$mapping->ecom_id}).",
                $mapping ? $this->orderRowPayload($ctx, $mapping) : [],
                redirectRoute: 'dashboard.orders'
            );
        }

        try {
            app(OrderSyncService::class)->syncErpOrderToEcom(
                $mapping?->payload() ?? app(ErpInterface::class)->getOrder($erpId) ?? []
            );
            $mapping = SyncMapping::whereIn('entity_type', ['order', 'sales_order'])
                ->where('erp_id', (string) $erpId)
                ->first();

            return $this->syncActionResponse(
                $request,
                'success',
                "Order #{$erpId} pushed to " . $this->settings->ecomDisplayName() . '.',
                $mapping ? $this->orderRowPayload($ctx, $mapping) : ['refresh_table' => true],
                redirectRoute: 'dashboard.orders'
            );
        } catch (\Throwable $e) {
            if ($mapping) {
                $this->markOrderPushFailed($mapping, $this->syncErrorMessage($e));
            }
            $mapping = SyncMapping::whereIn('entity_type', ['order', 'sales_order'])
                ->where('erp_id', (string) $erpId)
                ->first();

            return $this->syncActionResponse(
                $request,
                'error',
                'Push failed: ' . $this->syncErrorMessage($e),
                $mapping ? $this->orderRowPayload($ctx, $mapping) : ['refresh_table' => true],
                status: 422,
                redirectRoute: 'dashboard.orders'
            );
        }
    }

	// ── Post single order to ERP (manual) ────────────────────────────────

    // ── Post single order — direction-aware ─────────────────────────────
    public function postSingle(Request $request, string $ecomId)
    {
        $ecomId = trim($ecomId);
        if ($ecomId === '' || $ecomId === '0') {
            return $this->syncActionResponse(
                $request,
                'error',
                'Invalid order ID. Refresh the orders page and try again.',
                redirectRoute: 'dashboard.orders'
            );
        }

        $syncMode = $this->settings->salesOrderSyncMode();
        $ctx      = $this->listingContext($request);
        $mapping  = $this->findOrderMappingByEcomId($ecomId);
        $order    = $mapping ? $this->resolveOrderPushPayload($mapping) : null;

        if (empty($order)) {
            try {
                $order   = $this->fetchEcomOrderForPush($ecomId);
                $mapping = $this->persistFetchedOrder($ecomId, $order) ?? $mapping;
            } catch (\Throwable $e) {
                return $this->syncActionResponse(
                    $request,
                    'error',
                    $e->getMessage(),
                    redirectRoute: 'dashboard.orders'
                );
            }
        }

        if (empty($order) || !is_array($order)) {
            return $this->syncActionResponse(
                $request,
                'error',
                'No data for this order. Run Fetch from ' . $this->settings->ecomDisplayName() . ' first.',
                $mapping ? $this->orderRowPayload($ctx, $mapping) : null,
                redirectRoute: 'dashboard.orders'
            );
        }

        if (!$mapping) {
            $mapping = $this->findOrderMappingByEcomId((string) ($order['id'] ?? $ecomId));
        }

        $pushErp = $syncMode === 'ecom_to_erp'
            || ($syncMode === 'bidirectional' && ($mapping?->last_sync_direction ?? 'ecom_to_erp') === 'ecom_to_erp');

        if ($pushErp) {
            if ($mapping && !SyncEntityState::needsPush($mapping) && $mapping->erp_id) {
                return $this->syncActionResponse(
                    $request,
                    'info',
                    "Order {$ecomId} is already synced and unchanged.",
                    $this->orderRowPayload($ctx, $mapping),
                    redirectRoute: 'dashboard.orders'
                );
            }

            try {
                app(OrderSyncService::class)->syncEcomOrderToErp($order);
                $mapping = $this->findOrderMappingByEcomId($ecomId)
                    ?? $this->findOrderMappingByEcomId((string) ($order['id'] ?? ''))
                    ?? $mapping?->fresh();

                return $this->syncActionResponse(
                    $request,
                    'success',
                    "Order {$ecomId} posted to " . $this->settings->erpDisplayName()
                        . ($mapping->erp_id ? " (#{$mapping->erp_id})." : '.'),
                    $this->orderRowPayload($ctx, $mapping),
                    redirectRoute: 'dashboard.orders'
                );
            } catch (\Throwable $e) {
                if ($mapping) {
                    $this->markOrderPushFailed($mapping, $this->syncErrorMessage($e));
                }
                $mapping = $this->findOrderMappingByEcomId($ecomId)
                    ?? $this->findOrderMappingByEcomId((string) ($order['id'] ?? ''))
                    ?? $mapping?->fresh();

                return $this->syncActionResponse(
                    $request,
                    'error',
                    'Push failed: ' . $this->syncErrorMessage($e),
                    $this->orderRowPayload($ctx, $mapping),
                    status: 500,
                    redirectRoute: 'dashboard.orders'
                );
            }
        }

        if (!$mapping?->erp_id) {
            return $this->syncActionResponse(
                $request,
                'error',
                'Order has no ERP ID. Cannot push to ' . $this->settings->ecomDisplayName() . '.',
                redirectRoute: 'dashboard.orders'
            );
        }

        try {
            app(OrderSyncService::class)->syncErpOrderToEcom($mapping->payload() ?? []);
            $mapping->refresh();

            return $this->syncActionResponse(
                $request,
                'success',
                'Order posted to ' . $this->settings->ecomDisplayName() . '.',
                $this->orderRowPayload($ctx, $mapping),
                redirectRoute: 'dashboard.orders'
            );
        } catch (\Throwable $e) {
            $this->markOrderPushFailed($mapping, $this->syncErrorMessage($e));
            $mapping->refresh();

            return $this->syncActionResponse(
                $request,
                'error',
                'Push failed: ' . $this->syncErrorMessage($e),
                $this->orderRowPayload($ctx, $mapping),
                status: 422,
                redirectRoute: 'dashboard.orders'
            );
        }
    }

    /** Alias for orders-info "Sync from E-commerce" button. */
    public function syncBack(Request $request, string $ecomId)
    {
        return $this->postSingle($request, $ecomId);
    }

    // ── Fetch Dispatch: ERP → fetch fulfillments ──────────────────────────

    public function fetchDispatch(Request $request)
    {
        if (!$this->settings->allowsDispatchFetch()) {
            return $this->syncActionResponse(
                $request,
                'error',
                'Dispatch fetch is not available for the current sales order sync direction.',
                redirectRoute: 'dashboard.orders'
            );
        }

        $flow = $this->settings->dispatchFlowForListing($request->input('direction'));

        return $flow === 'ecom_to_erp'
            ? $this->fetchDispatchFromEcom($request)
            : $this->fetchDispatchFromErp($request);
    }

    private function fetchDispatchFromErp(Request $request)
    {
        if (!$this->settings->allowsDispatchErpToEcom()) {
            return $this->syncActionResponse(
                $request,
                'error',
                'Dispatch fetch from ' . $this->settings->erpDisplayName()
                    . ' is only used when sales orders sync '
                    . $this->settings->erpDisplayName() . ' → ' . $this->settings->ecomDisplayName() . '.',
                redirectRoute: 'dashboard.orders'
            );
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
                $saleOrderId = $this->resolvePickingSaleOrderId($picking);

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

                if ($existingMap && $this->dispatchPickingUnchanged($existingMap, $picking)) {
                    $skipped++;
                    if ($dateDone && (!$latest || $dateDone > $latest)) {
                        $latest = $dateDone;
                    }
                    continue;
                }

                if ($existingMap && in_array($existingMap->ecom_status, SyncEntityState::DISPATCH_PUSHABLE, true)) {
                    $this->refreshDispatchPickingPayload($picking, $orderMapping);
                    $this->clearOrderDispatchMessage($saleOrderId);
                    $skipped++;
                    if ($dateDone && (!$latest || $dateDone > $latest)) {
                        $latest = $dateDone;
                    }
                    continue;
                }

                if ($existingMap && $existingMap->ecom_status === SyncEntityState::DISPATCH_SENT) {
                    $this->persistDispatchPicking($picking, $orderMapping, SyncEntityState::DISPATCH_UPDATED);
                    $this->clearOrderDispatchMessage($saleOrderId);
                    $fetched++;
                    if ($dateDone && (!$latest || $dateDone > $latest)) {
                        $latest = $dateDone;
                    }
                    continue;
                }

                $this->persistDispatchPicking($picking, $orderMapping, SyncEntityState::DISPATCH_PENDING);
                $this->clearOrderDispatchMessage($saleOrderId);
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
                return $this->syncActionResponse(
                    $request,
                    'info',
                    'No new fulfillments to fetch from ' . $this->settings->erpDisplayName() . $reason,
                    ['fetched' => 0, 'skipped' => $skipped],
                    redirectRoute: 'dashboard.orders'
                );
            }

            $skipNote = $skipped > 0 ? " ({$skipped} already dispatched skipped)" : '';
            return $this->syncActionResponse(
                $request,
                'success',
                "{$fetched} dispatch(es) fetched{$skipNote}. Click Post Dispatch to push to " . $this->settings->ecomDisplayName() . '.',
                ['fetched' => $fetched, 'skipped' => $skipped, 'refresh_table' => true],
                redirectRoute: 'dashboard.orders'
            );

        } catch (\Throwable $e) {
            return $this->syncActionResponse(
                $request,
                'error',
                'Fetch dispatch failed: ' . $e->getMessage(),
                status: 500,
                redirectRoute: 'dashboard.orders'
            );
        }
    }

    private function fetchDispatchFromEcom(Request $request)
    {
        if (!$this->settings->allowsDispatchEcomToErp()) {
            return $this->syncActionResponse(
                $request,
                'error',
                'Dispatch fetch from ' . $this->settings->ecomDisplayName()
                    . ' is only used when sales orders sync '
                    . $this->settings->ecomDisplayName() . ' → ' . $this->settings->erpDisplayName() . '.',
                redirectRoute: 'dashboard.orders'
            );
        }

        try {
            $ecom = app(EcomInterface::class);

            $orders = SyncMapping::whereIn('entity_type', ['order', 'sales_order'])
                ->whereNotNull('ecom_id')
                ->whereNotNull('erp_id')
                ->where('erp_id', '!=', '0')
                ->where('last_sync_direction', 'ecom_to_erp')
                ->get();

            $fetched = 0;
            $skipped = 0;

            foreach ($orders as $orderMapping) {
                $fulfillments = $ecom->getFulfillmentsForOrder($orderMapping->ecom_id);

                foreach ($fulfillments as $fulfillment) {
                    if (($fulfillment['status'] ?? '') !== 'success') {
                        continue;
                    }

                    $fulfillmentId = (string) ($fulfillment['id'] ?? '');
                    if ($fulfillmentId === '') {
                        continue;
                    }

                    $existingMap = SyncMapping::where('entity_type', 'dispatch')
                        ->where('ecom_id', $fulfillmentId)
                        ->where('ecom_driver', $this->settings->ecomDriver())
                        ->first();

                    if ($existingMap && $this->dispatchFulfillmentUnchanged($existingMap, $fulfillment)) {
                        $skipped++;
                        continue;
                    }

                    if ($existingMap && in_array($existingMap->ecom_status, SyncEntityState::DISPATCH_PUSHABLE, true)) {
                        $this->refreshDispatchFulfillmentPayload($fulfillment, $orderMapping);
                        $this->clearOrderDispatchMessage((string) $orderMapping->erp_id);
                        $skipped++;
                        continue;
                    }

                    if ($existingMap && $existingMap->ecom_status === SyncEntityState::DISPATCH_SENT) {
                        $this->persistDispatchFulfillment($fulfillment, $orderMapping, SyncEntityState::DISPATCH_UPDATED);
                        $this->clearOrderDispatchMessage((string) $orderMapping->erp_id);
                        $fetched++;
                        continue;
                    }

                    $this->persistDispatchFulfillment($fulfillment, $orderMapping, SyncEntityState::DISPATCH_PENDING);
                    $this->clearOrderDispatchMessage((string) $orderMapping->erp_id);
                    $fetched++;
                }
            }

            if ($fetched === 0) {
                $reason = $skipped > 0
                    ? " ({$skipped} already dispatched or pending.)"
                    : '.';
                return $this->syncActionResponse(
                    $request,
                    'info',
                    'No new fulfillments to fetch from ' . $this->settings->ecomDisplayName() . $reason,
                    ['fetched' => 0, 'skipped' => $skipped],
                    redirectRoute: 'dashboard.orders'
                );
            }

            $skipNote = $skipped > 0 ? " ({$skipped} already dispatched skipped)" : '';

            return $this->syncActionResponse(
                $request,
                'success',
                "{$fetched} dispatch(es) fetched{$skipNote}. Click Post Dispatch to push to "
                    . $this->settings->erpDisplayName() . '.',
                ['fetched' => $fetched, 'skipped' => $skipped, 'refresh_table' => true],
                redirectRoute: 'dashboard.orders'
            );
        } catch (\Throwable $e) {
            return $this->syncActionResponse(
                $request,
                'error',
                'Fetch dispatch failed: ' . $e->getMessage(),
                status: 500,
                redirectRoute: 'dashboard.orders'
            );
        }
    }

    // ── Post Dispatch: push pending_dispatch mappings to Ecom ──────────────
    // Reads locally stored dispatch mappings (set by Fetch Dispatch) and
    // pushes each one to Shopify as a fulfillment. Independent of Odoo.

    public function postDispatch(Request $request)
    {
        if (!$this->settings->allowsDispatchPost()) {
            return $this->syncActionResponse(
                $request,
                'error',
                'Dispatch post is not available for the current sales order sync direction.',
                redirectRoute: 'dashboard.orders'
            );
        }

        $flow = $this->settings->dispatchFlowForListing($request->input('direction'));

        return $flow === 'ecom_to_erp'
            ? $this->postDispatchToErp($request)
            : $this->postDispatchToEcom($request);
    }

    private function postDispatchToEcom(Request $request)
    {
        if (!$this->settings->allowsDispatchErpToEcom()) {
            return $this->syncActionResponse(
                $request,
                'error',
                'Dispatch post to ' . $this->settings->ecomDisplayName()
                    . ' is for ' . $this->settings->erpDisplayName() . ' → ' . $this->settings->ecomDisplayName() . ' only.',
                redirectRoute: 'dashboard.orders'
            );
        }

        try {
            $pending = SyncMapping::where('entity_type', 'dispatch')
                ->whereIn('ecom_status', SyncEntityState::DISPATCH_PUSHABLE)
                ->where('last_sync_direction', 'erp_to_ecom')
                ->whereNotNull('erp_id')
                ->get();

            if ($pending->isEmpty()) {
                return $this->syncActionResponse(
                    $request,
                    'info',
                    'No pending dispatches. Run Fetch Dispatch first.',
                    ['pushed' => 0],
                    redirectRoute: 'dashboard.orders'
                );
            }

            $pushed  = 0;
            $failed  = 0;
            $lastError = null;

            foreach ($pending as $mapping) {
                $picking = $mapping->payload();

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
                    $mapping->update([
                        'ecom_status'    => SyncEntityState::DISPATCH_SENT,
                        'last_synced_at' => now(),
                        'sync_message'   => null,
                    ]);
                    $this->clearOrderDispatchMessage($mapping->erp_reference);
                    $pushed++;
                } catch (\Throwable $e) {
                    $lastError = $this->syncErrorMessage($e);
                    Log::error("postDispatch: failed for picking#{$mapping->erp_id}: " . $e->getMessage());
                    $mapping->update(['sync_message' => $lastError]);
                    if ($mapping->erp_reference) {
                        SyncMapping::whereIn('entity_type', ['order', 'sales_order'])
                            ->where('erp_id', (string) $mapping->erp_reference)
                            ->update(['sync_message' => $lastError]);
                    }
                    $failed++;
                }
            }

            if ($pushed === 0 && $failed > 0 && $lastError) {
                return $this->syncActionResponse(
                    $request,
                    'error',
                    'Push failed: ' . $lastError,
                    ['pushed' => $pushed, 'failed' => $failed, 'refresh_table' => true],
                    redirectRoute: 'dashboard.orders'
                );
            }

            $msg = "{$pushed} fulfillment(s) pushed to " . $this->settings->ecomDisplayName() . ".";
            if ($failed > 0) {
                $msg .= " {$failed} failed" . ($lastError ? ": {$lastError}" : ' — check logs.');
            }

            return $this->syncActionResponse(
                $request,
                $failed > 0 ? ($pushed > 0 ? 'warning' : 'error') : 'success',
                $msg,
                ['pushed' => $pushed, 'failed' => $failed, 'refresh_table' => true],
                redirectRoute: 'dashboard.orders'
            );

        } catch (\Throwable $e) {
            return $this->syncActionResponse(
                $request,
                'error',
                'Post dispatch failed: ' . $this->syncErrorMessage($e),
                status: 500,
                redirectRoute: 'dashboard.orders'
            );
        }
    }

    private function postDispatchToErp(Request $request)
    {
        if (!$this->settings->allowsDispatchEcomToErp()) {
            return $this->syncActionResponse(
                $request,
                'error',
                'Dispatch post to ' . $this->settings->erpDisplayName()
                    . ' is for ' . $this->settings->ecomDisplayName() . ' → ' . $this->settings->erpDisplayName() . ' only.',
                redirectRoute: 'dashboard.orders'
            );
        }

        try {
            $pending = SyncMapping::where('entity_type', 'dispatch')
                ->whereIn('ecom_status', SyncEntityState::DISPATCH_PUSHABLE)
                ->where('last_sync_direction', 'ecom_to_erp')
                ->whereNotNull('ecom_id')
                ->get();

            if ($pending->isEmpty()) {
                return $this->syncActionResponse(
                    $request,
                    'info',
                    'No pending dispatches. Run Fetch Dispatch first.',
                    ['pushed' => 0],
                    redirectRoute: 'dashboard.orders'
                );
            }

            $pushed    = 0;
            $failed    = 0;
            $lastError = null;

            foreach ($pending as $mapping) {
                $fulfillment = $mapping->payload();

                if (is_string($fulfillment)) {
                    $fulfillment = json_decode($fulfillment, true);
                }

                if (empty($fulfillment) || !is_array($fulfillment)) {
                    Log::warning("postDispatchToErp: invalid payload for dispatch mapping#{$mapping->id}, skipping.");
                    $failed++;
                    continue;
                }

                $fulfillment['erp_order_id']   = $fulfillment['erp_order_id'] ?? $mapping->erp_reference;
                $fulfillment['_ecom_order_id'] = $fulfillment['_ecom_order_id'] ?? null;

                try {
                    $pickingId = PushFulfillmentToErpJob::dispatchSync($fulfillment);
                    $mapping->update([
                        'erp_id'         => SyncEntityState::requireErpId($pickingId, 'Delivery note id'),
                        'ecom_status'    => SyncEntityState::DISPATCH_SENT,
                        'last_synced_at' => now(),
                        'sync_message'   => null,
                    ]);
                    $this->clearOrderDispatchMessage($mapping->erp_reference);
                    $pushed++;
                } catch (\Throwable $e) {
                    $lastError = $this->syncErrorMessage($e);
                    Log::error("postDispatchToErp: failed for fulfillment#{$mapping->ecom_id}: " . $e->getMessage());
                    $mapping->update(['sync_message' => $lastError]);
                    if ($mapping->erp_reference) {
                        SyncMapping::whereIn('entity_type', ['order', 'sales_order'])
                            ->where('erp_id', (string) $mapping->erp_reference)
                            ->update(['sync_message' => $lastError]);
                    }
                    $failed++;
                }
            }

            if ($pushed === 0 && $failed > 0 && $lastError) {
                return $this->syncActionResponse(
                    $request,
                    'error',
                    'Push failed: ' . $lastError,
                    ['pushed' => $pushed, 'failed' => $failed, 'refresh_table' => true],
                    redirectRoute: 'dashboard.orders'
                );
            }

            $msg = "{$pushed} fulfillment(s) pushed to " . $this->settings->erpDisplayName() . '.';
            if ($failed > 0) {
                $msg .= " {$failed} failed" . ($lastError ? ": {$lastError}" : ' — check logs.');
            }

            return $this->syncActionResponse(
                $request,
                $failed > 0 ? ($pushed > 0 ? 'warning' : 'error') : 'success',
                $msg,
                ['pushed' => $pushed, 'failed' => $failed, 'refresh_table' => true],
                redirectRoute: 'dashboard.orders'
            );
        } catch (\Throwable $e) {
            return $this->syncActionResponse(
                $request,
                'error',
                'Post dispatch failed: ' . $this->syncErrorMessage($e),
                status: 500,
                redirectRoute: 'dashboard.orders'
            );
        }
    }

    // ── Detail (show) ─────────────────────────────────────────────────────

    public function show(int|string $erpId)
    {
        return $this->salesInfo($erpId);
    }

    // ── Fetch Dispatch (single order) ─────────────────────────────────────
    public function fetchDispatchSingle(Request $request, int|string $erpId)
    {
        if (!$this->settings->allowsDispatchFetch()) {
            return $this->syncActionResponse(
                $request,
                'error',
                'Dispatch fetch is not available for the current sales order sync direction.',
                redirectRoute: 'dashboard.orders'
            );
        }

        $flow = $this->settings->dispatchFlowForListing($request->input('direction'));

        return $flow === 'ecom_to_erp'
            ? $this->fetchDispatchSingleFromEcom($request, $erpId)
            : $this->fetchDispatchSingleFromErp($request, $erpId);
    }

    private function fetchDispatchSingleFromErp(Request $request, int|string $erpId)
    {
        if (!$this->settings->allowsDispatchErpToEcom()) {
            return $this->syncActionResponse(
                $request,
                'error',
                'Dispatch fetch is for ' . $this->settings->erpDisplayName() . ' → ' . $this->settings->ecomDisplayName() . ' only.',
                redirectRoute: 'dashboard.orders'
            );
        }

        $ctx = $this->listingContext($request);

        try {
            $erp      = app(ErpInterface::class);
            $pickings = $erp->getFulfilledOrders();

            // Filter to pickings belonging to this sale order
            $matched = collect($pickings)->filter(function ($picking) use ($erpId) {
                return $this->erpIdsMatch($erpId, $this->resolvePickingSaleOrderId($picking));
            });

            if ($matched->isEmpty()) {
                return $this->syncActionResponse(
                    $request,
                    'info',
                    "No fulfilled deliveries found for order #{$erpId} in " . $this->settings->erpDisplayName() . '.',
                    redirectRoute: 'dashboard.orders'
                );
            }

            $orderMapping = SyncMapping::whereIn('entity_type', ['order', 'sales_order'])
                ->where('erp_id', (string) $erpId)
                ->first();

            if (!$orderMapping) {
                return $this->syncActionResponse(
                    $request,
                    'error',
                    "No ecom mapping found for order #{$erpId}. Post the sale first.",
                    redirectRoute: 'dashboard.orders'
                );
            }

            $stored    = 0;
            $refreshed = 0;
            $unchanged = 0;
            foreach ($matched as $picking) {
                $pickingId = (string) $picking['id'];

                $existing = SyncMapping::where('entity_type', 'dispatch')
                    ->where('erp_id', $pickingId)
                    ->first();

                if ($existing && $this->dispatchPickingUnchanged($existing, $picking)) {
                    $unchanged++;
                    continue;
                }

                if ($existing && in_array($existing->ecom_status, SyncEntityState::DISPATCH_PUSHABLE, true)) {
                    $this->refreshDispatchPickingPayload($picking, $orderMapping);
                    $refreshed++;
                    continue;
                }

                if ($existing && $existing->ecom_status === SyncEntityState::DISPATCH_SENT) {
                    $this->persistDispatchPicking($picking, $orderMapping, SyncEntityState::DISPATCH_UPDATED);
                    $stored++;
                    continue;
                }

                $this->persistDispatchPicking($picking, $orderMapping, SyncEntityState::DISPATCH_PENDING);
                $stored++;
            }

            if ($stored === 0 && $refreshed === 0) {
                if ($unchanged > 0) {
                    $this->setOrderDispatchInfoMessage((string) $erpId, SyncEntityState::DISPATCH_MSG_NO_UPDATE);
                }

                return $this->syncActionResponse(
                    $request,
                    'info',
                    $unchanged > 0
                        ? SyncEntityState::DISPATCH_MSG_NO_UPDATE . " for order #{$erpId}."
                        : "Dispatch for order #{$erpId} already fetched — no changes.",
                    $this->orderRowPayload($ctx, $orderMapping),
                    redirectRoute: 'dashboard.orders'
                );
            }

            $this->clearOrderDispatchMessage((string) $erpId);
            $orderMapping->refresh();

            $message = $stored > 0
                ? "Dispatch fetched for order #{$erpId}. Click Post Dispatch to push to " . $this->settings->ecomDisplayName() . '.'
                : "Dispatch data refreshed for order #{$erpId}.";

            return $this->syncActionResponse(
                $request,
                'success',
                $message,
                $this->orderRowPayload($ctx, $orderMapping),
                redirectRoute: 'dashboard.orders'
            );
        } catch (\Throwable $e) {
            return $this->syncActionResponse(
                $request,
                'error',
                'Fetch dispatch failed: ' . $e->getMessage(),
                status: 500,
                redirectRoute: 'dashboard.orders'
            );
        }
    }

    private function fetchDispatchSingleFromEcom(Request $request, int|string $erpId)
    {
        if (!$this->settings->allowsDispatchEcomToErp()) {
            return $this->syncActionResponse(
                $request,
                'error',
                'Dispatch fetch is for ' . $this->settings->ecomDisplayName() . ' → ' . $this->settings->erpDisplayName() . ' only.',
                redirectRoute: 'dashboard.orders'
            );
        }

        $ctx = $this->listingContext($request);

        try {
            $orderMapping = SyncMapping::whereIn('entity_type', ['order', 'sales_order'])
                ->where('erp_id', (string) $erpId)
                ->first();

            if (!$orderMapping || !$orderMapping->ecom_id) {
                return $this->syncActionResponse(
                    $request,
                    'error',
                    "No e-commerce mapping found for order #{$erpId}. Post the sale first.",
                    redirectRoute: 'dashboard.orders'
                );
            }

            $ecom         = app(EcomInterface::class);
            $fulfillments = $ecom->getFulfillmentsForOrder($orderMapping->ecom_id);
            $matched      = collect($fulfillments)->filter(
                fn ($f) => ($f['status'] ?? '') === 'success'
            );

            if ($matched->isEmpty()) {
                return $this->syncActionResponse(
                    $request,
                    'info',
                    "No fulfillments found for order #{$erpId} in " . $this->settings->ecomDisplayName() . '.',
                    redirectRoute: 'dashboard.orders'
                );
            }

            $stored    = 0;
            $refreshed = 0;
            $unchanged = 0;

            foreach ($matched as $fulfillment) {
                $fulfillmentId = (string) ($fulfillment['id'] ?? '');

                $existing = SyncMapping::where('entity_type', 'dispatch')
                    ->where('ecom_id', $fulfillmentId)
                    ->first();

                if ($existing && $this->dispatchFulfillmentUnchanged($existing, $fulfillment)) {
                    $unchanged++;
                    continue;
                }

                if ($existing && in_array($existing->ecom_status, SyncEntityState::DISPATCH_PUSHABLE, true)) {
                    $this->refreshDispatchFulfillmentPayload($fulfillment, $orderMapping);
                    $refreshed++;
                    continue;
                }

                if ($existing && $existing->ecom_status === SyncEntityState::DISPATCH_SENT) {
                    $this->persistDispatchFulfillment($fulfillment, $orderMapping, SyncEntityState::DISPATCH_UPDATED);
                    $stored++;
                    continue;
                }

                $this->persistDispatchFulfillment($fulfillment, $orderMapping, SyncEntityState::DISPATCH_PENDING);
                $stored++;
            }

            if ($stored === 0 && $refreshed === 0) {
                if ($unchanged > 0) {
                    $this->setOrderDispatchInfoMessage((string) $erpId, SyncEntityState::DISPATCH_MSG_NO_UPDATE);
                }

                return $this->syncActionResponse(
                    $request,
                    'info',
                    $unchanged > 0
                        ? SyncEntityState::DISPATCH_MSG_NO_UPDATE . " for order #{$erpId}."
                        : "Dispatch for order #{$erpId} already fetched — no changes.",
                    $this->orderRowPayload($ctx, $orderMapping),
                    redirectRoute: 'dashboard.orders'
                );
            }

            $this->clearOrderDispatchMessage((string) $erpId);
            $orderMapping->refresh();

            $message = $stored > 0
                ? "Dispatch fetched for order #{$erpId}. Click Post Dispatch to push to " . $this->settings->erpDisplayName() . '.'
                : "Dispatch data refreshed for order #{$erpId}.";

            return $this->syncActionResponse(
                $request,
                'success',
                $message,
                $this->orderRowPayload($ctx, $orderMapping),
                redirectRoute: 'dashboard.orders'
            );
        } catch (\Throwable $e) {
            return $this->syncActionResponse(
                $request,
                'error',
                'Fetch dispatch failed: ' . $e->getMessage(),
                status: 500,
                redirectRoute: 'dashboard.orders'
            );
        }
    }

    // ── Post Dispatch (single order) ──────────────────────────────────────
    public function postDispatchSingle(Request $request, int|string $erpId)
    {
        if (!$this->settings->allowsDispatchPost()) {
            return $this->syncActionResponse(
                $request,
                'error',
                'Dispatch post is not available for the current sales order sync direction.',
                redirectRoute: 'dashboard.orders'
            );
        }

        $flow = $this->settings->dispatchFlowForListing($request->input('direction'));

        return $flow === 'ecom_to_erp'
            ? $this->postDispatchSingleToErp($request, $erpId)
            : $this->postDispatchSingleToEcom($request, $erpId);
    }

    private function postDispatchSingleToEcom(Request $request, int|string $erpId)
    {
        if (!$this->settings->allowsDispatchErpToEcom()) {
            return $this->syncActionResponse(
                $request,
                'error',
                'Dispatch post is for ' . $this->settings->erpDisplayName() . ' → ' . $this->settings->ecomDisplayName() . ' only.',
                redirectRoute: 'dashboard.orders'
            );
        }

        $ctx = $this->listingContext($request);

        try {
            $pending = SyncMapping::where('entity_type', 'dispatch')
                ->whereIn('ecom_status', SyncEntityState::DISPATCH_PUSHABLE)
                ->where('last_sync_direction', 'erp_to_ecom')
                ->where(function ($q) use ($erpId) {
                    $q->where('erp_reference', (string) $erpId);
                })
                ->get();

            // Fallback: match via ecom_id of the order mapping
            if ($pending->isEmpty()) {
                $orderMapping = SyncMapping::whereIn('entity_type', ['order', 'sales_order'])
                    ->where('erp_id', (string) $erpId)
                    ->first();

                if ($orderMapping) {
                    $pending = SyncMapping::where('entity_type', 'dispatch')
                        ->whereIn('ecom_status', SyncEntityState::DISPATCH_PUSHABLE)
                        ->where('ecom_id', $orderMapping->ecom_id)
                        ->get();
                }
            }

            if ($pending->isEmpty()) {
                return $this->syncActionResponse(
                    $request,
                    'info',
                    "No pending dispatch for order #{$erpId}. Run Fetch Dispatch first.",
                    redirectRoute: 'dashboard.orders'
                );
            }

            $pushed = 0;
            $failed = 0;
            $lastError = null;

            foreach ($pending as $mapping) {
                $picking = $mapping->payload();
                if (empty($picking)) {
                    $failed++;
                    continue;
                }

                $picking['_ecom_order_id'] = $mapping->ecom_id;

                try {
                    \App\Jobs\Ecom\PushFulfillmentToEcomJob::dispatchSync($picking);
                    $mapping->update([
                        'ecom_status'    => SyncEntityState::DISPATCH_SENT,
                        'last_synced_at' => now(),
                        'sync_message'   => null,
                    ]);
                    $this->clearOrderDispatchMessage($mapping->erp_reference ?? (string) $erpId);
                    $pushed++;
                } catch (\Throwable $e) {
                    $lastError = $this->syncErrorMessage($e);
                    Log::error("postDispatchSingle: picking#{$mapping->erp_id} failed: " . $e->getMessage());
                    $mapping->update(['sync_message' => $lastError]);
                    $failed++;
                }
            }

            $orderMapping = SyncMapping::whereIn('entity_type', ['order', 'sales_order'])
                ->where('erp_id', (string) $erpId)
                ->first();

            if ($lastError && $orderMapping) {
                $orderMapping->update(['sync_message' => $lastError]);
            } elseif ($pushed > 0 && $orderMapping) {
                $orderMapping->update(['sync_message' => null]);
            }

            if ($pushed === 0 && $failed > 0 && $lastError) {
                return $this->syncActionResponse(
                    $request,
                    'error',
                    'Push failed: ' . $lastError,
                    $orderMapping ? $this->orderRowPayload($ctx, $orderMapping->fresh()) : ['refresh_table' => true],
                    redirectRoute: 'dashboard.orders'
                );
            }

            $msg = "{$pushed} fulfillment(s) pushed to " . $this->settings->ecomDisplayName() . '.';
            if ($failed) {
                $msg .= " {$failed} failed" . ($lastError ? ": {$lastError}" : ' — check logs.');
            }

            return $this->syncActionResponse(
                $request,
                $failed > 0 ? ($pushed > 0 ? 'warning' : 'error') : 'success',
                $msg,
                $orderMapping ? $this->orderRowPayload($ctx, $orderMapping->fresh()) : ['refresh_table' => true],
                redirectRoute: 'dashboard.orders'
            );

        } catch (\Throwable $e) {
            return $this->syncActionResponse(
                $request,
                'error',
                'Post dispatch failed: ' . $this->syncErrorMessage($e),
                status: 500,
                redirectRoute: 'dashboard.orders'
            );
        }
    }

    private function postDispatchSingleToErp(Request $request, int|string $erpId)
    {
        if (!$this->settings->allowsDispatchEcomToErp()) {
            return $this->syncActionResponse(
                $request,
                'error',
                'Dispatch post is for ' . $this->settings->ecomDisplayName() . ' → ' . $this->settings->erpDisplayName() . ' only.',
                redirectRoute: 'dashboard.orders'
            );
        }

        $ctx = $this->listingContext($request);

        try {
            $pending = SyncMapping::where('entity_type', 'dispatch')
                ->whereIn('ecom_status', SyncEntityState::DISPATCH_PUSHABLE)
                ->where('last_sync_direction', 'ecom_to_erp')
                ->where('erp_reference', (string) $erpId)
                ->get();

            if ($pending->isEmpty()) {
                return $this->syncActionResponse(
                    $request,
                    'info',
                    "No pending dispatch for order #{$erpId}. Run Fetch Dispatch first.",
                    redirectRoute: 'dashboard.orders'
                );
            }

            $pushed    = 0;
            $failed    = 0;
            $lastError = null;

            foreach ($pending as $mapping) {
                $fulfillment = $mapping->payload();
                if (empty($fulfillment) || !is_array($fulfillment)) {
                    $failed++;
                    continue;
                }

                $fulfillment['erp_order_id'] = $fulfillment['erp_order_id'] ?? (string) $erpId;

                try {
                    $pickingId = PushFulfillmentToErpJob::dispatchSync($fulfillment);
                    $mapping->update([
                        'erp_id'         => SyncEntityState::requireErpId($pickingId, 'Delivery note id'),
                        'ecom_status'    => SyncEntityState::DISPATCH_SENT,
                        'last_synced_at' => now(),
                        'sync_message'   => null,
                    ]);
                    $this->clearOrderDispatchMessage((string) $erpId);
                    $pushed++;
                } catch (\Throwable $e) {
                    $lastError = $this->syncErrorMessage($e);
                    Log::error("postDispatchSingleToErp: fulfillment#{$mapping->ecom_id} failed: " . $e->getMessage());
                    $mapping->update(['sync_message' => $lastError]);
                    $failed++;
                }
            }

            $orderMapping = SyncMapping::whereIn('entity_type', ['order', 'sales_order'])
                ->where('erp_id', (string) $erpId)
                ->first();

            if ($lastError && $orderMapping) {
                $orderMapping->update(['sync_message' => $lastError]);
            } elseif ($pushed > 0 && $orderMapping) {
                $orderMapping->update(['sync_message' => null]);
            }

            if ($pushed === 0 && $failed > 0 && $lastError) {
                return $this->syncActionResponse(
                    $request,
                    'error',
                    'Push failed: ' . $lastError,
                    $orderMapping ? $this->orderRowPayload($ctx, $orderMapping->fresh()) : ['refresh_table' => true],
                    redirectRoute: 'dashboard.orders'
                );
            }

            $msg = "{$pushed} fulfillment(s) pushed to " . $this->settings->erpDisplayName() . '.';
            if ($failed) {
                $msg .= " {$failed} failed" . ($lastError ? ": {$lastError}" : ' — check logs.');
            }

            return $this->syncActionResponse(
                $request,
                $failed > 0 ? ($pushed > 0 ? 'warning' : 'error') : 'success',
                $msg,
                $orderMapping ? $this->orderRowPayload($ctx, $orderMapping->fresh()) : ['refresh_table' => true],
                redirectRoute: 'dashboard.orders'
            );
        } catch (\Throwable $e) {
            return $this->syncActionResponse(
                $request,
                'error',
                'Post dispatch failed: ' . $this->syncErrorMessage($e),
                status: 500,
                redirectRoute: 'dashboard.orders'
            );
        }
    }

    private function resolveOrderLogPayload(?string $payload): mixed
    {
        if (!$payload) {
            return null;
        }

        $decoded = json_decode($payload, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $payload;
    }

    private function resolveOrderMappedPayload(?string $requestPayload): mixed
    {
        $decoded = $this->resolveOrderLogPayload($requestPayload);
        if (!is_array($decoded)) {
            return $decoded;
        }

        if (isset($decoded['mapped_payload']) && is_array($decoded['mapped_payload'])) {
            return $decoded['mapped_payload'];
        }

        if (isset($decoded['values']) && is_array($decoded['values'])) {
            return $decoded['values'];
        }

        if (isset($decoded['rpc_calls'])) {
            return null;
        }

        if (isset($decoded['source_order'])) {
            return null;
        }

        return $decoded;
    }

    private function resolveOrderApiCalls(?string $requestPayload): mixed
    {
        $decoded = $this->resolveOrderLogPayload($requestPayload);
        if (!is_array($decoded)) {
            return null;
        }

        if (!empty($decoded['rpc_calls'])) {
            return $decoded['rpc_calls'];
        }

        return null;
    }

    /** Actual values sent to Odoo sale.order or ERPNext Sales Order REST API. */
    private function resolveOrderWirePayload(?string $requestPayload): mixed
    {
        $decoded = $this->resolveOrderLogPayload($requestPayload);
        if (!is_array($decoded)) {
            return null;
        }

        if (!empty($decoded['wire_payload']) && is_array($decoded['wire_payload'])) {
            return $decoded['wire_payload'];
        }

        if (!empty($decoded['api_calls']) && is_array($decoded['api_calls'])) {
            foreach ($decoded['api_calls'] as $call) {
                $body = $call['body'] ?? null;
                if (is_array($body) && is_array($body['data'] ?? null) && $body['data'] !== []) {
                    return $body['data'];
                }
            }
        }

        if (!empty($decoded['rpc_calls']) && is_array($decoded['rpc_calls'])) {
            foreach ($decoded['rpc_calls'] as $call) {
                if (($call['model'] ?? '') !== 'sale.order') {
                    $body = $call['body'] ?? null;
                    if (is_array($body) && is_array($body['data'] ?? null) && $body['data'] !== []) {
                        return $body['data'];
                    }

                    continue;
                }
                $method = $call['method'] ?? '';
                $args   = $call['args'] ?? [];
                if ($method === 'create' && isset($args[0]) && is_array($args[0])) {
                    return $args[0];
                }
                if ($method === 'write' && isset($args[1]) && is_array($args[1])) {
                    return $args[1];
                }
            }
        }

        return null;
    }

    /** Real payload sent to the target system (wire values for Odoo, direct payload for Shopify). */
    private function resolveOrderDisplayPayload(?string $requestPayload, bool $isEcomToErp): mixed
    {
        if (!$requestPayload) {
            return null;
        }

        if ($isEcomToErp) {
            $wire = $this->resolveOrderWirePayload($requestPayload);
            if (!empty($wire) && is_array($wire)) {
                return $wire;
            }

            return $this->resolveOrderMappedPayload($requestPayload);
        }

        $decoded = $this->resolveOrderLogPayload($requestPayload);
        if (!is_array($decoded)) {
            return $decoded;
        }

        if (isset($decoded['wire_input']) && is_array($decoded['wire_input'])) {
            return $decoded['wire_input'];
        }

        if (isset($decoded['mapped_payload']) && is_array($decoded['mapped_payload'])) {
            return $decoded['mapped_payload'];
        }

        if (isset($decoded['rpc_calls'])) {
            return null;
        }

        return $decoded;
    }

    /** Response from the target system — record/ID/error only, not raw RPC call dumps. */
    private function resolveOrderDisplayResponse(?string $responsePayload, bool $isEcomToErp): mixed
    {
        $decoded = $this->resolveOrderLogPayload($responsePayload);
        if (!is_array($decoded)) {
            return $decoded;
        }

        if (!$isEcomToErp) {
            return $decoded;
        }

        $display = array_filter([
            'erp_id' => $decoded['erp_id'] ?? null,
            'driver' => $decoded['driver'] ?? null,
            'record' => $decoded['record'] ?? null,
            'error'  => $decoded['error'] ?? null,
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);

        return !empty($display) ? $display : $decoded;
    }

    public function destroy(Request $request, string $id)
    {
        return $this->destroySyncEntity(
            $request,
            app(\App\Services\Sync\UniversalSyncService::class),
            'order',
            $id,
            'dashboard.orders',
            removedRowId: $this->orderRemovedRowId($id),
        );
    }

    public function destroyBulk(Request $request)
    {
        return $this->destroySyncEntitiesBulk(
            $request,
            app(\App\Services\Sync\UniversalSyncService::class),
            'order',
            'dashboard.orders',
            fn (string $id) => $this->orderRemovedRowId($id),
        );
    }

    private function orderRemovedRowId(string $id): string
    {
        return (ctype_digit($id) ? 'erp-' : 'ecom-') . $id;
    }

    public function destroyDispatch(Request $request, string $id)
    {
        return $this->destroySyncEntity(
            $request,
            app(\App\Services\Sync\UniversalSyncService::class),
            'dispatch',
            $id,
            'dashboard.orders',
            idSide: ctype_digit($id) ? 'erp' : 'ecom',
            removedRowId: null,
        );
    }
}