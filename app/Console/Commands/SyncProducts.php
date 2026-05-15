<?php

namespace App\Console\Commands;

use App\Jobs\Erp\FetchErpProductsJob;   // ← changed from FetchOdooProductsJob
use App\Models\SyncQueueState;
use App\Services\Erp\ErpInterface;      // ← changed from OdooProductService
use Illuminate\Console\Command;

class SyncProducts extends Command
{
    protected $signature = 'sync:products
                            {--full    : Ignore write_date cursor and sync ALL active products}
                            {--dry-run : Print state without dispatching}
                            {--status  : Show current cursor and queue state, then exit}';

    protected $description = 'Fetch ERP products → JSON cache only (incremental by default). No push.';

    public function handle(): int
    {
        if ($this->option('status')) {
            $state = SyncQueueState::forType('products');
            $this->table(['Field', 'Value'], [
                ['Last write_date cursor', $state->last_odoo_write_date ?? '<none>'],
                ['Last polled',            $state->last_poll_at?->diffForHumans() ?? 'never'],
                ['Currently running',      $state->is_running ? 'YES' : 'no'],
                ['Last error',             $state->notes ?? '-'],
            ]);
            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run — no jobs dispatched.');
            return self::SUCCESS;
        }

        $full = (bool) $this->option('full');

        $this->info('Fetching products from ERP' . ($full ? ' (FULL)' : ' (incremental)') . '...');

        // fetch only — shopify/amazon flags are FALSE, push is handled by sync:push-products
        FetchErpProductsJob::dispatchSync(
            fullSync: $full,
            shopify:  false,
            amazon:   false,
        );

        $this->info('Done.');
        return self::SUCCESS;
    }
}