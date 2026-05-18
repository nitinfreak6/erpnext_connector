<?php

namespace App\Console\Commands;

use App\Jobs\Amazon\PushProductToAmazonJob;
use App\Jobs\Shopify\PushProductToShopifyJob;
use App\Models\ProductCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class SyncPushProductsCommand extends Command
{
    protected $signature = 'sync:push-products
                            {--channel=shopify  : shopify, amazon, or both}
                            {--only-new         : Only push products not yet sent (pending/failed)}
                            {--dry-run          : Show what would be pushed without dispatching}';

    protected $description = 'Push cached product JSON files to Shopify and/or Amazon. No ERP API calls.';

    public function handle(): int
    {
        $channel = $this->option('channel');
        $onlyNew = $this->option('only-new');
        $dryRun  = $this->option('dry-run');

        // ── Read from product_cache, NOT sync_mappings ────────────────────
        //
        // sync_mappings only contains products already pushed to Shopify.
        // product_cache contains EVERY fetched product (new + existing).
        // New products live in product_cache but have no sync_mappings row yet.
        //
        $query = ProductCache::query();

        if ($onlyNew) {
            // Push only products that haven't been successfully sent yet
            if (in_array($channel, ['shopify', 'both'])) {
                $query->where(function ($q) {
                    $q->whereNull('shopify_status')
                      ->orWhere('shopify_status', ProductCache::STATUS_PENDING)
                      ->orWhere('shopify_status', ProductCache::STATUS_FAILED);
                });
            }
            if ($channel === 'amazon') {
                $query->where(function ($q) {
                    $q->whereNull('amazon_status')
                      ->orWhere('amazon_status', ProductCache::STATUS_PENDING)
                      ->orWhere('amazon_status', ProductCache::STATUS_FAILED);
                });
            }
        }

        $caches = $query->orderBy('odoo_id')->get();

        if ($caches->isEmpty()) {
            $this->warn('No cached products found. Run sync:products first to fetch from ERP.');
            return self::SUCCESS;
        }

        $pushed      = 0;
        $skipped     = 0;
        $noJsonFile  = 0;

        foreach ($caches as $cache) {
            $odooId   = (int) $cache->odoo_id;
            $filePath = 'products/' . $odooId . '.json';

            // JSON file must exist on disk
            if (!Storage::disk('local')->exists($filePath)) {
                $noJsonFile++;
                if ($dryRun) {
                    $this->line("  <comment>SKIP</comment> #{$odooId} {$cache->name} — JSON file missing");
                }
                continue;
            }

            if ($dryRun) {
                $shopifyStatus = $cache->shopify_status ?? 'pending';
                $amazonStatus  = $cache->amazon_status  ?? 'pending';
                $this->line("  <info>PUSH</info> #{$odooId} {$cache->name} [shopify:{$shopifyStatus} amazon:{$amazonStatus}]");
                $pushed++;
                continue;
            }

            if (in_array($channel, ['shopify', 'both'])) {
                PushProductToShopifyJob::dispatch($odooId)->onQueue('sync');
            }
            if (in_array($channel, ['amazon', 'both'])) {
                PushProductToAmazonJob::dispatch($odooId)->onQueue('sync');
            }

            $pushed++;
        }

        if ($dryRun) {
            $this->info("[DRY RUN] Would push: {$pushed} | Missing JSON: {$noJsonFile}");
            return self::SUCCESS;
        }

        $this->info("Dispatched: {$pushed} | Missing JSON: {$noJsonFile}");

        if ($pushed > 0) {
            $this->line("Run <info>php artisan queue:work --queue=sync</info> to process.");
        }

        return self::SUCCESS;
    }
}