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

/**
 * FetchOdooProductsJob
 *
 * ERP API call budget per run:
 *
 *   Incremental (normal scheduler run, nothing changed):
 *     1 call  — getModifiedSince() returns []
 *     Done.
 *
 *   Incremental (N products changed):
 *     1 call  — getModifiedSince()                      → N product templates
 *     1 call  — getVariantsForTemplates([...N ids])     → batched, one call total
 *     1 call  — getAttributeValues([...all av ids])     → batched, one call total
 *     ─────
 *     3 calls total, regardless of N
 *
 *   Full sync (pages of 100):
 *     Per page: 1 (getAllActive) + 1 (variants batch) + 1 (attrs batch) = 3 calls/page
 */
class FetchOdooProductsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 600;

    public function __construct(
        private readonly bool   $fullSync = false,
        private readonly bool   $shopify  = true,
        private readonly bool   $amazon   = true,
        private readonly ?array $odooIds  = null,
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
            // ── Manual re-push (UI button with specific IDs) ─────────────
            if ($this->odooIds) {
                foreach ($this->odooIds as $odooId) {
                    if (!$cache->read((int) $odooId)) {
                        Log::info("FetchOdooProductsJob: no cache for #{$odooId}, fetching from Odoo.");
                        $cache->fetchAndCacheSingle((int) $odooId);
                    }
                    $this->dispatchPushJobs((int) $odooId);
                }
                Log::info('FetchOdooProductsJob: manual dispatch for ' . count($this->odooIds) . ' product(s).');
                return;
            }

            // ── Incremental or full ───────────────────────────────────────
            if ($this->fullSync) {
                $this->runFull($odooProducts, $cache, $state);
            } else {
                $this->runIncremental($odooProducts, $cache, $state);
            }

        } catch (\Throwable $e) {
            if (!$this->odooIds) {
                $state->update(['is_running' => false, 'notes' => $e->getMessage()]);
            }
            throw $e;
        }
    }

    // ── Private: incremental ──────────────────────────────────────────────

    private function runIncremental(OdooProductService $odooProducts, ProductCacheService $cache, SyncQueueState $state): void
    {
        $writeDate = $state->last_odoo_write_date ?? '2000-01-01 00:00:00';

        Log::info("FetchOdooProductsJob: incremental from {$writeDate}");

        // 1 Odoo call
        $products = $odooProducts->getModifiedSince($writeDate);

        if (empty($products)) {
            Log::info("FetchOdooProductsJob: nothing changed since {$writeDate}.");
            $state->markComplete($writeDate);
            return;
        }

        // Batch cache: 1 variant call + 1 attribute call for ALL products
        $this->batchCacheAndDispatch($products, $odooProducts, $cache);

        $latestWriteDate = max(array_column($products, 'write_date') ?: [$writeDate]);
        $state->markComplete($latestWriteDate);

        Log::info("FetchOdooProductsJob: incremental done — " . count($products) . " product(s), cursor → {$latestWriteDate}");
    }

    // ── Private: full ─────────────────────────────────────────────────────

    private function runFull(OdooProductService $odooProducts, ProductCacheService $cache, SyncQueueState $state): void
    {
        $pageSize        = 100;
        $offset          = 0;
        $dispatched      = 0;
        $latestWriteDate = '2000-01-01 00:00:00';

        Log::info("FetchOdooProductsJob: full sync started.");

        do {
            // 1 Odoo call per page
            $products = $odooProducts->getAllActive($offset, $pageSize);

            if (empty($products)) {
                break;
            }

            // 2 Odoo calls per page (variant batch + attr batch)
            $this->batchCacheAndDispatch($products, $odooProducts, $cache);

            foreach ($products as $p) {
                if (($p['write_date'] ?? '') > $latestWriteDate) {
                    $latestWriteDate = $p['write_date'];
                }
            }

            $dispatched += count($products);
            $offset     += count($products);

            Log::debug("FetchOdooProductsJob: full sync page — offset {$offset}, got " . count($products));

        } while (count($products) === $pageSize);

        $state->markComplete($latestWriteDate);

        Log::info("FetchOdooProductsJob: full sync done — {$dispatched} product(s).");
    }

    // ── Private: shared batch helper ──────────────────────────────────────

    /**
     * For a page of product templates:
     *   1. Fetch ALL variants in one call
     *   2. Fetch ALL attribute values in one call
     *   3. Write one JSON cache file per product (no further Odoo calls)
     *   4. Dispatch push jobs
     *
     * This is the key change from the original: was N variant calls + N attr calls.
     * Now it's always 2 calls regardless of how many products are in the page.
     */
    private function batchCacheAndDispatch(
        array              $products,
        OdooProductService $odooProducts,
        ProductCacheService $cache,
    ): void {
        $templateIds = array_column($products, 'id');

        // 1 Odoo call — all variants for this batch
        $allVariants = [];
        try {
            $allVariants = $odooProducts->getVariantsForTemplates($templateIds);
        } catch (\Throwable $e) {
            Log::error('FetchOdooProductsJob: variant batch fetch failed: ' . $e->getMessage());
        }

        // Group variants by template ID
        $variantsByTemplate = [];
        $allAvIds           = [];

        foreach ($allVariants as $variant) {
            $tmplId = is_array($variant['product_tmpl_id'] ?? null)
                ? $variant['product_tmpl_id'][0]
                : ($variant['product_tmpl_id'] ?? null);

            if ($tmplId) {
                $variantsByTemplate[$tmplId][] = $variant;
            }

            foreach ($variant['product_template_attribute_value_ids'] ?? [] as $avId) {
                $allAvIds[] = $avId;
            }
        }

        // 1 Odoo call — all attribute values for this batch
        $attrValueMap = [];
        $allAvIds     = array_values(array_unique($allAvIds));

        if (!empty($allAvIds)) {
            try {
                $rawAttrs = $odooProducts->getAttributeValues($allAvIds);
                foreach ($rawAttrs as $av) {
                    $attrValueMap[$av['id']] = $av;
                }
            } catch (\Throwable $e) {
                Log::error('FetchOdooProductsJob: attribute batch fetch failed: ' . $e->getMessage());
            }
        }

        // Write cache + dispatch — 0 Odoo calls from here
        foreach ($products as $template) {
            $erpId    = $template['id'];
            $variants = $variantsByTemplate[$erpId] ?? [];

            // Collect attribute values for this template's variants only
            $tmplAvIds = [];
            foreach ($variants as $v) {
                foreach ($v['product_template_attribute_value_ids'] ?? [] as $avId) {
                    $tmplAvIds[] = $avId;
                }
            }

            $attributeValues = array_values(array_filter(
                array_map(fn($id) => $attrValueMap[$id] ?? null, array_unique($tmplAvIds))
            ));

            // Write the JSON cache file directly (bypass ProductCacheService::cacheProduct
            // which would make another variant + attr call per product)
            try {
                $this->writeCacheFile($cache, $template, $variants, $attributeValues);
            } catch (\Throwable $e) {
                Log::warning("FetchOdooProductsJob: cache write failed for #{$erpId}: " . $e->getMessage());
            }

            $this->dispatchPushJobs($erpId);
        }
    }

    /**
     * Write the JSON cache file that PushProductToShopifyJob reads from.
     * Mirrors exactly what ProductCacheService::cacheProduct() writes,
     * but uses the pre-fetched variants/attrs so no extra Odoo calls are made.
     */
    private function writeCacheFile(
        ProductCacheService $cache,
        array               $template,
        array               $variants,
        array               $attributeValues,
    ): void {
        $erpId    = $template['id'];
        $filePath = 'products/' . $erpId . '.json';

        $data = [
            'fetched_at'       => now()->toISOString(),
            'odoo_id'          => $erpId,
            'template'         => $template,
            'variants'         => $variants,
            'attribute_values' => $attributeValues,
        ];

        \Illuminate\Support\Facades\Storage::disk('local')->put(
            $filePath,
            json_encode($data, JSON_PRETTY_PRINT)
        );

        \App\Models\ProductCache::updateOrCreate(
            ['odoo_id' => $erpId],
            [
                'name'         => $template['name'],
                'default_code' => $template['default_code'] ?: null,
                'file_path'    => $filePath,
                'fetched_at'   => now(),
            ]
        );
    }

    private function dispatchPushJobs(int $erpId): void
    {
        if ($this->shopify) {
            PushProductToShopifyJob::dispatch($erpId)->onQueue('sync');
        }
        if ($this->amazon) {
            PushProductToAmazonJob::dispatch($erpId)->onQueue('sync');
        }
    }
}
