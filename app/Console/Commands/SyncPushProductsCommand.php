<?php

namespace App\Console\Commands;

use App\Models\ProductCache;
use App\Services\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class SyncPushProductsCommand extends Command
{
    protected $signature = 'sync:push-products
                            {--only-new         : Only push products not yet sent (pending/failed)}
                            {--dry-run          : Show what would be pushed without dispatching}
                            {--force            : Push even when product sync is disabled}';

    protected $description = 'Push cached ERP product JSON files to the active ecom platform. No ERP API calls.';

    public function handle(SettingsService $settings): int
    {
        if (!$settings->isProductSyncEnabled() && !$this->option('force')) {
            $this->warn('Product sync is DISABLED in settings. Use --force to override.');
            return self::SUCCESS;
        }

        $mode = $settings->productSyncMode();

        if ($mode === 'ecom_to_erp') {
            $this->warn("Product sync direction is '{$mode}' — products flow from ecommerce to ERP.");
            $this->line('  Use <comment>sync:pull-products-from-ecom</comment> instead.');
            return self::SUCCESS;
        }

        $onlyNew = $this->option('only-new');
        $dryRun  = $this->option('dry-run');

        // Resolve active ecom push job from the connector registry — no hardcoded Shopify
        $ecomDriver   = $settings->ecomDriver();
        $ecomJobClass = app(\App\Services\ConnectorRegistry::class)->job($ecomDriver, 'push_product');

        if (!$ecomJobClass) {
            $this->error("No push job registered for ecom driver [{$ecomDriver}].");
            return self::FAILURE;
        }
        $amazonEnabled  = $settings->isAmazonChannelEnabled();

        // Build query — FIX: use ecom_status (generic column), fall back to shopify_status
        $query = ProductCache::query();

        if ($onlyNew) {
            $query->where(function ($q) {
                $q->whereNull('ecom_status')
                  ->orWhereIn('ecom_status', [ProductCache::STATUS_PENDING, ProductCache::STATUS_FAILED])
                  ->orWhereNull('shopify_status')
                  ->orWhereIn('shopify_status', [ProductCache::STATUS_PENDING, ProductCache::STATUS_FAILED]);
            });
        }

        // FIX: order by erp_id, fall back to odoo_id
        $caches = $query->orderByRaw('COALESCE(erp_id, odoo_id)')->get();

        if ($caches->isEmpty()) {
            $this->warn('No cached products found. Run <comment>sync:products</comment> first to fetch from ERP.');
            return self::SUCCESS;
        }

        $pushed     = 0;
        $noJsonFile = 0;

        foreach ($caches as $cache) {
            // FIX: use erp_id ?? odoo_id generically
            $erpId    = (int) ($cache->erp_id ?? $cache->odoo_id);
            $filePath = 'products/' . $erpId . '.json';

            if (!Storage::disk('local')->exists($filePath)) {
                $noJsonFile++;
                if ($dryRun) {
                    $this->line("  <comment>SKIP</comment> #{$erpId} {$cache->name} — JSON file missing");
                }
                continue;
            }

            if ($dryRun) {
                // FIX: use ecom_status ?? shopify_status generically
                $ecomStatus   = $cache->ecom_status   ?? $cache->shopify_status  ?? 'pending';
                $amazonStatus = $cache->amazon_status ?? 'pending';
                $this->line("  <info>PUSH</info> #{$erpId} {$cache->name} [{$ecomDriver}:{$ecomStatus} amazon:{$amazonStatus}]");
                $pushed++;
                continue;
            }

            // Push to active ecom driver
            $ecomJobClass::dispatch($erpId)->onQueue('sync');

            // Push to Amazon if enabled (secondary channel)
            if ($amazonEnabled) {
                \App\Jobs\Amazon\PushProductToAmazonJob::dispatch($erpId)->onQueue('sync');
            }

            $pushed++;
        }

        if ($dryRun) {
            $this->info("[DRY RUN] Would push: {$pushed} | Missing JSON: {$noJsonFile}");
            return self::SUCCESS;
        }

        $this->info("Dispatched: {$pushed} | Missing JSON: {$noJsonFile}");

        if ($pushed > 0) {
            $this->line('Run <info>php artisan queue:work --queue=sync</info> to process.');
        }

        return self::SUCCESS;
    }
}
