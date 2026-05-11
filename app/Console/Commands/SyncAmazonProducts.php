<?php

namespace App\Console\Commands;

use App\Jobs\Odoo\FetchOdooProductsJob;
use App\Services\Odoo\OdooProductService;
use Illuminate\Console\Command;

class SyncAmazonProducts extends Command
{
    protected $signature = 'sync:amazon-products
                            {--full : Sync all active products (ignore cursor)}
                            {--limit=0 : Max products to process}
                            {--dry-run : Show what would sync without pushing}';

    protected $description = 'Sync Odoo products → Amazon (fetches once, caches JSON, no repeat Odoo calls)';

    public function handle(OdooProductService $odooProducts): int
    {
        $full   = $this->option('full');
        $limit  = (int) $this->option('limit');
        $dryRun = $this->option('dry-run');

        $this->info('Amazon product sync' . ($full ? ' (FULL)' : '') . ($dryRun ? ' [DRY RUN]' : ''));

        if ($dryRun) {
            $products = $odooProducts->getAllActive(0, $limit ?: 100);

            $this->table(['ID', 'Name'], array_map(fn($p) => [
                $p['id'], $p['name'],
            ], $products));

            $this->info(count($products) . ' product(s) would be synced.');
            return self::SUCCESS;
        }

        // ── Always dispatch FetchOdooProductsJob ─────────────────────────
        // This job:
        //   1. Fetches products from Odoo ONCE
        //   2. Saves each to storage/app/products/{id}.json
        //   3. Dispatches PushProductToAmazonJob(odooId) per product
        //   4. PushProductToAmazonJob reads from JSON — zero further Odoo calls
        //
        // If JSON already exists for a product (from a previous sync:products run),
        // Odoo is still called once here to refresh the cache before pushing to Amazon.
        FetchOdooProductsJob::dispatch(
            fullSync: $full || $limit > 0,
            shopify:  false,   // sync:amazon-products is Amazon only
            amazon:   true,
        );

        $this->info('FetchOdooProductsJob dispatched to queue (Amazon only).');
        $this->line("Run <info>php artisan queue:work --queue=sync</info> to process.");

        return self::SUCCESS;
    }
}