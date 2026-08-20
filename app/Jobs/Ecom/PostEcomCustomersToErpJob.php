<?php

namespace App\Jobs\Ecom;

use App\Models\SyncLog;
use App\Models\SyncMapping;
use App\Services\SettingsService;
use App\Services\Sync\CustomerSyncService;
use App\Services\Sync\SyncEntityState;
use App\Services\Sync\UniversalSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * MANUAL: Post fetched (pending) customers from cache to ERP.
 * Pair with FetchEcomCustomersOnlyJob for the two-step manual flow.
 */
class PostEcomCustomersToErpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly ?string $ecomId = null)
    {
        $this->onQueue('sync');
    }

    public function handle(
        CustomerSyncService $customerSync,
        SettingsService $settings,
        UniversalSyncService $universalSync
    ): void {
        $driver = $settings->ecomDriver();

        if ($this->ecomId) {
            $mappings = SyncMapping::where('entity_type', 'customer')
                ->where('ecom_id', $this->ecomId)
                ->get();
        } else {
            $mappings = SyncMapping::where('entity_type', 'customer')
                ->where('last_sync_direction', 'ecom_to_erp')
                ->whereIn('ecom_status', SyncEntityState::PUSHABLE_STATUSES)
                ->whereNotNull('ecom_id')
                ->get();
        }

        $posted = 0;
        $failed = 0;

        foreach ($mappings as $mapping) {
            $rawCustomer = $mapping->payload();

            if (empty($rawCustomer) || !is_array($rawCustomer)) {
                Log::warning("PostEcomCustomersToErpJob: no cached data for ecom#{$mapping->ecom_id}");
                continue;
            }

            $mappedPayload = null;

            try {
                $erpId = $customerSync->syncCustomerToErp($rawCustomer);

                $mapping->update([
                    'erp_id'              => $erpId ?: $mapping->erp_id,
                    'last_sync_direction' => 'ecom_to_erp',
                    'last_synced_at'      => now(),
                ]);

                Log::info("PostEcomCustomersToErpJob [{$driver}]: synced ecom#{$mapping->ecom_id} → ERP #{$erpId}");
                $posted++;
            } catch (\Throwable $e) {
                SyncEntityState::markFailed('customer', [
                    'ecom_id'     => $mapping->ecom_id,
                    'ecom_driver' => $driver,
                ]);

                if ($mappedPayload === null) {
                    try {
                        $mappedPayload = $universalSync->buildErpPayloadOnly('customer', $rawCustomer, 'header');
                    } catch (\Throwable) {
                        $mappedPayload = null;
                    }
                }

                SyncLog::create([
                    'direction'       => SyncLog::DIRECTION_ECOM_TO_ERP,
                    'entity_type'     => 'customer',
                    'entity_id'       => $mapping->ecom_id,
                    'action'          => ($mapping->erp_id ? 'update' : 'create'),
                    'status'          => SyncLog::STATUS_FAILED,
                    'error_message'   => $e->getMessage(),
                    'request_payload' => json_encode(
                        $mappedPayload ?? ['source_customer' => $rawCustomer],
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    ),
                ]);

                Log::error("PostEcomCustomersToErpJob: failed ecom#{$mapping->ecom_id}: " . $e->getMessage());
                $failed++;
            }
        }

        Log::info("PostEcomCustomersToErpJob [{$driver}]: done. Posted: {$posted}, Failed: {$failed}");
    }
}
