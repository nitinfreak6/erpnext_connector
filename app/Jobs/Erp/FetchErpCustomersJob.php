<?php

namespace App\Jobs\Erp;

use App\Jobs\Ecom\PushCustomerToEcomJob;
use App\Models\SyncQueueState;
use App\Services\Erp\ErpInterface;
use App\Services\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchErpCustomersJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 3600;

    public function __construct()
    {
        $this->onQueue('sync');
    }

    public function handle(ErpInterface $erp, SettingsService $settings): void
    {
        // FIX #13: check enable flag
        if (!$settings->isCustomerSyncEnabled()) {
            Log::info('FetchErpCustomersJob: skipped — customer sync is disabled in settings.');
            return;
        }

        $syncMode = $settings->customerSyncMode();

        if ($syncMode === 'ecom_to_erp') {
            Log::info('FetchErpCustomersJob: skipped — mode is ecom_to_erp.');
            return;
        }

        $state = SyncQueueState::forType('customers');

        if ($state->is_running) {
            Log::warning('FetchErpCustomersJob: previous run still active, skipping.');
            return;
        }

        $state->markRunning();

        try {
            // FIX: use getErpWriteDate() — reads last_erp_write_date
            $writeDate = $state->getErpWriteDate();

            $customers       = $erp->getCustomersModifiedSince($writeDate);
            $latestWriteDate = $writeDate;

            foreach ($customers as $customer) {
                PushCustomerToEcomJob::dispatch($customer);

                if (($customer['write_date'] ?? '') > $latestWriteDate) {
                    $latestWriteDate = $customer['write_date'];
                }
            }

            $state->markComplete($latestWriteDate);

            Log::info("FetchErpCustomersJob [{$erp->driverName()}]: dispatched " . count($customers) . ' customer jobs.');
        } catch (\Throwable $e) {
            $state->update(['is_running' => false, 'notes' => $e->getMessage()]);
            throw $e;
        }
    }
}
