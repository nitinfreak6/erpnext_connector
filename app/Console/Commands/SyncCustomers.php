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
                            {--dry-run : Print without syncing}
                            {--force : Run even when customer sync is disabled}';

    protected $description = 'Sync customers bidirectionally based on settings';

    public function handle(SettingsService $settings): int
    {
        // FIX #14: check enable flag before dispatching
        if (!$settings->isCustomerSyncEnabled() && !$this->option('force')) {
            $this->warn('Customer sync is DISABLED in settings (customer_sync_enabled = off).');
            $this->line('  Run with <comment>--force</comment> to override.');
            return self::SUCCESS;
        }

        $this->info('Starting customer sync...' . ($this->option('dry-run') ? ' [DRY RUN]' : ''));

        if ($this->option('dry-run')) {
            $mode = $settings->customerSyncMode();
            $this->warn("Dry-run: would sync customers in '{$mode}' mode.");
            return self::SUCCESS;
        }

        if ($this->option('full')) {
            \App\Models\SyncQueueState::forType('customers')->update([
                'last_erp_write_date'  => null,
                'last_ecom_write_date' => null,
                'is_running'           => false,
            ]);
        }

        $mode = $settings->customerSyncMode();

        if ($mode === 'erp_to_ecom' || $mode === 'bidirectional') {
            FetchErpCustomersJob::dispatch()->onQueue('sync');
            $this->info('Dispatched: ERP → Ecom customer sync');
        }

        if ($mode === 'ecom_to_erp' || $mode === 'bidirectional') {
            FetchEcomCustomersJob::dispatch()->onQueue('sync');
            $this->info('Dispatched: Ecom → ERP customer sync');
        }

        if ($mode === 'disabled') {
            $this->warn('Customer sync mode is set to disabled.');
        }

        $this->info('Customer sync completed.');
        return self::SUCCESS;
    }
}
