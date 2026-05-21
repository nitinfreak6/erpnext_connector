<?php

namespace App\Jobs\Erp;

use App\Jobs\Shopify\PushCancellationToShopifyJob;
use App\Jobs\Shopify\PushFulfillmentToShopifyJob;
use App\Models\SyncQueueState;
use App\Services\Erp\ErpInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * FetchErpOrdersJob
 *
 * Replaces FetchOdooOrdersJob. Also fixes the stale-lock issue and removes
 * the erroneous Order::updateOrCreate() call that referenced a non-existent model.
 */
class FetchErpOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('sync');
    }

    public function handle(ErpInterface $erp): void
    {
        $settings = app(\App\Services\SettingsService::class);
        $syncMode = $settings->salesOrderSyncMode();
        
        // Only run if mode includes ERP → Ecom direction
        if ($syncMode === 'ecom_to_erp') {
            Log::info('FetchErpOrdersJob: Skipped (mode is ecom_to_erp, use ImportShopifyOrdersJob instead)');
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
            $writeDate = $state->last_odoo_write_date ?? '2000-01-01 00:00:00';

            // Determine if we should filter by origin based on sync mode
            // erp_to_ecom: Only fetch ERP-origin orders (exclude ecom imports)
            // bidirectional: Fetch ALL orders (both ERP and ecom-origin for status updates)
            $onlyErpOrigin = ($syncMode === 'erp_to_ecom');
            
            Log::info("FetchErpOrdersJob: Mode={$syncMode}, OnlyErpOrigin=" . ($onlyErpOrigin ? 'true' : 'false'));
            
            $orders = $erp->getOrdersModifiedSince($writeDate, $onlyErpOrigin);

            $latestWriteDate = $writeDate;

            foreach ($orders as $order) {
                $orderId = (string) $order['id'];
                
                // Check if order already has a mapping to ecom
                $mapping = \App\Models\SyncMapping::where('entity_type', 'order')
                    ->where('erp_id', $orderId)
                    ->first();
                
                if (!$mapping) {
                    // No mapping exists - create order in ecom platform
                    Log::info("FetchErpOrdersJob: Order #{$orderId} not mapped, creating in ecom");
                    \App\Jobs\Ecom\PushOrderToEcomJob::dispatch((int) $orderId);
                } else {
                    // Mapping exists - push fulfillment/status updates
                    if ($order['state'] === 'cancel') {
                        PushCancellationToShopifyJob::dispatch($orderId);
                    } else {
                        PushFulfillmentToShopifyJob::dispatch($order);
                    }
                }

                if (($order['write_date'] ?? '') > $latestWriteDate) {
                    $latestWriteDate = $order['write_date'];
                }
            }

            $state->update([
                'is_running'           => false,
                'last_odoo_write_date' => $latestWriteDate,
            ]);

            Log::info("FetchErpOrdersJob [{$erp->driverName()}]: processed " . count($orders) . ' orders.');
        } catch (\Throwable $e) {
            $state->update(['is_running' => false, 'notes' => $e->getMessage()]);
            throw $e;
        }
    }
}