<?php

namespace App\Jobs\Erp;

use App\Jobs\Amazon\PushInventoryToAmazonJob;
use App\Jobs\Ecom\PushInventoryToEcomJob;
use App\Models\ProductCache;
use App\Models\SyncMapping;
use App\Models\SyncQueueState;
use App\Services\Erp\ErpInterface;
use App\Services\SettingsService;
use App\Services\Sync\InventorySyncService;
use App\Services\Sync\SyncEntityState;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchErpInventoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // $autoPush: when false (manual Fetch Stock button), only fetch and store as pending.
    //            when true  (scheduled/cron runs), fetch + immediately dispatch push jobs.
    public function __construct(
        private readonly ?int  $locationId = null,
        private readonly bool  $autoPush   = true,
    ) {
        $this->onQueue('sync');
    }

    public function handle(
        ErpInterface $erp,
        SettingsService $settings,
        InventorySyncService $inventorySync,
    ): void {
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
            if ($this->autoPush) {
                $writeDate = $state->getErpWriteDate();
                $locationId = $this->locationId;

                if ($locationId === null) {
                    $locationId = $erp->defaultInventoryFetchLocationId();
                }

                Log::info("FetchErpInventoryJob: cursor={$writeDate} locationId=" . ($locationId ?? 'null'));

                $quants = $erp->getInventoryModifiedSince($writeDate, $locationId);
            } else {
                Log::info('FetchErpInventoryJob: manual fetch — synced products at mapped warehouse');

                $quants = $inventorySync->collectQuantsForSyncedErpProducts();
            }

            Log::info('FetchErpInventoryJob: raw quants count=' . count($quants));

            $latestWriteDate = $this->autoPush ? ($state->getErpWriteDate()) : '2000-01-01 00:00:00';
            $stored          = 0;
            $skipped         = 0;
            $completionNotes = null;

            if (!$this->autoPush && $quants === []) {
                $hasSyncedProducts = SyncMapping::query()
                    ->where('entity_type', 'product')
                    ->where('erp_driver', $settings->erpDriver())
                    ->whereNotNull('erp_id')
                    ->whereNotNull('ecom_id')
                    ->exists()
                    || ProductCache::query()
                        ->whereNotNull('ecom_product_id')
                        ->where('ecom_product_id', '!=', '')
                        ->exists();

                $completionNotes = $hasSyncedProducts
                    ? 'nothing_changed'
                    : 'error:no_synced_products';
            }

            foreach ($quants as $quant) {
                if ($this->autoPush) {
                    PushInventoryToEcomJob::dispatch($quant);

                    if ($settings->isAmazonChannelEnabled()) {
                        PushInventoryToAmazonJob::dispatch($quant);
                    }
                    $stored++;
                } else {
                    $erpId = $inventorySync->inventorySyncErpIdFromQuant($quant);
                    if ($erpId === '' || $erpId === '0') {
                        Log::warning('FetchErpInventoryJob: quant missing ERP product reference', [
                            'quant_id' => $quant['id'] ?? null,
                            'driver'   => $erp->driverName(),
                        ]);
                        continue;
                    }

                    $existing = SyncMapping::where('entity_type', 'inventory')
                        ->where('erp_id', $erpId)
                        ->where('erp_driver', $settings->erpDriver())
                        ->first();

                    $changed = SyncEntityState::markFetched(
                        'inventory',
                        [
                            'erp_id'     => $erpId,
                            'erp_driver' => $settings->erpDriver(),
                        ],
                        $quant,
                        $existing,
                        'erp_to_ecom'
                    );

                    if ($changed) {
                        $stored++;
                    } else {
                        $skipped++;
                    }
                }

                if (!empty($quant['write_date']) && $quant['write_date'] > $latestWriteDate) {
                    $latestWriteDate = $quant['write_date'];
                }
            }

            if (!$this->autoPush && $completionNotes === null) {
                $completionNotes = $stored === 0 ? 'nothing_changed' : "fetched:{$stored}";
                if ($skipped > 0) {
                    $completionNotes .= ":skipped:{$skipped}";
                }
            }

            if ($this->autoPush && $latestWriteDate !== $state->getErpWriteDate()) {
                $latestWriteDate = date('Y-m-d H:i:s', strtotime($latestWriteDate) + 1);
            }

            if ($this->autoPush) {
                $state->markComplete($latestWriteDate, $completionNotes);
            } else {
                $state->update([
                    'is_running'     => false,
                    'last_poll_at'   => now(),
                    'run_started_at' => null,
                    'notes'          => $completionNotes,
                ]);
            }

            Log::info("FetchErpInventoryJob [{$erp->driverName()}]: stored={$stored} skipped={$skipped}");
        } catch (\Throwable $e) {
            $state->update(['is_running' => false, 'run_started_at' => null, 'notes' => $e->getMessage()]);
            throw $e;
        }
    }
}
