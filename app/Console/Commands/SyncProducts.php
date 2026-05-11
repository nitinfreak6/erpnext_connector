<?php

namespace App\Console\Commands;

use App\Jobs\Odoo\FetchOdooProductsJob;
use App\Services\Odoo\OdooProductService;
use Illuminate\Console\Command;

class SyncProducts extends Command
{
    protected $signature = 'sync:products
                            {--full : Ignore cursor, sync all active products}
                            {--limit=0 : Max number of products to process (0 = unlimited)}
                            {--dry-run : Print products without dispatching jobs}';

    protected $description = 'Sync Odoo products → Shopify (fetches once, caches JSON, no repeat Odoo calls)';

    public function handle(OdooProductService $odooProducts): int
    {
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

        // ── Always dispatch FetchOdooProductsJob ─────────────────────────
        // This job:
        //   1. Fetches products from Odoo ONCE
        //   2. Saves each to storage/app/products/{id}.json
        //   3. Dispatches PushProductToShopifyJob(odooId) per product
        //   4. PushProductToShopifyJob reads from JSON — zero further Odoo calls
        //
        // --full flag is passed through so the job ignores the write_date cursor
        FetchOdooProductsJob::dispatch(
            fullSync: $full || $limit > 0,
            shopify:  true,
            amazon:   false,   // sync:products is Shopify only
        );

        $this->info('FetchOdooProductsJob dispatched to queue.');
        $this->line("Run <info>php artisan queue:work --queue=sync</info> to process.");

        return self::SUCCESS;
    }
}