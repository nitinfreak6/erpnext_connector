<?php

namespace App\Jobs\Erp;

use App\Jobs\Ecom\PushInventoryToEcomJob;
use App\Jobs\Amazon\PushInventoryToAmazonJob;
use App\Models\SyncQueueState;
use App\Services\Erp\ErpInterface;
use App\Services\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchErpInventoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly ?int $locationId = null)
    {
        $this->onQueue('sync');
    }

    public function handle(ErpInterface $erp, SettingsService $settings): void
    {
        // FIX #13: check enable flag
        if (!$settings->isInventorySyncEnabled()) {
            Log::info('FetchErpInventoryJob: skipped — inventory sync is disabled in settings.');
            return;
        }

        $syncMode = $settings->inventorySyncMode();

        if ($syncMode === 'ecom_to_erp') {
            Log::info('FetchErpInventoryJob: skipped — mode is ecom_to_erp.');
            return;
        }

        $state             = SyncQueueState::forType('inventory');
        $staleAfterMinutes = 15;

        if ($state->is_running) {
            $startedAt = $state->run_started_at;
            if ($startedAt && $startedAt->diffInMinutes(now()) >= $staleAfterMinutes) {
                Log::warning('FetchErpInventoryJob: stale lock, resetting.');
                $state->update(['is_running' => false, 'run_started_at' => null]);
            } else {
                Log::warning('FetchErpInventoryJob: previous run still active, skipping.');
                return;
            }
        }

        $state->markRunning();

        try {
            // FIX: use getErpWriteDate() — reads last_erp_write_date
            $writeDate = $state->getErpWriteDate();

            Log::info("FetchErpInventoryJob: cursor={$writeDate} locationId=" . ($this->locationId ?? 'null'));

            $quants = $erp->getInventoryModifiedSince($writeDate, $this->locationId);

            Log::info("FetchErpInventoryJob: raw quants count=" . count($quants));
            $latestWriteDate = $writeDate;

            foreach ($quants as $quant) {
                // FIX #17: dispatch to generic ecom job — not hardcoded Shopify
                PushInventoryToEcomJob::dispatch($quant);

                // Amazon is a secondary channel, stays conditional
                if ($settings->isAmazonChannelEnabled()) {
                    PushInventoryToAmazonJob::dispatch($quant);
                }

                if ($quant['write_date'] > $latestWriteDate) {
                    $latestWriteDate = $quant['write_date'];
                }
            }

            // Advance cursor by 1 second so next run uses strict > and avoids re-fetching
            $state->markComplete($latestWriteDate);

            Log::info("FetchErpInventoryJob [{$erp->driverName()}]: dispatched " . count($quants) . ' inventory jobs.');
        } catch (\Throwable $e) {
            $state->update(['is_running' => false, 'notes' => $e->getMessage()]);
            throw $e;
        }
    }
}