<?php

namespace App\Console\Commands;

use App\Jobs\Ecom\FetchEcomOrdersJob;
use App\Jobs\Erp\FetchErpOrdersJob;
use App\Models\SyncQueueState;
use App\Services\SettingsService;
use Illuminate\Console\Command;

class SyncOrders extends Command
{
    protected $signature = 'sync:orders
                            {--full : Reset cursor and check all orders}
                            {--dry-run : Print orders without dispatching}';

    protected $description = 'Sync orders bidirectionally based on settings';

    public function handle(SettingsService $settings): int
    {
        $full   = $this->option('full');
        $dryRun = $this->option('dry-run');

        $this->info('Starting order sync...' . ($dryRun ? ' [DRY RUN]' : ''));

        if ($dryRun) {
            $mode = $settings->salesOrderSyncMode();
            $this->warn("Dry-run: would sync orders in '{$mode}' mode.");
            return self::SUCCESS;
        }

        if ($full) {
            SyncQueueState::forType('orders')->update([
                'last_erp_write_date' => null,
                'last_ecom_write_date' => null,
                'is_running' => false,
            ]);
        }

        $mode = $settings->salesOrderSyncMode();
        
        // Dispatch jobs based on sync mode
        if ($mode === 'erp_to_ecom' || $mode === 'bidirectional') {
            FetchErpOrdersJob::dispatch()->onQueue('sync');
            $this->info('Dispatched: ERP → Ecom order sync');
        }
        
        if ($mode === 'ecom_to_erp' || $mode === 'bidirectional') {
            FetchEcomOrdersJob::dispatch()->onQueue('sync');
            $this->info('Dispatched: Ecom → ERP order sync');
        }

        if ($mode === 'disabled') {
            $this->warn('Order sync is disabled in settings.');
        }

        $this->info('Order sync jobs dispatched.');

        return self::SUCCESS;
    }
}