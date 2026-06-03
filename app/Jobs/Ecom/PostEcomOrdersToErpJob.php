<?php

namespace App\Jobs\Ecom;

use App\Models\SyncLog;
use App\Models\SyncMapping;
use App\Services\SettingsService;
use App\Services\Sync\OrderSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * MANUAL: Post fetched (pending) orders from cache to ERP.
 * Only processes orders with ecom_status = 'pending' (fetched but not posted).
 *
 * Pair with FetchEcomOrdersOnlyJob for the manual two-step flow.
 * Cron uses FetchEcomOrdersJob which does both in one step.
 */
class PostEcomOrdersToErpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Optionally target a single order
    public function __construct(private readonly ?string $ecomId = null)
    {
        $this->onQueue('sync');
    }

    public function handle(OrderSyncService $orderSync, SettingsService $settings): void
    {
        $driver = $settings->ecomDriver();

        if ($this->ecomId) {
            // Single order post
            $mappings = SyncMapping::whereIn('entity_type', ['order', 'sales_order'])
                ->where('ecom_id', $this->ecomId)
                ->get();
        } else {
            // All pending orders
            $mappings = SyncMapping::whereIn('entity_type', ['order', 'sales_order'])
                ->where('ecom_status', 'pending')
                ->whereNotNull('metadata')
                ->get();
        }

        $posted = 0;
        $failed = 0;

        foreach ($mappings as $mapping) {
            // metadata is cast as 'array' on SyncMapping — Eloquent already decoded it.
            // Calling json_decode() on an array returns null; guard both cases.
            $rawOrder = is_array($mapping->metadata)
                ? $mapping->metadata
                : json_decode($mapping->metadata, true);

            if (empty($rawOrder) || !is_array($rawOrder)) {
                Log::warning("PostEcomOrdersToErpJob: no cached data for ecom#{$mapping->ecom_id}");
                continue;
            }

            try {
                $erpId = $orderSync->syncEcomOrderToErp($rawOrder);

                $mapping->update([
                    'erp_id'              => $erpId,
                    'ecom_status'         => 'posted',
                    'last_sync_direction' => 'ecom_to_erp',
                    'last_synced_at'      => now(),
                ]);

                Log::info("PostEcomOrdersToErpJob [{$driver}]: posted ecom#{$mapping->ecom_id} → ERP #{$erpId}");
                $posted++;

            } catch (\Throwable $e) {
                $mapping->update(['ecom_status' => 'failed']);

                SyncLog::create([
                    'direction'       => SyncLog::DIRECTION_ECOM_TO_ERP,
                    'entity_type'     => 'sales_order',
                    'entity_id'       => $mapping->ecom_id,
                    'action'          => 'create',
                    'status'          => SyncLog::STATUS_FAILED,
                    'error_message'   => $e->getMessage(),
                    'request_payload' => $mapping->metadata,
                ]);

                Log::error("PostEcomOrdersToErpJob: failed ecom#{$mapping->ecom_id}: " . $e->getMessage());
                $failed++;
            }
        }

        Log::info("PostEcomOrdersToErpJob [{$driver}]: done. Posted: {$posted}, Failed: {$failed}");
    }
}