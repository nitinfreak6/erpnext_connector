<?php

namespace App\Services\Sync;

use App\Models\SyncLog;
use App\Models\SyncMapping;
use App\Services\Amazon\AmazonListingService;
use App\Services\MappingService;
use App\Services\Odoo\OdooProductService;
use Illuminate\Support\Facades\Log;

class AmazonProductSyncService
{
    const ENTITY_PRODUCT = 'amazon_product';
    const ENTITY_VARIANT = 'amazon_variant';

    public function __construct(
        private readonly OdooProductService   $odooProducts,
        private readonly AmazonListingService $amazonListings,
        private readonly MappingService       $mappings,
    ) {}

    /**
     * Sync a single Odoo product template to Amazon.
     *
     * Pass $cachedVariants and $cachedProductAttributes from the JSON cache
     * to avoid calling Odoo again. If not provided, falls back to Odoo API.
     */
    public function syncProduct(
        array  $odooTemplate,
        ?array $cachedVariants           = null,
        ?array $cachedProductAttributes  = null,
    ): array {
        $odooId = (string) $odooTemplate['id'];
        $synced = [];
        $failed = [];

        // ── Use cached data if provided, otherwise fall back to Odoo API ─
        if ($cachedVariants !== null) {
            $variants = $cachedVariants;
            Log::debug("AmazonProductSyncService: using cached variants for #{$odooId} (no Odoo call)");
        } else {
            $variants = $this->odooProducts->getVariantsForTemplates([$odooTemplate['id']]);
            Log::debug("AmazonProductSyncService: fetched variants from Odoo for #{$odooId}");
        }

        if (empty($variants)) {
            Log::warning("Amazon: Odoo product #{$odooId} has no variants, skipping.");
            return ['synced' => [], 'failed' => []];
        }

        // Pre-fetch product attributes once (from cache or Odoo)
        // Done outside the variant loop to avoid N Odoo calls
        $productAttributes = $cachedProductAttributes
            ?? $this->odooProducts->getProductAttributes((int) $odooTemplate['id']);

        foreach ($variants as $variant) {
            $sku = $variant['default_code'] ?? '';

            if (!$sku) {
                Log::warning("Amazon: Odoo variant #{$variant['id']} has no SKU, skipping.");
                $failed[] = $variant['id'];
                continue;
            }

            $existingMapping = $this->mappings->findByOdooId(self::ENTITY_VARIANT, (string) $variant['id']);

            $log = SyncLog::create([
                'direction'   => 'erp_to_ecom',
                'entity_type' => self::ENTITY_VARIANT,
                'entity_id'   => (string) $variant['id'],
                'action'      => $existingMapping ? 'update' : 'create',
                'status'      => SyncLog::STATUS_PROCESSING,
            ]);

            try {
                // Build listing attributes using cached product attributes — no Odoo call
                $attributes = $this->amazonListings->buildListingAttributes(
                    $odooTemplate,
                    $variant,
                    $productAttributes   // ← from cache, not from Odoo
                );

                $result = $this->amazonListings->putListing($sku, $attributes);
                $status = $result['status'] ?? 'UNKNOWN';

                if ($status === 'INVALID') {
                    $issues = $result['issues'] ?? [];
                    throw new \RuntimeException('Amazon rejected listing: ' . json_encode(array_slice($issues, 0, 3)));
                }

                $this->mappings->upsert(self::ENTITY_VARIANT, (string) $variant['id'], $sku, [
                    'odoo_reference' => $sku,
                    'last_synced_at' => now(),
                ]);

                $this->mappings->upsert(self::ENTITY_PRODUCT, $odooId, $odooId, [
                    'last_synced_at' => now(),
                ]);

                $log->markSuccess(json_encode(['sku' => $sku, 'status' => $status]));

                Log::info("Amazon listing synced: SKU={$sku}, status={$status}");

                $synced[] = $sku;
            } catch (\Throwable $e) {
                $log->markFailed($e->getMessage());
                Log::error("Amazon listing failed for SKU={$sku}: " . $e->getMessage());
                $failed[] = $sku;
            }
        }

        return ['synced' => $synced, 'failed' => $failed];
    }
}