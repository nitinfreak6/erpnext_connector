<?php

namespace App\Console\Commands;

use App\Jobs\Amazon\FetchAmazonOrdersJob;
use App\Models\SyncQueueState;
use App\Services\SettingsService;
use Illuminate\Console\Command;

class SyncAmazonOrders extends Command
{
    protected $signature = 'sync:amazon-orders
                            {--full : Reset cursor and re-fetch last 30 days}
                            {--dry-run : Show what would be synced}
                            {--force : Run even when order sync is disabled}';

    protected $description = 'Fetch Amazon orders → create/update in ERP (incremental by cursor)';

    public function handle(SettingsService $settings): int
    {
        if (!$settings->isSalesOrderSyncEnabled() && !$this->option('force')) {
            $this->warn('Order sync is DISABLED in settings. Use --force to override.');
            return self::SUCCESS;
        }

        if (!$settings->isAmazonChannelEnabled()) {
            $this->warn('Amazon channel is DISABLED in settings.');
            return self::SUCCESS;
        }

        $this->info('Amazon order sync...' . ($this->option('dry-run') ? ' [DRY RUN]' : ''));

        if ($this->option('dry-run')) {
            $state = SyncQueueState::forType('amazon_orders');
            $cursor = $state->last_erp_write_date ?? $state->attributes['last_odoo_write_date'] ?? '<none>';
            $this->warn("Dry-run: would fetch Amazon orders updated since cursor [{$cursor}].");
            return self::SUCCESS;
        }

        if ($this->option('full')) {
            // FIX: use last_erp_write_date — not last_odoo_write_date
            SyncQueueState::forType('amazon_orders')->update([
                'last_erp_write_date' => null,
                'is_running'          => false,
            ]);
            $this->info('Cursor reset — will fetch last 30 days.');
        }

        FetchAmazonOrdersJob::dispatchSync();

        $this->info('Amazon order sync completed.');
        return self::SUCCESS;
    }
}
