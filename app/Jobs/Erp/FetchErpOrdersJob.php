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

            $orders = $erp->getOrdersModifiedSince($writeDate);

            $latestWriteDate = $writeDate;

            foreach ($orders as $order) {
                if ($order['state'] === 'cancel') {
                    PushCancellationToShopifyJob::dispatch((string) $order['id']);
                } else {
                    PushFulfillmentToShopifyJob::dispatch($order);
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
