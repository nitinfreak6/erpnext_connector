<?php

namespace App\Console\Commands;

use App\Jobs\Erp\FetchErpProductsJob;
use App\Services\SettingsService;
use Illuminate\Console\Command;

class SyncAmazonProducts extends Command
{
    protected $signature = 'sync:amazon-products
                            {--full : Sync all active products (ignore cursor)}
                            {--limit=0 : Max products to process}
                            {--dry-run : Show what would sync without pushing}
                            {--force : Run even when product sync is disabled}';

    protected $description = 'Sync ERP products → Amazon. Fetches from ERP, caches JSON, pushes to Amazon.';

    public function handle(SettingsService $settings): int
    {
        if (!$settings->isProductSyncEnabled() && !$this->option('force')) {
            $this->warn('Product sync is DISABLED in settings. Use --force to override.');
            return self::SUCCESS;
        }

        if (!$settings->isAmazonChannelEnabled()) {
            $this->warn('Amazon channel is DISABLED in settings.');
            return self::SUCCESS;
        }

        $full   = (bool) $this->option('full');
        $limit  = (int) $this->option('limit');
        $dryRun = $this->option('dry-run');

        $this->info('Amazon product sync' . ($full ? ' (FULL)' : ' (incremental)') . ($dryRun ? ' [DRY RUN]' : ''));

        if ($dryRun) {
            $this->warn('Dry-run: would dispatch FetchErpProductsJob targeting Amazon only.');
            return self::SUCCESS;
        }

        // FIX: dispatches FetchErpProductsJob (the fixed, driver-agnostic job).
        // Amazon push is handled inside dispatchPushJobs() when isAmazonChannelEnabled() = true.
        // The ecom push job is skipped if the active ecom driver has no push job registered,
        // but that won't affect Amazon since it's dispatched separately.
        FetchErpProductsJob::dispatch(
            fullSync: $full || $limit > 0,
        );

        $this->info('FetchErpProductsJob dispatched (Amazon + active ecom driver).');
        $this->line('Run <info>php artisan queue:work --queue=sync</info> to process.');

        return self::SUCCESS;
    }
}
