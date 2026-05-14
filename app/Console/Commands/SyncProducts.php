<?php

namespace App\Console\Commands;

use App\Jobs\Erp\FetchErpProductsJob;
use App\Services\Erp\ErpInterface;
use Illuminate\Console\Command;

class SyncProducts extends Command
{
    protected $signature = 'sync:products
                            {--full : Ignore cursor, sync all active products}
                            {--limit=0 : Max number of products to process (0 = unlimited)}
                            {--dry-run : Print products without dispatching jobs}';

    protected $description = 'Sync ERP products → Shopify (fetches once, caches JSON, no repeat ERP calls)';

    public function handle(ErpInterface $erp): int
    {
        $full   = $this->option('full');
        $limit  = (int) $this->option('limit');
        $dryRun = $this->option('dry-run');

        $this->info('Starting product sync [' . $erp->driverName() . ']'
            . ($full ? ' (FULL)' : '')
            . ($dryRun ? ' [DRY RUN]' : '')
            . '...');

        if ($dryRun) {
            $products = $erp->getAllActiveProducts(0, $limit ?: 100);

            $this->table(['ID', 'Name', 'Write Date'], array_map(fn($p) => [
                $p['id'], $p['name'], $p['write_date'] ?? '',
            ], $products));

            $this->info(count($products) . ' product(s) would be synced.');
            return self::SUCCESS;
        }

        FetchErpProductsJob::dispatch(
            fullSync: $full || $limit > 0,
            shopify:  true,
            amazon:   false,
        );

        $this->info('FetchErpProductsJob dispatched to queue.');
        $this->line('Run <info>php artisan queue:work --queue=sync</info> to process.');

        return self::SUCCESS;
    }
}
