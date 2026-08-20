<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\HandlesAjaxSyncResponses;
use App\Jobs\Ecom\FetchEcomCustomersOnlyJob;
use App\Jobs\Erp\FetchErpCustomersJob;
use App\Models\SyncLog;
use App\Models\SyncMapping;
use App\Models\SyncQueueState;
use App\Services\Erp\ErpInterface;
use App\Services\SettingsService;
use App\Services\Sync\CustomerSyncService;
use App\Services\Sync\SyncEntityState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomersController extends Controller
{
    use HandlesAjaxSyncResponses;

    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    public function index(Request $request)
    {
        $ctx = $this->listingContext($request);
        $customers = $this->queryCustomers($ctx);

        $stats = [
            'total'        => SyncMapping::where('entity_type', 'customer')->count(),
            'synced_today' => SyncLog::where('entity_type', 'customer')
                ->where('status', 'success')
                ->whereDate('created_at', today())->count(),
            'failed_today' => SyncLog::where('entity_type', 'customer')
                ->where('status', 'failed')
                ->whereDate('created_at', today())->count(),
            'pending'      => SyncMapping::where('entity_type', 'customer')
                ->whereIn('ecom_status', SyncEntityState::PUSHABLE_STATUSES)
                ->count(),
        ];

        if ($ctx['syncMode'] === 'bidirectional') {
            $stats['erp_to_ecom'] = SyncMapping::where('entity_type', 'customer')
                ->where('last_sync_direction', 'erp_to_ecom')->count();
            $stats['ecom_to_erp'] = SyncMapping::where('entity_type', 'customer')
                ->where('last_sync_direction', 'ecom_to_erp')->count();
        }

        return view('dashboard.customers', array_merge($ctx, compact('customers', 'stats')));
    }

    public function rows(Request $request): JsonResponse
    {
        $ctx = $this->listingContext($request);

        return response()->json([
            'html' => $this->renderCustomersRowsHtml($this->queryCustomers($ctx), $ctx),
        ]);
    }

    // ── Fetch from ERP (erp_to_ecom / bidirectional) ─────────────────────

    public function fetch(Request $request)
    {
        if (!$this->settings->allowsFetchFromErp('customer')) {
            return $this->syncActionResponse(
                $request,
                'error',
                'Customer sync is set to ' . $this->settings->ecomDisplayName() . ' → ' . $this->settings->erpDisplayName()
                    . '. Use Fetch from ' . $this->settings->ecomDisplayName() . ' (pull).',
                redirectRoute: 'dashboard.customers'
            );
        }

        try {
            FetchErpCustomersJob::dispatchSync(autoPush: false);

            $notes = SyncQueueState::forType('customers')->fresh()->notes ?? '';

            if ($notes === 'nothing_changed' || str_starts_with($notes, 'fetched:0')) {
                return $this->syncActionResponse(
                    $request,
                    'info',
                    'No customer changes in ' . $this->settings->erpDisplayName() . ' since last fetch.',
                    ['fetched' => 0],
                    redirectRoute: 'dashboard.customers'
                );
            }

            if (str_starts_with($notes, 'fetched:')) {
                preg_match('/fetched:(\d+)(?::skipped:(\d+))?/', $notes, $m);
                $fetched = (int) ($m[1] ?? 0);
                $skipped = isset($m[2]) ? (int) $m[2] : 0;
                $skipNote = $skipped > 0 ? " ({$skipped} unchanged skipped)" : '';

                return $this->syncActionResponse(
                    $request,
                    'success',
                    "{$fetched} customer(s) fetched{$skipNote} from " . $this->settings->erpDisplayName()
                        . '. Click Post to ' . $this->settings->ecomDisplayName() . ' to sync.',
                    ['fetched' => $fetched, 'skipped' => $skipped, 'refresh_table' => true],
                    redirectRoute: 'dashboard.customers'
                );
            }

            return $this->syncActionResponse(
                $request,
                'success',
                'Customers fetched from ' . $this->settings->erpDisplayName()
                    . '. Click Post to ' . $this->settings->ecomDisplayName() . ' to sync.',
                ['refresh_table' => true],
                redirectRoute: 'dashboard.customers'
            );
        } catch (\Throwable $e) {
            return $this->syncActionResponse(
                $request,
                'error',
                'Fetch from ' . $this->settings->erpDisplayName() . ' failed: ' . $e->getMessage(),
                status: 500,
                redirectRoute: 'dashboard.customers'
            );
        }
    }

    // ── Pull from Ecom (ecom_to_erp / bidirectional) ─────────────────────

    public function pull(Request $request)
    {
        if (!$this->settings->allowsFetchFromEcom('customer')) {
            return $this->syncActionResponse(
                $request,
                'error',
                'Customer sync is set to ' . $this->settings->erpDisplayName() . ' → ' . $this->settings->ecomDisplayName()
                    . '. Use Fetch from ' . $this->settings->erpDisplayName() . '.',
                redirectRoute: 'dashboard.customers'
            );
        }

        try {
            FetchEcomCustomersOnlyJob::dispatchSync();

            $notes = SyncQueueState::forType('customers')->fresh()->notes ?? '';

            if ($notes === 'nothing_changed' || str_starts_with($notes, 'fetched:0')) {
                return $this->syncActionResponse(
                    $request,
                    'info',
                    'No customers returned from ' . $this->settings->ecomDisplayName() . ' since last fetch.',
                    ['fetched' => 0],
                    redirectRoute: 'dashboard.customers'
                );
            }

            if (str_starts_with($notes, 'checked:')) {
                preg_match('/checked:(\d+)(?::skipped:(\d+))?/', $notes, $m);
                $checked = (int) ($m[1] ?? 0);
                $skipped = (int) ($m[2] ?? 0);
                $skipNote = $skipped > 0 ? " ({$skipped} unchanged)" : '';

                return $this->syncActionResponse(
                    $request,
                    'info',
                    "{$checked} customer(s) checked from {$this->settings->ecomDisplayName()}{$skipNote} — none changed since last fetch.",
                    ['fetched' => 0, 'checked' => $checked, 'skipped' => $skipped],
                    redirectRoute: 'dashboard.customers'
                );
            }

            if (str_starts_with($notes, 'fetched:')) {
                preg_match('/fetched:(\d+)(?::skipped:(\d+))?/', $notes, $m);
                $fetched = (int) ($m[1] ?? 0);
                $skipped = isset($m[2]) ? (int) $m[2] : 0;
                $skipNote = $skipped > 0 ? " ({$skipped} unchanged skipped)" : '';

                return $this->syncActionResponse(
                    $request,
                    'success',
                    "{$fetched} customer(s) fetched{$skipNote}. Click Post to "
                        . $this->settings->erpDisplayName() . ' to sync.',
                    ['fetched' => $fetched, 'skipped' => $skipped, 'refresh_table' => true],
                    redirectRoute: 'dashboard.customers'
                );
            }

            return $this->syncActionResponse(
                $request,
                'success',
                'Customers fetched from ' . $this->settings->ecomDisplayName()
                    . '. Click Post to ' . $this->settings->erpDisplayName() . ' to sync.',
                ['refresh_table' => true],
                redirectRoute: 'dashboard.customers'
            );
        } catch (\Throwable $e) {
            return $this->syncActionResponse(
                $request,
                'error',
                'Fetch from ' . $this->settings->ecomDisplayName() . ' failed: ' . $e->getMessage(),
                status: 500,
                redirectRoute: 'dashboard.customers'
            );
        }
    }

    /** @deprecated Use fetch() or pull() */
    public function fetchCustomers(Request $request)
    {
        $syncMode = $this->settings->customerSyncMode();

        return $syncMode === 'ecom_to_erp'
            ? $this->pull($request)
            : $this->fetch($request);
    }

    // ── Post all: direction-aware push ──────────────────────────────────

    public function postCustomers(Request $request)
    {
        $syncMode      = $this->settings->customerSyncMode();
        $pushDirection = $syncMode === 'bidirectional'
            ? ($request->input('direction') === 'ecom_to_erp' ? 'ecom_to_erp' : 'erp_to_ecom')
            : $syncMode;

        try {
            $pending = SyncMapping::where('entity_type', 'customer')
                ->where('last_sync_direction', $pushDirection)
                ->whereIn('ecom_status', SyncEntityState::PUSHABLE_STATUSES)
                ->when($pushDirection === 'ecom_to_erp', fn ($q) => $q->whereNotNull('ecom_id'))
                ->when($pushDirection === 'erp_to_ecom', fn ($q) => $q->whereNotNull('erp_id'))
                ->get();

            if ($pending->isEmpty()) {
                $fetchFrom = $pushDirection === 'ecom_to_erp'
                    ? $this->settings->ecomDisplayName()
                    : $this->settings->erpDisplayName();
                $pushTo = $pushDirection === 'ecom_to_erp'
                    ? $this->settings->erpDisplayName()
                    : $this->settings->ecomDisplayName();

                return $this->syncActionResponse(
                    $request,
                    'info',
                    "No customers to push to {$pushTo}. Run Fetch from {$fetchFrom} first.",
                    ['pushed' => 0],
                    redirectRoute: 'dashboard.customers'
                );
            }

            $customerSync = app(CustomerSyncService::class);
            $pushed       = 0;
            $failed       = 0;
            $lastError    = null;

            foreach ($pending as $mapping) {
                $data = $mapping->payload();

                if (empty($data) || !is_array($data)) {
                    $lastError = 'No fetched customer data. Run Fetch first.';
                    $this->markCustomerPushFailed($mapping, $lastError);
                    $failed++;
                    continue;
                }

                try {
                    if ($pushDirection === 'ecom_to_erp') {
                        $customerSync->syncCustomerToErp($data);
                    } else {
                        $email = $data['email'] ?? null;
                        if (empty($email) || $email === false) {
                            $lastError = 'Customer has no email — required for Shopify push.';
                            $this->markCustomerPushFailed($mapping, $lastError);
                            $failed++;
                            continue;
                        }
                        $customerSync->syncCustomerToEcom($data);
                    }
                    $pushed++;
                } catch (\Throwable $e) {
                    $lastError = $this->syncErrorMessage($e);
                    $this->markCustomerPushFailed($mapping, $lastError);
                    \Illuminate\Support\Facades\Log::error('postCustomers: failed: ' . $e->getMessage());
                    $failed++;
                }
            }

            $dest = $pushDirection === 'ecom_to_erp'
                ? $this->settings->erpDisplayName()
                : $this->settings->ecomDisplayName();
            $msg = "{$pushed} customer(s) pushed to {$dest}.";
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
                redirectRoute: 'dashboard.customers'
            );
        } catch (\Throwable $e) {
            return $this->syncActionResponse(
                $request,
                'error',
                'Post customers failed: ' . $e->getMessage(),
                status: 500,
                redirectRoute: 'dashboard.customers'
            );
        }
    }

    public function fetchCustomerSingle(Request $request, string $id)
    {
        $syncMode = $this->settings->customerSyncMode();
        $ctx      = $this->listingContext($request);

        try {
            if ($syncMode === 'ecom_to_erp' || ($syncMode === 'bidirectional' && !ctype_digit($id))) {
                return $this->fetchCustomerSingleFromEcom($request, $id, $ctx);
            }

            return $this->fetchCustomerSingleFromErp($request, (int) $id, $ctx);
        } catch (\Throwable $e) {
            return $this->syncActionResponse(
                $request,
                'error',
                'Fetch customer failed: ' . $e->getMessage(),
                status: 500,
                redirectRoute: 'dashboard.customers'
            );
        }
    }

    public function postCustomerSingle(Request $request, string $id)
    {
        $syncMode = $this->settings->customerSyncMode();
        $ctx      = $this->listingContext($request);

        try {
            if ($syncMode === 'ecom_to_erp' || ($syncMode === 'bidirectional' && !ctype_digit($id))) {
                return $this->postCustomerSingleToErp($request, $id, $ctx);
            }

            return $this->postCustomerSingleToEcom($request, (int) $id, $ctx);
        } catch (\Throwable $e) {
            return $this->syncActionResponse(
                $request,
                'error',
                'Post customer failed: ' . $this->syncErrorMessage($e),
                status: 500,
                redirectRoute: 'dashboard.customers'
            );
        }
    }

    public function customerInfo(string $id)
    {
        $syncMode = $this->settings->customerSyncMode();

        $mapping = SyncMapping::where('entity_type', 'customer')
            ->where(function ($q) use ($id) {
                $q->where('erp_id', $id)
                  ->orWhere('ecom_id', $id);
            })
            ->first();

        $logIds = array_filter(array_unique([$id, $mapping?->ecom_id, $mapping?->erp_id]));

        $effectiveDirection = match (true) {
            $syncMode === 'bidirectional' => $mapping?->last_sync_direction ?: 'erp_to_ecom',
            $syncMode === 'ecom_to_erp'  => 'ecom_to_erp',
            default                      => 'erp_to_ecom',
        };

        $pushDirections = $effectiveDirection === 'ecom_to_erp'
            ? ['ecom_to_erp', 'shopify_to_erp', 'shopify_to_odoo']
            : ['erp_to_ecom', 'odoo_to_shopify', 'erp_to_shopify'];

        $syncLog = SyncLog::where('entity_type', 'customer')
            ->whereIn('entity_id', $logIds)
            ->whereIn('direction', $pushDirections)
            ->whereIn('status', ['success', 'failed'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        [$customerName, $customerEmail] = $this->extractMetaNameEmail($mapping?->payload());

        $meta       = $mapping ? ($mapping->payload() ?? []) : [];
        $sourceData = is_array($meta) ? collect($meta)->except('_fetch_wire')->all() : [];

        $targetPayload  = $this->resolveCustomerDisplayPayload(
            $syncLog?->request_payload,
            $syncLog?->response_payload,
            $effectiveDirection === 'ecom_to_erp'
        );
        $targetPayloadIsMapped = is_array($targetPayload)
            && !(array_is_list($targetPayload) && (
                isset($targetPayload[0]['model'])
                || isset($targetPayload[0]['query'])
                || isset($targetPayload[0]['action'])
                || isset($targetPayload[0]['url'])
            ));
        $targetResponse = $this->resolveCustomerDisplayResponse($syncLog?->response_payload, $syncLog?->request_payload);

        $shopifyStore = $this->settings->shopifyShop();
        $apiVersion   = $this->settings->shopifyVersion() ?: '2024-01';
        $graphqlUrl   = $shopifyStore
            ? "https://{$shopifyStore}.myshopify.com/admin/api/{$apiVersion}/graphql.json"
            : null;
        $erpHost = rtrim((string) (app(\App\Services\Erp\ErpInterface::class)->apiBaseUrl() ?? ''), '/');
        if ($erpHost === '') {
            $erpHost = rtrim((string) config('odoo.url', config('erp.url', '')), '/');
        }

        $displayId = $syncMode === 'ecom_to_erp'
            ? ($mapping?->ecom_id ?? $id)
            : ($mapping?->erp_id ?? $id);

        return view('dashboard.customer-info', compact(
            'mapping', 'id', 'syncLog', 'syncMode',
            'sourceData', 'targetPayload', 'targetPayloadIsMapped', 'targetResponse',
            'customerName', 'customerEmail',
            'shopifyStore', 'apiVersion', 'graphqlUrl', 'erpHost', 'displayId',
        ))
            ->with('erpDisplayName', $this->settings->erpDisplayName())
            ->with('ecomDisplayName', $this->settings->ecomDisplayName())
            ->with('erpDriver', $this->settings->erpDriver());
    }

    // ── Private helpers ───────────────────────────────────────────────────

    private function listingContext(Request $request): array
    {
        return [
            'syncMode'        => $this->settings->customerSyncMode(),
            'search'          => $request->input('search', ''),
            'status'          => $request->input('status', 'all'),
            'perPage'         => (int) $request->input('per_page', 25),
            'direction'       => $request->input('direction', 'erp_to_ecom'),
            'ecomDriver'      => $this->settings->ecomDriver(),
            'erpDriver'       => $this->settings->erpDriver(),
            'erpDisplayName'  => $this->settings->erpDisplayName(),
            'ecomDisplayName' => $this->settings->ecomDisplayName(),
        ];
    }

    private function queryCustomers(array $ctx)
    {
        $query = SyncMapping::where('entity_type', 'customer')
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

        $this->applyStatusFilter($query, $ctx['status']);

        if ($ctx['search']) {
            $search = $ctx['search'];
            $query->where(function ($q) use ($search) {
                $q->where('erp_id', 'like', "%{$search}%")
                  ->orWhere('ecom_id', 'like', "%{$search}%")
                  ->orWhere('ecom_handle', 'like', "%{$search}%");
            });
        }

        $customers = $query->paginate($ctx['perPage'])->withQueryString();

        $customers->getCollection()->transform(function ($mapping) {
            [$mapping->customer_name, $mapping->customer_email] = $this->extractMetaNameEmail($mapping->metadata);
            $mapping->display_status = SyncEntityState::displayStatus($mapping);

            if ($mapping->display_status === SyncEntityState::STATUS_SENT) {
                $mapping->display_message = null;
            } else {
                $mapping->display_message = $mapping->sync_message;
            }

            return $mapping;
        });

        return $customers;
    }

    private function applyStatusFilter($query, string $status): void
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

    private function renderCustomersRowsHtml($customers, array $ctx): string
    {
        return view('dashboard.partials.customers-table-rows', array_merge($ctx, [
            'customers' => $customers,
        ]))->render();
    }

    private function renderCustomerRowHtml($mapping, array $ctx): string
    {
        $rowId = ($ctx['syncMode'] === 'ecom_to_erp'
            || ($ctx['syncMode'] === 'bidirectional' && ($ctx['direction'] ?? 'erp_to_ecom') === 'ecom_to_erp'))
            ? 'ecom-' . ($mapping->ecom_id ?? 'unknown')
            : 'erp-' . ($mapping->erp_id ?? 'unknown');

        return view('dashboard.partials.customers-table-row', array_merge($ctx, [
            'mapping'  => $mapping,
            'rowIndex' => abs(crc32($rowId)),
        ]))->render();
    }

    private function customerRowPayload(array $ctx, SyncMapping $mapping): array
    {
        $mapping->refresh();
        [$mapping->customer_name, $mapping->customer_email] = $this->extractMetaNameEmail($mapping->metadata);
        $mapping->display_status = SyncEntityState::displayStatus($mapping);
        $mapping->display_message = $mapping->display_status === SyncEntityState::STATUS_SENT
            ? null
            : $mapping->sync_message;

        $rowId = ($ctx['syncMode'] === 'ecom_to_erp'
            || ($ctx['syncMode'] === 'bidirectional' && ($ctx['direction'] ?? 'erp_to_ecom') === 'ecom_to_erp'))
            ? 'ecom-' . ($mapping->ecom_id ?? 'unknown')
            : 'erp-' . ($mapping->erp_id ?? 'unknown');

        return [
            'row_id'   => $rowId,
            'row_html' => $this->renderCustomerRowHtml($mapping, $ctx),
        ];
    }

    private function findCustomerMapping(string $id): ?SyncMapping
    {
        return SyncMapping::where('entity_type', 'customer')
            ->where(function ($q) use ($id) {
                $q->where('erp_id', $id)
                  ->orWhere('ecom_id', $id);
            })
            ->first();
    }

    private function markCustomerPushFailed(SyncMapping $mapping, string $message, ?string $erpId = null): void
    {
        SyncEntityState::markFailed('customer', array_filter([
            'erp_id'      => $erpId ?? $mapping->erp_id,
            'erp_driver'  => $mapping->erp_driver ?? $this->settings->erpDriver(),
            'ecom_id'     => $mapping->ecom_id,
            'ecom_driver' => $mapping->ecom_driver,
        ]), $message);
    }

    private function fetchCustomerSingleFromErp(Request $request, int|string $erpId, array $ctx)
    {
        $erp      = app(ErpInterface::class);
        $customer = $erp->getCustomer($erpId);

        if (!$customer) {
            return $this->syncActionResponse(
                $request,
                'info',
                "Customer #{$erpId} not found in " . $this->settings->erpDisplayName() . '.',
                redirectRoute: 'dashboard.customers'
            );
        }

        $existing = SyncMapping::where('entity_type', 'customer')
            ->where('erp_id', (string) $erpId)
            ->where('erp_driver', $this->settings->erpDriver())
            ->first();

        SyncEntityState::markFetched(
            'customer',
            ['erp_id' => (string) $erpId, 'erp_driver' => $this->settings->erpDriver()],
            $customer,
            $existing,
            'erp_to_ecom',
            fn (array $d) => $d['write_date'] ?? null
        );

        $mapping = $this->findCustomerMapping((string) $erpId);

        return $this->syncActionResponse(
            $request,
            'success',
            "Customer #{$erpId} fetched. Click Post to push to " . $this->settings->ecomDisplayName() . '.',
            $mapping ? $this->customerRowPayload($ctx, $mapping) : ['refresh_table' => true],
            redirectRoute: 'dashboard.customers'
        );
    }

    private function fetchCustomerSingleFromEcom(Request $request, string $ecomId, array $ctx)
    {
        $mapping = SyncMapping::where('entity_type', 'customer')
            ->where('ecom_id', $ecomId)
            ->first();

        if (!$mapping || !$mapping->hasPayload()) {
            FetchEcomCustomersOnlyJob::dispatchSync();
            $mapping = SyncMapping::where('entity_type', 'customer')
                ->where('ecom_id', $ecomId)
                ->first();
        }

        if (!$mapping) {
            return $this->syncActionResponse(
                $request,
                'info',
                "Customer {$ecomId} not found in " . $this->settings->ecomDisplayName() . '.',
                redirectRoute: 'dashboard.customers'
            );
        }

        return $this->syncActionResponse(
            $request,
            'success',
            "Customer {$ecomId} fetched. Click Post to push to " . $this->settings->erpDisplayName() . '.',
            $this->customerRowPayload($ctx, $mapping),
            redirectRoute: 'dashboard.customers'
        );
    }

    private function postCustomerSingleToEcom(Request $request, int|string $erpId, array $ctx)
    {
        $mapping = SyncMapping::where('entity_type', 'customer')
            ->where('erp_id', (string) $erpId)
            ->whereNotNull('erp_id')
            ->first();

        if (!$mapping) {
            return $this->syncActionResponse(
                $request,
                'error',
                "No pending customer for #{$erpId}. Run Fetch first.",
                redirectRoute: 'dashboard.customers'
            );
        }

        if (!SyncEntityState::needsPush($mapping)) {
            return $this->syncActionResponse(
                $request,
                'info',
                "Customer #{$erpId} is already synced and unchanged.",
                $this->customerRowPayload($ctx, $mapping),
                redirectRoute: 'dashboard.customers'
            );
        }

        $data = $mapping->payload();

        if (empty($data) || !is_array($data)) {
            return $this->syncActionResponse(
                $request,
                'error',
                "No fetched data for customer #{$erpId}. Run Fetch first.",
                $this->customerRowPayload($ctx, $mapping),
                redirectRoute: 'dashboard.customers'
            );
        }

        try {
            app(CustomerSyncService::class)->syncCustomerToEcom($data);
            $mapping = $this->findCustomerMapping((string) $erpId);

            return $this->syncActionResponse(
                $request,
                'success',
                "Customer #{$erpId} pushed to " . $this->settings->ecomDisplayName() . '.',
                $mapping ? $this->customerRowPayload($ctx, $mapping) : ['refresh_table' => true],
                redirectRoute: 'dashboard.customers'
            );
        } catch (\Throwable $e) {
            $message = $this->syncErrorMessage($e);
            $this->markCustomerPushFailed($mapping, $message, (string) $erpId);
            $mapping = $this->findCustomerMapping((string) $erpId);

            return $this->syncActionResponse(
                $request,
                'error',
                'Push failed: ' . $message,
                $mapping ? $this->customerRowPayload($ctx, $mapping) : ['refresh_table' => true],
                status: 422,
                redirectRoute: 'dashboard.customers'
            );
        }
    }

    private function postCustomerSingleToErp(Request $request, string $ecomId, array $ctx)
    {
        $mapping = SyncMapping::where('entity_type', 'customer')
            ->where('ecom_id', $ecomId)
            ->whereNotNull('ecom_id')
            ->first();

        if (!$mapping) {
            return $this->syncActionResponse(
                $request,
                'error',
                "No pending customer for {$ecomId}. Run Fetch first.",
                redirectRoute: 'dashboard.customers'
            );
        }

        if (!SyncEntityState::needsPush($mapping)) {
            return $this->syncActionResponse(
                $request,
                'info',
                "Customer {$ecomId} is already synced and unchanged.",
                $this->customerRowPayload($ctx, $mapping),
                redirectRoute: 'dashboard.customers'
            );
        }

        $data = $mapping->payload();

        try {
            app(CustomerSyncService::class)->syncCustomerToErp($data);
            $mapping = $this->findCustomerMapping($ecomId);

            return $this->syncActionResponse(
                $request,
                'success',
                "Customer {$ecomId} pushed to " . $this->settings->erpDisplayName() . '.',
                $mapping ? $this->customerRowPayload($ctx, $mapping) : ['refresh_table' => true],
                redirectRoute: 'dashboard.customers'
            );
        } catch (\Throwable $e) {
            $message = $this->syncErrorMessage($e);
            $this->markCustomerPushFailed($mapping, $message);
            $mapping = $this->findCustomerMapping($ecomId);

            return $this->syncActionResponse(
                $request,
                'error',
                'Push failed: ' . $message,
                $mapping ? $this->customerRowPayload($ctx, $mapping) : ['refresh_table' => true],
                status: 422,
                redirectRoute: 'dashboard.customers'
            );
        }
    }

    private function resolveCustomerLogPayload(?string $payload): mixed
    {
        return $this->decodeSyncLogPayload($payload);
    }

    private function decodeSyncLogPayload(?string $payload): mixed
    {
        if (!$payload) {
            return null;
        }

        $decoded = json_decode($payload, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $payload;
    }

    private function resolveCustomerDisplayPayload(
        ?string $requestPayload,
        ?string $responsePayload,
        bool $isEcomToErp
    ): mixed {
        $apiCalls = $this->syncLogApiCalls($requestPayload, $responsePayload);

        if (is_array($apiCalls) && $apiCalls !== []) {
            $wire = collect($apiCalls)
                ->map(fn ($call) => array_filter([
                    'url'       => $call['url'] ?? null,
                    'method'    => $call['method'] ?? null,
                    'query'     => $call['query'] ?? null,
                    'body'      => $call['body'] ?? null,
                    'endpoint'  => $call['endpoint'] ?? null,
                    'action'    => $call['action'] ?? null,
                    'variables' => $call['variables'] ?? null,
                    'model'     => $call['model'] ?? null,
                    'args'      => $call['args'] ?? null,
                    'kwargs'    => $call['kwargs'] ?? null,
                    'wire_input'=> $call['wire_input'] ?? null,
                ], fn ($v) => $v !== null && $v !== []))
                ->filter(fn ($call) => $call !== [])
                ->values()
                ->all();

            if ($wire !== []) {
                return $wire;
            }
        }

        $decoded = $this->decodeSyncLogPayload($requestPayload);
        if (!is_array($decoded)) {
            return $decoded;
        }

        if (!empty($decoded['rpc_calls']) && is_array($decoded['rpc_calls'])) {
            $fromWire = $this->outgoingPayloadFromRpcCalls($decoded['rpc_calls']);
            if ($fromWire !== null) {
                return $fromWire;
            }
        }

        if (!empty($decoded['api_calls']) && is_array($decoded['api_calls'])) {
            return $decoded['api_calls'];
        }

        if (isset($decoded['mapped_payload']) && is_array($decoded['mapped_payload'])) {
            return $decoded['mapped_payload'];
        }

        if (isset($decoded['values']) && is_array($decoded['values'])) {
            return $decoded['values'];
        }

        return $decoded;
    }

    private function resolveCustomerDisplayResponse(?string $responsePayload, ?string $requestPayload = null): mixed
    {
        $apiCalls = $this->syncLogApiCalls($requestPayload, $responsePayload);

        if (is_array($apiCalls) && $apiCalls !== []) {
            $responses = collect($apiCalls)
                ->map(fn ($call) => array_filter([
                    'url'      => $call['url'] ?? null,
                    'method'   => $call['method'] ?? null,
                    'status'   => $call['status'] ?? null,
                    'action'   => $call['action'] ?? null,
                    'model'    => $call['model'] ?? null,
                    'response' => $call['response'] ?? $call['result'] ?? null,
                ], fn ($v) => $v !== null))
                ->filter(fn ($row) => ($row['response'] ?? null) !== null || ($row['url'] ?? null) !== null || ($row['model'] ?? null) !== null)
                ->values()
                ->all();

            if ($responses !== []) {
                return $responses;
            }
        }

        $decoded = $this->resolveCustomerLogPayload($responsePayload);
        if (!is_array($decoded)) {
            return $decoded;
        }

        if (!empty($decoded['api_calls']) && is_array($decoded['api_calls'])) {
            return collect($decoded['api_calls'])
                ->map(fn ($call) => array_filter([
                    'url'      => $call['url'] ?? null,
                    'method'   => $call['method'] ?? null,
                    'status'   => $call['status'] ?? null,
                    'action'   => $call['action'] ?? null,
                    'model'    => $call['model'] ?? null,
                    'response' => $call['response'] ?? $call['result'] ?? null,
                ], fn ($v) => $v !== null))
                ->filter(fn ($row) => ($row['response'] ?? null) !== null || ($row['url'] ?? null) !== null || ($row['model'] ?? null) !== null)
                ->values()
                ->all();
        }

        return collect($decoded)->except('api_calls', 'calls', 'erp_id', 'driver')->filter()->all() ?: null;
    }

    /** @param  array<int, array<string, mixed>>  $rpcCalls */
    private function outgoingPayloadFromRpcCalls(array $rpcCalls): ?array
    {
        foreach (array_reverse($rpcCalls) as $call) {
            $method = $call['method'] ?? '';
            $args   = $call['args'] ?? [];

            if ($method === 'create' && isset($args[0]) && is_array($args[0])) {
                return [
                    '_rpc' => [
                        'endpoint' => $call['endpoint'] ?? null,
                        'model'    => $call['model'] ?? null,
                        'method'   => 'create',
                    ],
                    'values' => $args[0],
                ];
            }

            if ($method === 'write' && isset($args[1]) && is_array($args[1])) {
                return [
                    '_rpc' => [
                        'endpoint' => $call['endpoint'] ?? null,
                        'model'    => $call['model'] ?? null,
                        'method'   => 'write',
                        'ids'      => $args[0] ?? [],
                    ],
                    'values' => $args[1],
                ];
            }
        }

        return null;
    }

    private function syncLogApiCalls(?string $requestPayload, ?string $responsePayload): mixed
    {
        $req = $this->decodeSyncLogPayload($requestPayload);
        $res = $this->decodeSyncLogPayload($responsePayload);

        $calls = [];

        if (is_array($req)) {
            if (!empty($req['rpc_calls'])) {
                $calls = array_merge($calls, $req['rpc_calls']);
            }
            if (!empty($req['api_calls'])) {
                $calls = array_merge($calls, $req['api_calls']);
            }
        }

        if (is_array($res) && !empty($res['api_calls'])) {
            $calls = array_merge($calls, $res['api_calls']);
        }

        if (is_array($res) && !empty($res['calls']) && $calls === []) {
            return $res['calls'];
        }

        return $calls ?: null;
    }

    private function extractMetaNameEmail(mixed $metadata): array
    {
        if (empty($metadata)) {
            return [null, null];
        }

        $meta = is_array($metadata) ? $metadata : json_decode($metadata, true);

        if (is_string($meta)) {
            $meta = json_decode($meta, true);
        }

        if (!is_array($meta)) {
            return [null, null];
        }

        $name = null;
        if (isset($meta['name']) && $meta['name'] !== false) {
            $name = (string) $meta['name'];
        } else {
            $first = $meta['firstName'] ?? $meta['first_name'] ?? '';
            $last  = $meta['lastName'] ?? $meta['last_name'] ?? '';
            $name  = trim(trim((string) $first) . ' ' . trim((string) $last)) ?: null;
        }

        $email = isset($meta['email']) && $meta['email'] !== false ? (string) $meta['email'] : null;

        return [$name, $email];
    }

    public function destroy(Request $request, string $id)
    {
        return $this->destroySyncEntity(
            $request,
            app(\App\Services\Sync\UniversalSyncService::class),
            'customer',
            $id,
            'dashboard.customers',
            removedRowId: $this->customerRemovedRowId($id),
        );
    }

    public function destroyBulk(Request $request)
    {
        return $this->destroySyncEntitiesBulk(
            $request,
            app(\App\Services\Sync\UniversalSyncService::class),
            'customer',
            'dashboard.customers',
            fn (string $id) => $this->customerRemovedRowId($id),
        );
    }

    private function customerRemovedRowId(string $id): string
    {
        return (ctype_digit($id) ? 'erp-' : 'ecom-') . $id;
    }
}
