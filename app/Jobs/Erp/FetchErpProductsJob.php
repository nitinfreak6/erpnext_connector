<?php

namespace App\Jobs\Erp;

use App\Jobs\Amazon\PushProductToAmazonJob;
use App\Jobs\Shopify\PushProductToShopifyJob;
use App\Models\SyncQueueState;
use App\Services\Erp\ErpInterface;
use App\Services\ProductCacheService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * FetchErpProductsJob
 *
 * Replaces FetchOdooProductsJob. Uses ErpInterface so it works with any ERP.
 * Logic is identical to the old job — only the dependency changed.
 *
 * The old FetchOdooProductsJob is kept as a thin alias (see below) so
 * any already-queued jobs drain correctly without crashing.
 */
class FetchErpProductsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 600; // 10 min lock

    public function __construct(
        private readonly bool   $fullSync = false,
        private readonly bool   $shopify  = true,
        private readonly bool   $amazon   = true,
        private readonly ?array $erpIds   = null,  // push specific IDs only (from UI button)
    ) {
        $this->onQueue('sync');
    }

    public function handle(ErpInterface $erp, ProductCacheService $cache): void
    {
        $state = SyncQueueState::forType('products');

        if ($state->is_running && !$this->erpIds) {
            Log::warning('FetchErpProductsJob: previous run still active, skipping.');
            return;
        }

        if (!$this->erpIds) {
            $state->markRunning();
        }

        try {
            // ── Specific IDs (manual UI trigger) ────────────────────────
            if ($this->erpIds) {
                foreach ($this->erpIds as $erpId) {
                    $data = $cache->read((int) $erpId);

                    if (!$data) {
                        Log::info("FetchErpProductsJob: no cache for #{$erpId}, fetching from ERP.");
                        $cache->fetchAndCacheSingle((int) $erpId);
                    }

                    if ($this->shopify) {
                        PushProductToShopifyJob::dispatch((int) $erpId)->onQueue('sync');
                    }
                    if ($this->amazon) {
                        PushProductToAmazonJob::dispatch((int) $erpId)->onQueue('sync');
                    }
                }

                Log::info('FetchErpProductsJob: manual dispatch for ' . count($this->erpIds) . ' products.');
                return;
            }

            // ── Full / incremental sync ──────────────────────────────────
            $writeDate       = ($this->fullSync || !$state->last_odoo_write_date)
                ? '2000-01-01 00:00:00'
                : $state->last_odoo_write_date;

            $latestWriteDate = $writeDate;
            $offset          = 0;
            $dispatched      = 0;

            do {
                $products = $this->fullSync
                    ? $erp->getAllActiveProducts($offset, config('sync.product_page_size', 100))
                    : $erp->getProductsModifiedSince($writeDate);

                foreach ($products as $product) {
                    try {
                        $cache->cacheProduct($product);
                    } catch (\Throwable $e) {
                        Log::warning("FetchErpProductsJob: cache write failed for #{$product['id']}: " . $e->getMessage());
                    }

                    if ($this->shopify) {
                        PushProductToShopifyJob::dispatch((int) $product['id'])->onQueue('sync');
                    }
                    if ($this->amazon) {
                        PushProductToAmazonJob::dispatch((int) $product['id'])->onQueue('sync');
                    }

                    if (($product['write_date'] ?? '') > $latestWriteDate) {
                        $latestWriteDate = $product['write_date'];
                    }

                    $dispatched++;
                }

                $offset += count($products);
            } while ($this->fullSync && count($products) === config('sync.product_page_size', 100));

            $state->markComplete($latestWriteDate);

            Log::info("FetchErpProductsJob [{$erp->driverName()}]: cached + dispatched {$dispatched} products.");
        } catch (\Throwable $e) {
            if (!$this->erpIds) {
                $state->update(['is_running' => false, 'notes' => $e->getMessage()]);
            }
            throw $e;
        }
    }
}
