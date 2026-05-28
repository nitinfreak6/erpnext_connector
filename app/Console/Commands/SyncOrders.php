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
                            {--dry-run : Print orders without dispatching}
                            {--force : Run even when order sync is disabled}';

    protected $description = 'Sync orders bidirectionally based on settings';

    public function handle(SettingsService $settings): int
    {
        // FIX #14: check enable flag before dispatching
        if (!$settings->isSalesOrderSyncEnabled() && !$this->option('force')) {
            $this->warn('Order sync is DISABLED in settings (sales_order_sync_enabled = off).');
            $this->line('  Run with <comment>--force</comment> to override.');
            return self::SUCCESS;
        }

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
                'last_erp_write_date'  => null,
                'last_ecom_write_date' => null,
                'is_running'           => false,
            ]);
        }

        $mode = $settings->salesOrderSyncMode();

        if ($mode === 'erp_to_ecom' || $mode === 'bidirectional') {
            FetchErpOrdersJob::dispatch()->onQueue('sync');
            $this->info('Dispatched: ERP → Ecom order sync');
        }

        if ($mode === 'ecom_to_erp' || $mode === 'bidirectional') {
            FetchEcomOrdersJob::dispatch()->onQueue('sync');
            $this->info('Dispatched: Ecom → ERP order sync');
        }

        if ($mode === 'disabled') {
            $this->warn('Order sync mode is set to disabled.');
        }

        $this->info('Order sync jobs dispatched.');
        return self::SUCCESS;
    }
}
