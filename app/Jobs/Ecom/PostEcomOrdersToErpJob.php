<?php

namespace App\Jobs\Ecom;

use App\Models\SyncLog;
use App\Models\SyncMapping;
use App\Services\SettingsService;
use App\Services\Sync\OrderSyncService;
use App\Services\Sync\SyncEntityState;
use App\Services\Sync\UniversalSyncService;
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

    public function handle(OrderSyncService $orderSync, SettingsService $settings, UniversalSyncService $universalSync): void
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
                ->whereIn('ecom_status', SyncEntityState::PUSHABLE_STATUSES)
                ->whereNotNull('ecom_id')
                ->get();
        }

        $posted = 0;
        $failed = 0;

        foreach ($mappings as $mapping) {
            $rawOrder = $mapping->payload();

            if (empty($rawOrder) || !is_array($rawOrder)) {
                SyncEntityState::markFailed('sales_order', array_filter([
                    'ecom_id'     => $mapping->ecom_id,
                    'ecom_driver' => $mapping->ecom_driver ?: $driver,
                ]), 'No fetched order data. Run Fetch from e-commerce first.');
                Log::warning("PostEcomOrdersToErpJob: no cached data for ecom#{$mapping->ecom_id}");
                $failed++;
                continue;
            }

            $mappedPayload = null;

            try {
                $erpId = $orderSync->syncEcomOrderToErp($rawOrder);

                $mapping->update([
                    'erp_id'              => $erpId,
                    'last_sync_direction' => 'ecom_to_erp',
                    'last_synced_at'      => now(),
                ]);

                Log::info("PostEcomOrdersToErpJob [{$driver}]: synced ecom#{$mapping->ecom_id} → ERP #{$erpId}");
                $posted++;

            } catch (\Throwable $e) {
                $short = \App\Services\Sync\SyncErrorFormatter::short($e) ?? 'Sync failed.';
                SyncEntityState::markFailed('sales_order', array_filter([
                    'ecom_id'     => $mapping->ecom_id,
                    'ecom_driver' => $mapping->ecom_driver ?: $driver,
                ]), $short);

                if ($mappedPayload === null) {
                    try {
                        $mappedPayload = $universalSync->buildErpPayloadOnly('sales_order', $rawOrder, 'header');
                    } catch (\Throwable) {
                        $mappedPayload = null;
                    }
                }

                SyncLog::create([
                    'direction'       => SyncLog::DIRECTION_ECOM_TO_ERP,
                    'entity_type'     => 'sales_order',
                    'entity_id'       => $mapping->ecom_id,
                    'action'          => ($mapping->erp_id ? 'update' : 'create'),
                    'status'          => SyncLog::STATUS_FAILED,
                    'error_message'   => $e->getMessage(),
                    'request_payload' => json_encode(
                        [
                            'driver'         => $settings->erpDriver(),
                            'mapped_payload' => $mappedPayload ?? null,
                        ],
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    ),
                    'response_payload' => json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE),
                ]);

                Log::error("PostEcomOrdersToErpJob: failed ecom#{$mapping->ecom_id}: " . $e->getMessage());
                $failed++;
            }
        }

        Log::info("PostEcomOrdersToErpJob [{$driver}]: done. Posted: {$posted}, Failed: {$failed}");
    }
}