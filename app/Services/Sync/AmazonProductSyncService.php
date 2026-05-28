<?php

namespace App\Services\Sync;

use App\Models\SyncLog;
use App\Services\Amazon\AmazonListingService;
use App\Services\Erp\ErpInterface;
use App\Services\MappingService;
use Illuminate\Support\Facades\Log;

/**
 * FIX #23: ErpInterface replaces OdooProductService — works with any ERP driver.
 */
class AmazonProductSyncService
{
    const ENTITY_PRODUCT = 'amazon_product';
    const ENTITY_VARIANT = 'amazon_variant';

    public function __construct(
        private readonly ErpInterface       $erp,       // FIX: was OdooProductService
        private readonly AmazonListingService $amazonListings,
        private readonly MappingService       $mappings,
    ) {}

    public function syncProduct(
        array  $erpTemplate,                // FIX: renamed from $odooTemplate
        ?array $cachedVariants           = null,
        ?array $cachedProductAttributes  = null,
    ): array {
        $erpId  = (string) $erpTemplate['id'];  // FIX: renamed from $odooId
        $synced = [];
        $failed = [];

        if ($cachedVariants !== null) {
            $variants = $cachedVariants;
            Log::debug("AmazonProductSyncService: using cached variants for #{$erpId}");
        } else {
            // FIX: uses ErpInterface::getVariantsForProducts()
            $variants = $this->erp->getVariantsForProducts([$erpTemplate['id']]);
            Log::debug("AmazonProductSyncService: fetched variants from {$this->erp->driverName()} for #{$erpId}");
        }

        if (empty($variants)) {
            Log::warning("Amazon: ERP product #{$erpId} has no variants, skipping.");
            return ['synced' => [], 'failed' => []];
        }

        // FIX: uses ErpInterface::getAttributeValues()
        $productAttributes = $cachedProductAttributes
            ?? $this->erp->getAttributeValues(
                array_unique(array_merge(...array_map(
                    fn($v) => $v['product_template_attribute_value_ids'] ?? [],
                    $variants
                )))
            );

        foreach ($variants as $variant) {
            $sku = $variant['default_code'] ?? '';

            if (!$sku) {
                Log::warning("Amazon: ERP variant #{$variant['id']} has no SKU, skipping.");
                $failed[] = $variant['id'];
                continue;
            }

            $existingMapping = $this->mappings->findByErpId(self::ENTITY_VARIANT, (string) $variant['id']);

            $log = SyncLog::create([
                'direction'   => 'erp_to_ecom',
                'entity_type' => self::ENTITY_VARIANT,
                'entity_id'   => (string) $variant['id'],
                'action'      => $existingMapping ? 'update' : 'create',
                'status'      => SyncLog::STATUS_PROCESSING,
            ]);

            try {
                $attributes = $this->amazonListings->buildListingAttributes(
                    $erpTemplate,
                    $variant,
                    $productAttributes
                );

                $result = $this->amazonListings->putListing($sku, $attributes);
                $status = $result['status'] ?? 'UNKNOWN';

                if ($status === 'INVALID') {
                    $issues = $result['issues'] ?? [];
                    throw new \RuntimeException('Amazon rejected listing: ' . json_encode(array_slice($issues, 0, 3)));
                }

                $this->mappings->upsert(self::ENTITY_VARIANT, (string) $variant['id'], $sku, [
                    'erp_reference'  => $sku,
                    'last_synced_at' => now(),
                ]);

                $this->mappings->upsert(self::ENTITY_PRODUCT, $erpId, $erpId, [
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
