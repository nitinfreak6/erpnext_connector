<?php

namespace App\Jobs\Odoo;

use App\Jobs\Amazon\PushProductToAmazonJob;
use App\Jobs\Shopify\PushProductToShopifyJob;
use App\Models\SyncQueueState;
use App\Services\Odoo\OdooProductService;
use App\Services\ProductCacheService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchOdooProductsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 600; // 10 min lock

    public function __construct(
        private readonly bool $fullSync    = false,
        private readonly bool $shopify     = true,
        private readonly bool $amazon      = true,
        private readonly ?array $odooIds   = null,  // push specific IDs only (from UI button)
    ) {
        $this->onQueue('sync');
    }

    public function handle(OdooProductService $odooProducts, ProductCacheService $cache): void
    {
        $state = SyncQueueState::forType('products');

        if ($state->is_running && !$this->odooIds) {
            Log::warning('FetchOdooProductsJob: previous run still active, skipping.');
            return;
        }

        if (!$this->odooIds) {
            $state->markRunning();
        }

        try {
            // ── If specific IDs passed (manual UI trigger) ───────────────
            // Read from cache, skip Odoo fetch if cache is fresh
            if ($this->odooIds) {
                foreach ($this->odooIds as $odooId) {
                    $data = $cache->read((int) $odooId);

                    // Re-fetch from Odoo if no cache exists
                    if (!$data) {
                        Log::info("FetchOdooProductsJob: no cache for #{$odooId}, fetching from Odoo.");
                        $cache->fetchAndCacheSingle((int) $odooId);
                    }

                    if ($this->shopify) {
                        PushProductToShopifyJob::dispatch((int) $odooId)->onQueue('sync');
                    }
                    if ($this->amazon) {
                        PushProductToAmazonJob::dispatch((int) $odooId)->onQueue('sync');
                    }
                }

                Log::info('FetchOdooProductsJob: manual dispatch for ' . count($this->odooIds) . ' products.');
                return;
            }

            // ── Full / incremental sync (from scheduler or command) ──────
            $writeDate       = ($this->fullSync || !$state->last_odoo_write_date)
                ? '2000-01-01 00:00:00'
                : $state->last_odoo_write_date;

            $latestWriteDate = $writeDate;
            $offset          = 0;
            $dispatched      = 0;

            do {
                $products = $this->fullSync
                    ? $odooProducts->getAllActive($offset, 100)
                    : $odooProducts->getModifiedSince($writeDate);

                foreach ($products as $product) {
                    // ── Cache to JSON file (one per product) ─────────────
                    try {
                        $cache->cacheProduct($product);
                    } catch (\Throwable $e) {
                        Log::warning("FetchOdooProductsJob: cache write failed for #{$product['id']}: " . $e->getMessage());
                    }

                    // ── Dispatch push jobs using ID only (not full array) ─
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
            } while ($this->fullSync && count($products) === 100);

            $state->markComplete($latestWriteDate);

            Log::info("FetchOdooProductsJob: cached + dispatched {$dispatched} products.");
        } catch (\Throwable $e) {
            if (!$this->odooIds) {
                $state->update(['is_running' => false, 'notes' => $e->getMessage()]);
            }
            throw $e;
        }
    }
}