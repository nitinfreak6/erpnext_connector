<?php

namespace App\Console\Commands;

use App\Jobs\Ecom\FetchEcomCustomersJob;
use App\Jobs\Erp\FetchErpCustomersJob;
use App\Services\SettingsService;
use Illuminate\Console\Command;

class SyncCustomers extends Command
{
    protected $signature = 'sync:customers
                            {--full : Reset cursor and sync all customers}
                            {--dry-run : Print without syncing}';

    protected $description = 'Sync customers bidirectionally based on settings';

    public function handle(SettingsService $settings): int
    {
        $this->info('Starting customer sync...' . ($this->option('dry-run') ? ' [DRY RUN]' : ''));

        if ($this->option('dry-run')) {
            $mode = $settings->customerSyncMode();
            $this->warn("Dry-run: would sync customers in '{$mode}' mode.");
            return self::SUCCESS;
        }

        if ($this->option('full')) {
            \App\Models\SyncQueueState::forType('customers')->update([
                'last_erp_write_date' => null,
                'last_ecom_write_date' => null,
                'is_running' => false,
            ]);
        }

        $mode = $settings->customerSyncMode();
        
        // Dispatch jobs based on sync mode
        if ($mode === 'erp_to_ecom' || $mode === 'bidirectional') {
            FetchErpCustomersJob::dispatch()->onQueue('sync');
            $this->info('Dispatched: ERP → Ecom customer sync');
        }
        
        if ($mode === 'ecom_to_erp' || $mode === 'bidirectional') {
            FetchEcomCustomersJob::dispatch()->onQueue('sync');
            $this->info('Dispatched: Ecom → ERP customer sync');
        }

        if ($mode === 'disabled') {
            $this->warn('Customer sync is disabled in settings.');
        }

        $this->info('Customer sync completed.');

        return self::SUCCESS;
    }
}