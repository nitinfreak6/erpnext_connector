<?php

namespace App\Jobs\Erp;

use App\Jobs\Amazon\PushProductToAmazonJob;
use App\Jobs\Shopify\PushProductToShopifyJob;
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

    public function __construct(
        private readonly bool   $fullSync = false,
        private readonly bool   $shopify  = true,
        private readonly bool   $amazon   = true,
        private readonly ?array $erpIds   = null,
    ) {}

    public function handle(ErpInterface $erp, ProductCacheService $cache, SettingsService $settings): void
    {
        // ── Master switch check ─────────────────────────────────────────
        // Always honour the setting, even when the job is dispatched directly
        // (e.g. from the manual sync button or another job).
        if (! $settings->isProductSyncEnabled()) {
            Log::info('FetchErpProductsJob: skipped — product sync is disabled in settings.');
            return;
        }

        // ── Direction check ─────────────────────────────────────────────
        $mode = $settings->productSyncMode();
        
        if ($mode === 'ecom_to_erp') {
            Log::info("FetchErpProductsJob: skipped — sync mode is {$mode} (products should come from ecommerce, not ERP)");
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
                $this->handleManual($this->erpIds, $cache);
                return;
            }

            if ($this->fullSync) {
                $this->handleFull($erp, $cache, $state);
            } else {
                $this->handleIncremental($erp, $cache, $state);
            }
        } catch (\Throwable $e) {
            if (!$this->erpIds) {
                $state->update(['is_running' => false, 'notes' => $e->getMessage()]);
            }
            throw $e;
        }
    }

    // ── Incremental ──────────────────────────────────────────────────────

    private function handleIncremental(ErpInterface $erp, ProductCacheService $cache, SyncQueueState $state): void
    {
        $writeDate = $state->last_odoo_write_date ?? '2000-01-01 00:00:00';

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

            $this->dispatchPushJobs((int) $product['id']);

            if (($product['write_date'] ?? '') > $latestWriteDate) {
                $latestWriteDate = $product['write_date'];
            }

            $dispatched++;
        }

        $state->markComplete($latestWriteDate);

        Log::info("FetchErpProductsJob [{$erp->driverName()}]: incremental — {$dispatched} products cached, cursor → {$latestWriteDate}");
    }

    // ── Full sync ────────────────────────────────────────────────────────

    private function handleFull(ErpInterface $erp, ProductCacheService $cache, SyncQueueState $state): void
    {
        $pageSize        = config('sync.product_page_size', 100);
        $offset          = 0;
        $totalPages      = 0;
        $dispatched      = 0;
        $latestWriteDate = '2000-01-01 00:00:00';

        Log::info("FetchErpProductsJob [{$erp->driverName()}]: full sync started (page size: {$pageSize}).");

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

                $this->dispatchPushJobs((int) $product['id']);

                if (($product['write_date'] ?? '') > $latestWriteDate) {
                    $latestWriteDate = $product['write_date'];
                }

                $dispatched++;
            }

            $offset += count($products);
            $totalPages++;

            Log::debug("FetchErpProductsJob: full sync page {$totalPages}, offset {$offset}, got " . count($products));

        } while (count($products) === $pageSize);

        $state->markComplete($latestWriteDate);

        Log::info("FetchErpProductsJob [{$erp->driverName()}]: full sync done — {$dispatched} products across {$totalPages} pages.");
    }

    // ── Manual (UI button) ───────────────────────────────────────────────

    private function handleManual(array $erpIds, ProductCacheService $cache): void
    {
        foreach ($erpIds as $erpId) {
            $data = $cache->read((int) $erpId);

            if (!$data) {
                Log::info("FetchErpProductsJob: no cache for #{$erpId}, fetching from ERP.");
                $cache->fetchAndCacheSingle((int) $erpId);
            }

            $this->dispatchPushJobs((int) $erpId);
        }

        Log::info('FetchErpProductsJob: manual re-push dispatched for ' . count($erpIds) . ' product(s).');
    }

    private function dispatchPushJobs(int $erpId): void
    {
        if ($this->shopify) {
            PushProductToShopifyJob::dispatchSync($erpId);
        }
        if ($this->amazon) {
            PushProductToAmazonJob::dispatchSync($erpId);
        }
    }
}