<?php

namespace App\Console\Commands;

use App\Services\Sync\ScheduledSyncRunner;
use Illuminate\Console\Command;

class SyncAll extends Command
{
    protected $signature = 'sync:all
                            {--only= : Run a single step: products, inventory, customers, orders, dispatch}
                            {--dry-run : Print planned steps without running}';

    protected $description = 'Run scheduled sync in order (products → inventory → customers → orders → dispatch) using the same logic as the dashboard UI.';

    public function handle(ScheduledSyncRunner $runner): int
    {
        $only = $this->option('only');

        if ($this->option('dry-run')) {
            $this->info('Would run scheduled sync (UI fetch + post for each enabled entity):');
            $this->line('  1. Products   — direction from product_sync_mode');
            $this->line('  2. Inventory  — direction from inventory_sync_mode');
            $this->line('  3. Customers  — direction from customer_sync_mode');
            $this->line('  4. Orders     — direction from sales_order_sync_mode');
            $this->line('  5. Dispatch   — follows sales order direction');
            if ($only) {
                $this->line("  (--only={$only})");
            }

            return self::SUCCESS;
        }

        $this->info('Starting scheduled sync (UI-equivalent fetch + post)...');

        $results = $runner->runAll($only ?: null);

        foreach ($results as $entity => $result) {
            $level   = $result['level'] ?? 'info';
            $message = $result['message'] ?? '';

            match ($level) {
                'error'   => $this->error("[{$entity}] {$message}"),
                'warning' => $this->warn("[{$entity}] {$message}"),
                'skipped' => $this->line("[{$entity}] skipped — {$message}"),
                default   => $this->info("[{$entity}] {$message}"),
            };
        }

        $this->info('Scheduled sync finished.');

        return self::SUCCESS;
    }
}
