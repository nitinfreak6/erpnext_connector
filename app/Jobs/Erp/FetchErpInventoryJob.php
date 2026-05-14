<?php

namespace App\Jobs\Erp;

use App\Jobs\Amazon\PushInventoryToAmazonJob;
use App\Jobs\Shopify\PushInventoryToShopifyJob;
use App\Models\SyncQueueState;
use App\Services\Erp\ErpInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * FetchErpInventoryJob
 *
 * Replaces FetchOdooInventoryJob. Uses ErpInterface so it works with any ERP.
 */
class FetchErpInventoryJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 300; // 5 min lock

    public function __construct(private readonly ?int $locationId = null)
    {
        $this->onQueue('sync');
    }

    public function handle(ErpInterface $erp): void
    {
        $state = SyncQueueState::forType('inventory');

        // Self-healing stale lock
        $staleAfterMinutes = 15;
        if ($state->is_running) {
            $startedAt = $state->run_started_at;
            if ($startedAt && $startedAt->diffInMinutes(now()) >= $staleAfterMinutes) {
                Log::warning('FetchErpInventoryJob: stale running flag detected, resetting.');
                $state->update(['is_running' => false, 'run_started_at' => null]);
            } else {
                Log::warning('FetchErpInventoryJob: previous run still active, skipping.');
                return;
            }
        }

        $state->markRunning();

        try {
            $writeDate = $state->last_odoo_write_date ?? '2000-01-01 00:00:00';

            $quants = $erp->getInventoryModifiedSince($writeDate, $this->locationId);

            $latestWriteDate = $writeDate;

            foreach ($quants as $quant) {
                PushInventoryToShopifyJob::dispatch($quant);
                PushInventoryToAmazonJob::dispatch($quant);

                if ($quant['write_date'] > $latestWriteDate) {
                    $latestWriteDate = $quant['write_date'];
                }
            }

            $state->markComplete($latestWriteDate);

            Log::info("FetchErpInventoryJob [{$erp->driverName()}]: dispatched " . count($quants) . ' inventory jobs.');
        } catch (\Throwable $e) {
            $state->update(['is_running' => false, 'notes' => $e->getMessage()]);
            throw $e;
        }
    }
}
