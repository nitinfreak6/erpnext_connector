<?php

namespace App\Jobs\Erp;

use App\Jobs\Amazon\PushProductToAmazonJob;
use App\Models\SyncQueueState;
use App\Services\Erp\ErpInterface;
use App\Services\ProductCacheService;
use App\Services\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchErpProductsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 600;

    // FIX #15: removed $shopify and $amazon boolean params.
    // Push destinations are resolved from the active driver settings at runtime.
    public function __construct(
        private readonly bool   $fullSync = false,
        private readonly ?array $erpIds   = null,
    ) {}

    public function handle(ErpInterface $erp, ProductCacheService $cache, SettingsService $settings): void
    {
        if (!$settings->isProductSyncEnabled()) {
            Log::info('FetchErpProductsJob: skipped — product sync is disabled in settings.');
            return;
        }

        $mode = $settings->productSyncMode();

        if ($mode === 'ecom_to_erp') {
            Log::info("FetchErpProductsJob: skipped — sync mode is {$mode}.");
            return;
        }

        $state = SyncQueueState::forType('products');

        if ($state->is_running && !$this->erpIds) {
            Log::warning('FetchErpProductsJob: previous run still active, skipping.');
            return;
        }

        if (!$this->erpIds) {
            $state->markRunning();
        }

        try {
            if ($this->erpIds) {
                $this->handleManual($this->erpIds, $cache, $settings);
                return;
            }

            if ($this->fullSync) {
                $this->handleFull($erp, $cache, $state, $settings);
            } else {
                $this->handleIncremental($erp, $cache, $state, $settings);
            }
        } catch (\Throwable $e) {
            if (!$this->erpIds) {
                $state->update(['is_running' => false, 'notes' => $e->getMessage()]);
            }
            throw $e;
        }
    }

    private function handleIncremental(ErpInterface $erp, ProductCacheService $cache, SyncQueueState $state, SettingsService $settings): void
    {
        $writeDate = $state->getErpWriteDate();

        Log::info("FetchErpProductsJob [{$erp->driverName()}]: incremental from {$writeDate}");

        $products = $erp->getProductsModifiedSince($writeDate);

        if (empty($products)) {
            Log::info("FetchErpProductsJob [{$erp->driverName()}]: nothing changed since {$writeDate}.");
            $state->markComplete($writeDate);
            return;
        }

        $latestWriteDate = $writeDate;
        $dispatched      = 0;

        foreach ($products as $product) {
            try {
                $cache->cacheProduct($product);
            } catch (\Throwable $e) {
                Log::warning("FetchErpProductsJob: cache failed for #{$product['id']}: " . $e->getMessage());
            }

            $this->dispatchPushJobs((int) $product['id'], $settings);

            if (($product['write_date'] ?? '') > $latestWriteDate) {
                $latestWriteDate = $product['write_date'];
            }

            $dispatched++;
        }

        $state->markComplete($latestWriteDate);

        Log::info("FetchErpProductsJob [{$erp->driverName()}]: incremental — {$dispatched} products, cursor → {$latestWriteDate}");
    }

    private function handleFull(ErpInterface $erp, ProductCacheService $cache, SyncQueueState $state, SettingsService $settings): void
    {
        $pageSize        = config('sync.product_page_size', 100);
        $offset          = 0;
        $totalPages      = 0;
        $dispatched      = 0;
        $latestWriteDate = '2000-01-01 00:00:00';

        Log::info("FetchErpProductsJob [{$erp->driverName()}]: full sync started.");

        do {
            $products = $erp->getAllActiveProducts($offset, $pageSize);

            if (empty($products)) {
                break;
            }

            foreach ($products as $product) {
                try {
                    $cache->cacheProduct($product);
                } catch (\Throwable $e) {
                    Log::warning("FetchErpProductsJob: cache failed for #{$product['id']}: " . $e->getMessage());
                    continue;
                }

                $this->dispatchPushJobs((int) $product['id'], $settings);

                if (($product['write_date'] ?? '') > $latestWriteDate) {
                    $latestWriteDate = $product['write_date'];
                }

                $dispatched++;
            }

            $offset += count($products);
            $totalPages++;
        } while (count($products) === $pageSize);

        $state->markComplete($latestWriteDate);

        Log::info("FetchErpProductsJob [{$erp->driverName()}]: full sync done — {$dispatched} products.");
    }

    private function handleManual(array $erpIds, ProductCacheService $cache, SettingsService $settings): void
    {
        foreach ($erpIds as $erpId) {
            $data = $cache->read((int) $erpId);

            if (!$data) {
                Log::info("FetchErpProductsJob: no cache for #{$erpId}, fetching from ERP.");
                $cache->fetchAndCacheSingle((int) $erpId);
            }

            $this->dispatchPushJobs((int) $erpId, $settings);
        }

        Log::info('FetchErpProductsJob: manual re-push dispatched for ' . count($erpIds) . ' product(s).');
    }

    // FIX #15: Push destination resolved from active ecom driver — not hardcoded.
    // Add new ecom drivers by adding one entry to the $ecomJobMap array.
    private function dispatchPushJobs(int $erpId, SettingsService $settings): void
    {
        $ecomDriver = $settings->ecomDriver();

        $ecomJobMap = [
            'shopify'     => \App\Jobs\Shopify\PushProductToShopifyJob::class,
            // 'woocommerce' => \App\Jobs\WooCommerce\PushProductToWooCommerceJob::class,
            // 'magento'     => \App\Jobs\Magento\PushProductToMagentoJob::class,
        ];

        if (isset($ecomJobMap[$ecomDriver])) {
            $ecomJobMap[$ecomDriver]::dispatchSync($erpId);
        } else {
            Log::warning("FetchErpProductsJob: no push job registered for ecom driver [{$ecomDriver}].");
        }

        // Amazon is a secondary channel — conditional on its own enable flag
        if ($settings->isAmazonChannelEnabled()) {
            PushProductToAmazonJob::dispatchSync($erpId);
        }
    }
}
