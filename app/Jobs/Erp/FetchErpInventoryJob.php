<?php

namespace App\Jobs\Erp;

use App\Jobs\Ecom\PushInventoryToEcomJob;
use App\Jobs\Amazon\PushInventoryToAmazonJob;
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
            $stored  = 0;
            $skipped = 0;

            foreach ($quants as $quant) {
                if ($this->autoPush) {
                    // Scheduled/cron: dispatch push immediately
                    PushInventoryToEcomJob::dispatch($quant);

                    if ($settings->isAmazonChannelEnabled()) {
                        PushInventoryToAmazonJob::dispatch($quant);
                    }
                    $stored++;
                } else {
                    // Manual Fetch Stock button: store as pending so Post Stock can push later.
                    // Skip if write_date and quantity unchanged.
                    $erpId        = (string) ($quant['product_id'][0] ?? $quant['id'] ?? '');
                    $newWriteDate = $quant['write_date'] ?? null;
                    $newQty       = $quant['quantity'] ?? $quant['qty'] ?? null;

                    $existing = SyncMapping::where('entity_type', 'inventory')
                        ->where('erp_id', $erpId)
                        ->where('erp_driver', $settings->erpDriver())
                        ->first();

                    if ($existing) {
                        $prevMeta      = is_array($existing->metadata) ? $existing->metadata : json_decode($existing->metadata ?? '{}', true);
                        $prevWriteDate = $prevMeta['write_date'] ?? null;
                        $prevQty       = $prevMeta['quantity'] ?? $prevMeta['qty'] ?? null;

                        if ($prevWriteDate !== null && $prevWriteDate === $newWriteDate && $prevQty == $newQty) {
                            Log::debug("FetchErpInventoryJob: erp#{$erpId} unchanged, skipping.");
                            $skipped++;
                            if ($quant['write_date'] > $latestWriteDate) {
                                $latestWriteDate = $quant['write_date'];
                            }
                            continue;
                        }
                    }

                    SyncMapping::updateOrCreate(
                        [
                            'entity_type' => 'inventory',
                            'erp_id'      => $erpId,
                            'erp_driver'  => $settings->erpDriver(),
                        ],
                        [
                            'ecom_status'         => 'pending',
                            'metadata'            => $quant,
                            'last_synced_at'      => now(),
                            'last_sync_direction' => 'erp_to_ecom',
                        ]
                    );
                    $stored++;
                }

                if ($quant['write_date'] > $latestWriteDate) {
                    $latestWriteDate = $quant['write_date'];
                }
            }

            // Build notes for controller message BEFORE markComplete (which can clear notes)
            $completionNotes = null;
            if (!$this->autoPush) {
                $completionNotes = $stored === 0 ? 'nothing_changed' : "fetched:{$stored}";
                if ($skipped > 0) $completionNotes .= ":skipped:{$skipped}";
            }

            // Advance cursor by 1 second — query now uses strict > so this
            // ensures the last-seen write_date is excluded on next run.
            if ($latestWriteDate !== $writeDate) {
                $latestWriteDate = date('Y-m-d H:i:s', strtotime($latestWriteDate) + 1);
            }

            $state->markComplete($latestWriteDate, $completionNotes);

            Log::info("FetchErpInventoryJob [{$erp->driverName()}]: stored={$stored} skipped={$skipped}");
        } catch (\Throwable $e) {
            $state->update(['is_running' => false, 'notes' => $e->getMessage()]);
            throw $e;
        }
    }
}