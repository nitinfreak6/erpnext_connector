<?php

namespace App\Console\Commands;

use App\Jobs\Ecom\FetchEcomProductsJob;
use App\Services\SettingsService;
use Illuminate\Console\Command;

/**
 * Pull products from e-commerce platform to ERP
 * 
 * Usage:
 *   php artisan sync:pull-products-from-ecom
 *   php artisan sync:pull-products-from-ecom --full
 *   php artisan sync:pull-products-from-ecom --limit=50
 *   php artisan sync:pull-products-from-ecom --since="2024-01-01"
 */
class PullProductsFromEcomCommand extends Command
{
    protected $signature = 'sync:pull-products-from-ecom
                            {--full : Fetch all products instead of incremental}
                            {--limit= : Limit number of products to fetch}
                            {--since= : Fetch products updated since this date (YYYY-MM-DD)}
                            {--async : Dispatch as background job instead of running synchronously}';

    protected $description = 'Pull products from e-commerce platform and sync to ERP';

    public function handle(SettingsService $settings): int
    {
        // ── Check if product sync is enabled ────────────────────────────
        if (!$settings->isProductSyncEnabled()) {
            $this->error('Product sync is disabled in settings.');
            return self::FAILURE;
        }

        // ── Check sync direction ────────────────────────────────────────
        $mode = $settings->productSyncMode();
        $driver = $settings->ecomDriver();
        
        if ($mode === 'erp_to_ecom') {
            $this->error("Cannot pull from e-commerce: sync mode is '{$mode}'");
            $this->line("Products are configured to flow FROM ERP TO e-commerce.");
            $this->line("Change product_sync_mode to 'ecom_to_erp' or 'bidirectional' to enable this command.");
            return self::FAILURE;
        }

        // ── Display configuration ───────────────────────────────────────
        $this->info("Pulling products from {$driver} to ERP");
        $this->table(['Setting', 'Value'], [
            ['Sync Mode', $mode],
            ['E-commerce Driver', $driver],
            ['Full Sync', $this->option('full') ? 'Yes' : 'No'],
            ['Limit', $this->option('limit') ?? 'No limit'],
            ['Since', $this->option('since') ?? 'All time'],
        ]);
        $this->newLine();

        // ── Confirm if full sync ────────────────────────────────────────
        if ($this->option('full') && !$this->confirm('This will fetch ALL products. Continue?')) {
            $this->info('Cancelled.');
            return self::SUCCESS;
        }

        // ── Dispatch job ────────────────────────────────────────────────
        $job = new FetchEcomProductsJob(
            fullSync: (bool) $this->option('full'),
            limit: $this->option('limit') ? (int) $this->option('limit') : null,
            updatedSince: $this->option('since'),
        );

        if ($this->option('async')) {
            dispatch($job);
            $this->info('✓ Job dispatched to queue');
            $this->line('Monitor progress: php artisan queue:work');
            return self::SUCCESS;
        }

        // ── Run synchronously ───────────────────────────────────────────
        $this->info('Fetching products...');
        
        try {
            $job->handle(
                app(\App\Services\Ecom\EcomInterface::class),
                app(\App\Services\Sync\ProductSyncService::class),
                $settings
            );
            
            $this->newLine();
            $this->info('✓ Products pulled successfully');
            $this->line('Check logs for details: storage/logs/laravel.log');
            
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error('✗ Failed to pull products');
            $this->line($e->getMessage());
            
            if ($this->option('verbose')) {
                $this->line($e->getTraceAsString());
            }
            
            return self::FAILURE;
        }
    }
}