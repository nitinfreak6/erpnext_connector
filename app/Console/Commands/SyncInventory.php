<?php

namespace App\Console\Commands;

use App\Jobs\Erp\FetchErpInventoryJob;
use App\Models\SyncQueueState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncInventory extends Command
{
    protected $signature = 'sync:inventory
                            {--location= : ERP location ID to filter}
                            {--full : Reset cursor and sync all inventory}
                            {--dry-run : Print quants without syncing}';

    protected $description = 'Sync ERP inventory → Shopify';

    public function handle(): int
    {
        $locationId = $this->option('location') ? (int) $this->option('location') : null;
        $full       = $this->option('full');
        $dryRun     = $this->option('dry-run');

        $this->info('Starting inventory sync...' . ($dryRun ? ' [DRY RUN]' : ''));

        if ($dryRun) {
            $this->warn('Dry-run mode: use sync:products first to ensure variant mappings exist.');
            return self::SUCCESS;
        }

        if ($full) {
            SyncQueueState::forType('inventory')->update([
                'last_odoo_write_date' => null,
                'is_running'           => false,
            ]);
        }

        FetchErpInventoryJob::dispatchSync($locationId);

        try {
            $queued = DB::table('jobs')->where('queue', 'sync')->count();
            $this->line("Queued jobs on 'sync': {$queued}. Run `php artisan queue:work --queue=sync` to process.");
        } catch (\Throwable) {}

        $this->info('Inventory sync job completed.');

        return self::SUCCESS;
    }
}
