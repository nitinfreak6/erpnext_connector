<?php

namespace App\Console\Commands;

use App\Jobs\Shopify\PushProductToShopifyJob;
use App\Jobs\Amazon\PushProductToAmazonJob;
use App\Models\SyncMapping;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class SyncPushProductsCommand extends Command
{
    protected $signature   = 'sync:push-products
                                {--channel=both : shopify, amazon, or both}';

    protected $description = 'Push cached product JSON files to Shopify and/or Amazon. No ERP API calls.';

    public function handle(): int
    {
        $channel = $this->option('channel');

        $odooIds = SyncMapping::where('entity_type', 'product')
            ->pluck('odoo_id')
            ->toArray();

        $pushed  = 0;
        $skipped = 0;

        foreach ($odooIds as $odooId) {
            if (!Storage::disk('local')->exists('products/' . $odooId . '.json')) {
                $skipped++;
                continue;
            }

            if (in_array($channel, ['shopify', 'both'])) {
                PushProductToShopifyJob::dispatchSync((int) $odooId);
            }
            if (in_array($channel, ['amazon', 'both'])) {
                PushProductToAmazonJob::dispatchSync((int) $odooId);
            }

            $pushed++;
        }

        $this->info("Pushed: {$pushed} | Skipped (no cache): {$skipped}");
        return self::SUCCESS;
    }
}