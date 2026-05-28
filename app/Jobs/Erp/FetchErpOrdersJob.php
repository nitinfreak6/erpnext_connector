<?php

namespace App\Jobs\Erp;

use App\Jobs\Ecom\PushFulfillmentToEcomJob;
use App\Jobs\Ecom\PushCancellationToEcomJob;
use App\Jobs\Ecom\PushOrderToEcomJob;
use App\Models\SyncMapping;
use App\Models\SyncQueueState;
use App\Services\Erp\ErpInterface;
use App\Services\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchErpOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('sync');
    }

    public function handle(ErpInterface $erp, SettingsService $settings): void
    {
        // FIX #13: check enable flag before doing anything
        if (!$settings->isSalesOrderSyncEnabled()) {
            Log::info('FetchErpOrdersJob: skipped — sales order sync is disabled in settings.');
            return;
        }

        $syncMode = $settings->salesOrderSyncMode();

        if ($syncMode === 'ecom_to_erp') {
            Log::info('FetchErpOrdersJob: skipped — mode is ecom_to_erp.');
            return;
        }

        $state = SyncQueueState::forType('orders');

        // Self-healing lock (5 min timeout)
        if ($state->is_running && $state->updated_at->gt(now()->subMinutes(5))) {
            Log::warning('FetchErpOrdersJob: another run still active, skipping.');
            return;
        }

        if ($state->is_running) {
            Log::warning('FetchErpOrdersJob: stale lock detected, auto-releasing.');
        }

        $state->update(['is_running' => true]);

        try {
            // FIX: use getErpWriteDate() which reads last_erp_write_date
            $writeDate     = $state->getErpWriteDate();
            $onlyErpOrigin = ($syncMode === 'erp_to_ecom');

            Log::info("FetchErpOrdersJob [{$erp->driverName()}]: mode={$syncMode}");

            $orders          = $erp->getOrdersModifiedSince($writeDate, $onlyErpOrigin);
            $latestWriteDate = $writeDate;

            foreach ($orders as $order) {
                $orderId = (string) $order['id'];

                $mapping = SyncMapping::where('entity_type', 'order')
                    ->where('erp_id', $orderId)
                    ->first();

                if (!$mapping) {
                    Log::info("FetchErpOrdersJob: order #{$orderId} not mapped, creating in ecom");
                    PushOrderToEcomJob::dispatch((int) $orderId);
                } else {
                    // FIX #16: use generic ecom push jobs, not Shopify-specific
                    if ($order['state'] === 'cancel') {
                        PushCancellationToEcomJob::dispatch($orderId);
                    } else {
                        PushFulfillmentToEcomJob::dispatch($order);
                    }
                }

                if (($order['write_date'] ?? '') > $latestWriteDate) {
                    $latestWriteDate = $order['write_date'];
                }
            }

            $state->update([
                'is_running'          => false,
                'last_erp_write_date' => $latestWriteDate,  // FIX: correct column name
            ]);

            Log::info("FetchErpOrdersJob [{$erp->driverName()}]: processed " . count($orders) . ' orders.');
        } catch (\Throwable $e) {
            $state->update(['is_running' => false, 'notes' => $e->getMessage()]);
            throw $e;
        }
    }
}
