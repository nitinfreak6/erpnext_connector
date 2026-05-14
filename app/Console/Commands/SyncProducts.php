<?php

namespace App\Console\Commands;

use App\Jobs\Odoo\FetchOdooProductsJob;
use App\Services\SettingsService;
use App\Services\Odoo\OdooProductService;
use Illuminate\Console\Command;

class SyncProducts extends Command
{
    protected $signature = 'sync:products
                            {--full : Ignore cursor, sync all active products}
                            {--limit=0 : Max number of products to process (0 = unlimited)}
                            {--dry-run : Print products without dispatching jobs}';

    protected $description = 'Sync ERP products → Shopify';

    public function handle(OdooProductService $odooProducts, SettingsService $settings): int
    {
        // ── Master switch check ──────────────────────────────────────────
        if (!$settings->isProductSyncEnabled()) {
            $this->warn('Product sync is disabled in Global Settings (Enable Product Sync = off). Skipping.');
            return self::SUCCESS;
        }

        if (!$settings->isShopifyChannelEnabled()) {
            $this->warn('Shopify channel is disabled in Global Settings. Skipping.');
            return self::SUCCESS;
        }

        $full   = $this->option('full');
        $limit  = (int) $this->option('limit');
        $dryRun = $this->option('dry-run');

        $this->info('Starting product sync' . ($full ? ' (FULL)' : '') . ($dryRun ? ' [DRY RUN]' : '') . '...');

        if ($dryRun) {
            $products = $odooProducts->getAllActive(0, $limit ?: 100);

            $this->table(['ID', 'Name', 'Write Date'], array_map(fn($p) => [
                $p['id'], $p['name'], $p['write_date'] ?? '',
            ], $products));

            $this->info(count($products) . ' product(s) would be synced.');
            return self::SUCCESS;
        }

        FetchOdooProductsJob::dispatch(
            fullSync: $full || $limit > 0,
            shopify:  true,
            amazon:   false,
        );

        $this->info('FetchOdooProductsJob dispatched to queue.');
        $this->line("Run <info>php artisan queue:work --queue=sync</info> to process.");

        return self::SUCCESS;
    }
}