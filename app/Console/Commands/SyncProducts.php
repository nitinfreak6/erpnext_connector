<?php

namespace App\Console\Commands;

use App\Jobs\Ecom\FetchEcomProductsJob;
use App\Jobs\Erp\FetchErpProductsJob;
use App\Models\SyncQueueState;
use App\Services\SettingsService;
use Illuminate\Console\Command;

class SyncProducts extends Command
{
    protected $signature = 'sync:products
                            {--full    : Ignore cursor and sync ALL active products}
                            {--dry-run : Print state without dispatching}
                            {--status  : Show current cursor and queue state, then exit}
                            {--force   : Run even when product sync is disabled in settings}';

    protected $description = 'Sync products — incremental by default (new and updated only).';

    public function handle(SettingsService $settings): int
    {
        if ($this->option('status')) {
            $state = SyncQueueState::forType('products');
            $this->table(['Field', 'Value'], [
                ['ERP cursor (last write_date)',  $state->last_erp_write_date  ?? $state->attributes['last_odoo_write_date'] ?? '<none>'],
                ['Ecom cursor (last updated_at)', $state->last_ecom_write_date ?? '<none>'],
                ['Last polled',                   $state->last_poll_at?->diffForHumans() ?? 'never'],
                ['Currently running',             $state->is_running ? 'YES' : 'no'],
                ['Last error',                    $state->notes ?? '-'],
            ]);
            return self::SUCCESS;
        }

        if (!$settings->isProductSyncEnabled() && !$this->option('force')) {
            $this->warn('Product sync is DISABLED in settings (product_sync_enabled = off).');
            $this->line('  Run with <comment>--force</comment> to override.');
            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $mode = $settings->productSyncMode();
            $this->info("Dry run — would sync products in '{$mode}' mode (incremental, new/updated only).");
            return self::SUCCESS;
        }

        $full = (bool) $this->option('full');
        $mode = $settings->productSyncMode();

        if ($full) {
            SyncQueueState::forType('products')->update([
                'last_erp_write_date'  => null,
                'last_ecom_write_date' => null,
                'is_running'           => false,
            ]);
            $this->info('Cursors reset — full sync will run.');
        }

        if ($mode === 'erp_to_ecom' || $mode === 'bidirectional') {
            $this->info('Dispatching ERP → Ecom product sync' . ($full ? ' (full)' : ' (incremental — new/updated only)') . '...');
            FetchErpProductsJob::dispatch(fullSync: $full)->onQueue('sync');
        }

        if ($mode === 'ecom_to_erp' || $mode === 'bidirectional') {
            $this->info('Dispatching Ecom → ERP product sync' . ($full ? ' (full)' : ' (incremental — new/updated only)') . '...');
            FetchEcomProductsJob::dispatch(fullSync: $full)->onQueue('sync');
        }

        if ($mode === 'disabled') {
            $this->warn('Product sync mode is set to disabled.');
        }

        $this->info('Done.');
        return self::SUCCESS;
    }
}
