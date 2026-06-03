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
                    // Order not in ecom yet — push it
                    Log::info("FetchErpOrdersJob: order #{$orderId} not in ecom, pushing");
                    PushOrderToEcomJob::dispatch((int) $orderId);

                } elseif ($order['state'] === 'cancel') {
                    PushCancellationToEcomJob::dispatch($orderId);

                } elseif (!empty($order['picking_ids'])) {
                    // Order has deliveries — resolve the done pickings and push each one.
                    // PushFulfillmentToEcomJob expects a stock.picking record, NOT a sale.order.
                    // Calling it with a sale.order (which has no move_ids) silently produces empty fulfillments.
                    $pickingIds  = $order['picking_ids'];
                    $pickings    = $erp->getPickings($pickingIds);

                    foreach ($pickings as $picking) {
                        if (($picking['state'] ?? '') !== 'done') {
                            continue; // skip in-progress or draft pickings
                        }

                        $picking['erp_order_id'] = (int) $orderId;

                        // Skip if already dispatched
                        $alreadyDone = \App\Models\SyncLog::where('entity_type', 'dispatch')
                            ->where('entity_id', $orderId)
                            ->where('status', \App\Models\SyncLog::STATUS_SUCCESS)
                            ->exists();

                        if ($alreadyDone) {
                            Log::debug("FetchErpOrdersJob: order #{$orderId} already dispatched, skipping picking#{$picking['id']}");
                            continue;
                        }

                        // Resolve Shopify order ID — PushFulfillmentToEcomJob requires _ecom_order_id
                        $orderMapping = SyncMapping::whereIn('entity_type', ['order', 'sales_order'])
                            ->where('erp_id', $orderId)
                            ->first();

                        if (!$orderMapping) {
                            Log::debug("FetchErpOrdersJob: no ecom mapping for sale#{$orderId}, skipping fulfillment");
                            continue;
                        }

                        $picking['_ecom_order_id'] = $orderMapping->ecom_id;

                        PushFulfillmentToEcomJob::dispatch($picking);
                        Log::info("FetchErpOrdersJob: queued fulfillment for order #{$orderId} via picking #{$picking['id']}");
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