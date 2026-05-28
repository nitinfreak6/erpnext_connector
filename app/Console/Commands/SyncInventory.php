<?php

namespace App\Console\Commands;

use App\Jobs\Erp\FetchErpInventoryJob;
use App\Models\SyncQueueState;
use App\Services\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncInventory extends Command
{
    protected $signature = 'sync:inventory
                            {--location= : ERP location ID to filter}
                            {--full : Reset cursor and sync all inventory}
                            {--dry-run : Print quants without syncing}
                            {--force : Run even when inventory sync is disabled}';

    protected $description = 'Sync ERP inventory to ecommerce platform';

    public function handle(SettingsService $settings): int
    {
        // FIX #14: check enable flag
        if (!$settings->isInventorySyncEnabled() && !$this->option('force')) {
            $this->warn('Inventory sync is DISABLED in settings (inventory_sync_enabled = off).');
            $this->line('  Run with <comment>--force</comment> to override.');
            return self::SUCCESS;
        }

        $locationId = $this->option('location') ? (int) $this->option('location') : null;
        $full       = $this->option('full');
        $dryRun     = $this->option('dry-run');

        $this->info('Starting inventory sync...' . ($dryRun ? ' [DRY RUN]' : ''));

        if ($dryRun) {
            $mode = $settings->inventorySyncMode();
            $this->warn("Dry-run: would sync inventory in '{$mode}' mode.");
            return self::SUCCESS;
        }

        if ($full) {
            // FIX: correct column name
            SyncQueueState::forType('inventory')->update([
                'last_erp_write_date' => null,
                'is_running'          => false,
            ]);
        }

        FetchErpInventoryJob::dispatchSync($locationId);

        try {
            $queued = DB::table('jobs')->where('queue', 'sync')->count();
            $this->line("Queued jobs on 'sync': {$queued}.");
        } catch (\Throwable) {}

        $this->info('Inventory sync job completed.');
        return self::SUCCESS;
    }
}
