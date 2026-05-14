<?php

namespace App\Console\Commands;

use App\Jobs\Erp\FetchErpOrdersJob;
use App\Jobs\Shopify\ImportShopifyOrdersJob;
use App\Models\SyncQueueState;
use Illuminate\Console\Command;

class SyncOrders extends Command
{
    protected $signature = 'sync:orders
                            {mode? : Optional mode alias (supports "full")}
                            {--full : Reset cursor and check all ERP orders}
                            {--dry-run : Print orders without dispatching}
                            {--import-shopify : Import orders from Shopify into ERP}
                            {--limit=50 : Number of Shopify orders to import when using --import-shopify}';

    protected $description = 'Sync orders between ERP and Shopify';

    public function handle(): int
    {
        $mode          = (string) ($this->argument('mode') ?? '');
        $full          = $this->option('full') || strtolower($mode) === 'full';
        $dryRun        = (bool) $this->option('dry-run');
        $importShopify = (bool) $this->option('import-shopify');
        $limit         = max(1, (int) $this->option('limit'));

        $this->info('Starting order sync...' . ($dryRun ? ' [DRY RUN]' : ''));

        if ($dryRun && !$importShopify) {
            $this->warn('Dry-run: would fetch ERP orders modified since last cursor and push fulfillments/cancellations to Shopify.');
            return self::SUCCESS;
        }

        if ($importShopify) {
            if ($dryRun) {
                $this->warn("Dry-run: would import up to {$limit} Shopify orders into ERP.");
                return self::SUCCESS;
            }

            ImportShopifyOrdersJob::dispatchSync($limit, false);
            $this->info("Shopify order import completed (limit: {$limit}).");
            return self::SUCCESS;
        }

        if ($full) {
            SyncQueueState::forType('orders')->update([
                'last_odoo_write_date' => null,
                'is_running'           => false,
            ]);
        }

        FetchErpOrdersJob::dispatch()->onQueue('sync');

        $this->info('Order sync job dispatched.');

        return self::SUCCESS;
    }
}
