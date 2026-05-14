<?php

namespace App\Jobs\Erp;

use App\Jobs\Shopify\PushCustomerToShopifyJob;
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
 * FetchErpCustomersJob
 *
 * Replaces FetchOdooCustomersJob. Uses ErpInterface so it works with any ERP.
 */
class FetchErpCustomersJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 3600;

    public function __construct()
    {
        $this->onQueue('sync');
    }

    public function handle(ErpInterface $erp): void
    {
        $state = SyncQueueState::forType('customers');

        if ($state->is_running) {
            Log::warning('FetchErpCustomersJob: previous run still active, skipping.');
            return;
        }

        $state->markRunning();

        try {
            $writeDate = $state->last_odoo_write_date ?? '2000-01-01 00:00:00';

            $customers = $erp->getCustomersModifiedSince($writeDate);

            $latestWriteDate = $writeDate;

            foreach ($customers as $customer) {
                PushCustomerToShopifyJob::dispatch($customer);

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
