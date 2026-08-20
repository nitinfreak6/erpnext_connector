<?php

namespace App\Jobs\Erp;

use App\Jobs\Ecom\PushCustomerToEcomJob;
use App\Models\SyncMapping;
use App\Models\SyncQueueState;
use App\Services\Erp\ErpInterface;
use App\Services\SettingsService;
use App\Services\Sync\SyncEntityState;
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

    public function __construct(
        private readonly bool $autoPush = true,
    ) {
        $this->onQueue('sync');
    }

    public function handle(ErpInterface $erp, SettingsService $settings): void
    {
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
            $writeDate       = $state->getErpWriteDate();
            $customers       = $erp->getCustomersModifiedSince($writeDate);
            $latestWriteDate = $writeDate;
            $stored          = 0;
            $skipped         = 0;

            foreach ($customers as $customer) {
                $erpId = (string) ($customer['id'] ?? '');

                if (!$erpId) {
                    continue;
                }

                if ($this->autoPush) {
                    PushCustomerToEcomJob::dispatch($customer);
                    $stored++;
                } else {
                    $existing = SyncMapping::where('entity_type', 'customer')
                        ->where('erp_id', $erpId)
                        ->where('erp_driver', $settings->erpDriver())
                        ->first();

                    if ($existing && !SyncEntityState::changedSinceLastSync(
                        $existing,
                        $customer,
                        fn (array $d) => $d['write_date'] ?? null
                    )) {
                        $skipped++;
                        if (($customer['write_date'] ?? '') > $latestWriteDate) {
                            $latestWriteDate = $customer['write_date'];
                        }
                        continue;
                    }

                    SyncEntityState::markFetched(
                        'customer',
                        ['erp_id' => $erpId, 'erp_driver' => $settings->erpDriver()],
                        $customer,
                        $existing,
                        'erp_to_ecom',
                        fn (array $d) => $d['write_date'] ?? null
                    );

                    $name = isset($customer['name']) && $customer['name'] !== false
                        ? (string) $customer['name']
                        : null;

                    SyncMapping::where('entity_type', 'customer')
                        ->where('erp_id', $erpId)
                        ->where('erp_driver', $settings->erpDriver())
                        ->update(['ecom_handle' => $name ?: ($customer['email'] ?? null)]);

                    $stored++;
                }

                if (($customer['write_date'] ?? '') > $latestWriteDate) {
                    $latestWriteDate = $customer['write_date'];
                }
            }

            $completionNotes = null;
            if (!$this->autoPush) {
                $completionNotes = $stored === 0 ? 'nothing_changed' : "fetched:{$stored}";
                if ($skipped > 0) {
                    $completionNotes .= ":skipped:{$skipped}";
                }
            }

            if ($latestWriteDate !== $writeDate) {
                $latestWriteDate = date('Y-m-d H:i:s', strtotime($latestWriteDate) + 1);
            }

            $state->markComplete($latestWriteDate, $completionNotes);

            Log::info("FetchErpCustomersJob [{$erp->driverName()}]: stored={$stored} skipped={$skipped} autoPush=" . ($this->autoPush ? 'yes' : 'no'));
        } catch (\Throwable $e) {
            $state->update(['is_running' => false, 'notes' => $e->getMessage()]);
            throw $e;
        }
    }
}
