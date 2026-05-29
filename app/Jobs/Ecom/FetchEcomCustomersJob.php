<?php

namespace App\Jobs\Ecom;

use App\Models\SyncMapping;
use App\Models\SyncQueueState;
use App\Services\Ecom\EcomInterface;
use App\Services\SettingsService;
use App\Services\Sync\CustomerSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Pull NEW and UPDATED customers from ecom → ERP.
 *
 * Cursor: last_ecom_write_date in sync_queue_state (type = 'customers').
 * Only customers updated since the last run are fetched — reduces API load.
 */
class FetchEcomCustomersJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 3600;
    public int $timeout   = 600;

    public function __construct()
    {
        $this->onQueue('sync');
    }

    public function handle(EcomInterface $ecom, SettingsService $settings, CustomerSyncService $customerSync): void
    {
        if (!$settings->isCustomerSyncEnabled()) {
            Log::info('FetchEcomCustomersJob: skipped — customer sync disabled.');
            return;
        }

        $syncMode = $settings->customerSyncMode();

        if ($syncMode === 'erp_to_ecom') {
            Log::info('FetchEcomCustomersJob: skipped — mode is erp_to_ecom.');
            return;
        }

        $state = SyncQueueState::forType('customers');

        if ($state->is_running && $state->run_started_at?->gt(now()->subMinutes(30))) {
            Log::warning('FetchEcomCustomersJob: previous run still active, skipping.');
            return;
        }

        $state->update(['is_running' => true, 'run_started_at' => now()]);
        $driver = $ecom->driverName();

        try {
            // Cursor: use last_ecom_write_date — only fetch changed customers
            $since = $state->last_ecom_write_date ?? now()->subDays(30)->toIso8601String();

            Log::info("FetchEcomCustomersJob [{$driver}]: fetching customers updated since {$since}");

            $customers = $this->fetchUpdatedCustomers($ecom, $driver, $since);

            Log::info("FetchEcomCustomersJob [{$driver}]: found " . count($customers) . ' customers.');

            $synced  = 0;
            $updated = 0;
            $failed  = 0;

            foreach ($customers as $customer) {
                try {
                    $ecomId = (string) ($customer['id'] ?? '');

                    if (!$ecomId) {
                        continue;
                    }

                    $mapping = SyncMapping::where('entity_type', 'customer')
                        ->where('ecom_id', $ecomId)
                        ->first();

                    if ($mapping) {
                        // Already exists — update in ERP
                        $customerSync->syncCustomerToErp($customer);
                        $updated++;
                    } else {
                        // New customer
                        $customerSync->syncCustomerToErp($customer);
                        $synced++;
                    }
                } catch (\Throwable $e) {
                    Log::error("FetchEcomCustomersJob [{$driver}]: failed for customer " . ($customer['id'] ?? '?') . ': ' . $e->getMessage());
                    $failed++;
                }
            }

            // Advance cursor
            $state->update([
                'is_running'           => false,
                'last_poll_at'         => now(),
                'last_ecom_write_date' => now()->toIso8601String(),
                'run_started_at'       => null,
                'notes'                => "New: {$synced}, Updated: {$updated}, Failed: {$failed}",
            ]);

            Log::info("FetchEcomCustomersJob [{$driver}]: done. New: {$synced}, Updated: {$updated}, Failed: {$failed}");

        } catch (\Throwable $e) {
            $state->update(['is_running' => false, 'notes' => $e->getMessage()]);
            Log::error("FetchEcomCustomersJob [{$driver}]: job failed — " . $e->getMessage());
            throw $e;
        }
    }

    private function fetchUpdatedCustomers(EcomInterface $ecom, string $driver, string $since): array
    {
        if (!method_exists($ecom, 'getCustomers')) {
            Log::warning("FetchEcomCustomersJob [{$driver}]: getCustomers() not implemented.");
            return [];
        }

        $all     = [];
        $page    = 1;
        $limit   = 250;

        do {
            try {
                $batch = $ecom->getCustomers([
                    'updated_at_min' => $since,  // cursor — only changed records
                    'limit'          => $limit,
                    'page'           => $page,
                ]);

                if (empty($batch)) {
                    break;
                }

                $all  = array_merge($all, $batch);
                $page++;

                // Safety cap
                if (count($all) >= 10000) {
                    Log::warning("FetchEcomCustomersJob [{$driver}]: reached 10k cap.");
                    break;
                }
            } catch (\Throwable $e) {
                Log::error("FetchEcomCustomersJob [{$driver}]: page {$page} failed — " . $e->getMessage());
                break;
            }
        } while (count($batch) === $limit);

        return $all;
    }

    public function failed(\Throwable $exception): void
    {
        SyncQueueState::forType('customers')->update(['is_running' => false]);
    }
}
