<?php

namespace App\Services\Sync;

use App\Exceptions\ShopifyApiException;
use App\Models\SyncLog;
use App\Models\SyncMapping;
use App\Services\ChannelMappingService;
use App\Services\Erp\ErpInterface;
use App\Services\MappingService;
use App\Services\Shopify\ShopifyProductService;
use Illuminate\Support\Facades\Log;

class ProductSyncService
{
    public function __construct(
        private readonly ErpInterface          $erp,         // ← was OdooProductService
        private readonly ShopifyProductService $shopifyProducts,
        private readonly MappingService        $mappings,
        private readonly ChannelMappingService $channelMappings,
    ) {}

    /**
     * Sync a single ERP product template to Shopify.
     *
     * Pass $cachedVariants and $cachedAttributeValues from the JSON cache
     * to avoid calling the ERP again. If not provided, falls back to ERP API.
     */
    public function syncProduct(
        array  $erpTemplate,
        ?array $cachedVariants        = null,
        ?array $cachedAttributeValues = null,
    ): string {
        $erpId = (string) $erpTemplate['id'];

        // ── Use cached data if provided, otherwise fall back to ERP API ──
        if ($cachedVariants !== null) {
            $variants = $cachedVariants;
            Log::debug("ProductSyncService: using cached variants for #{$erpId} (no ERP call)");
        } else {
            $variants = $this->erp->getVariantsForProducts([$erpTemplate['id']]);
            Log::debug("ProductSyncService: fetched variants from ERP for #{$erpId}");
        }

        if ($cachedAttributeValues !== null) {
            $attributeValues = $cachedAttributeValues;
        } else {
            $avIds = [];
            foreach ($variants as $v) {
                $avIds = array_merge($avIds, $v['product_template_attribute_value_ids'] ?? []);
            }
            $attributeValues = $avIds
                ? $this->erp->getAttributeValues(array_unique($avIds))
                : [];
        }

        // ── Remap size attributes via ProductSize channel mapping ────────
        $attributeValues = $this->remapSizeAttributes($attributeValues);

        // ── Resolve Shopify product_type via Category channel mapping ────
        $shopifyProductType = $this->resolveProductType($erpTemplate);

        // Build Shopify payload (ShopifyProductService is ERP-agnostic)
        $payload = $this->shopifyProducts->buildPayload(
            array_merge($erpTemplate, ['_shopify_product_type' => $shopifyProductType]),
            $variants,
            $attributeValues
        );

        // Check for existing mapping
        $mapping = $this->mappings->findByOdooId(SyncMapping::TYPE_PRODUCT, $erpId);

        $log = SyncLog::create([
            'direction'       => SyncLog::DIRECTION_ODOO_TO_SHOPIFY,
            'entity_type'     => SyncMapping::TYPE_PRODUCT,
            'entity_id'       => $erpId,
            'action'          => $mapping ? 'update' : 'create',
            'status'          => SyncLog::STATUS_PROCESSING,
            'request_payload' => json_encode($payload),
        ]);

        try {
            if ($mapping) {
                try {
                    $shopifyProduct = $this->shopifyProducts->update($mapping->shopify_id, $payload);
                    $action = 'update';
                } catch (ShopifyApiException $e) {
                    if ($e->getHttpStatus() === 404) {
                        Log::warning('Shopify product not found for mapped ID; creating new.', [
                            'erp_id'     => $erpId,
                            'shopify_id' => $mapping->shopify_id,
                        ]);
                        $shopifyProduct = $this->shopifyProducts->create($payload);
                        $action = 'create';
                    } else {
                        throw $e;
                    }
                }
            } else {
                $shopifyProduct = $this->shopifyProducts->create($payload);
                $action = 'create';
            }

            $shopifyProductId = (string) $shopifyProduct['id'];

            $this->mappings->upsert(SyncMapping::TYPE_PRODUCT, $erpId, $shopifyProductId, [
                'shopify_handle' => $shopifyProduct['handle'] ?? null,
                'last_synced_at' => now(),
            ]);

            $this->syncVariantMappings($variants, $shopifyProduct['variants'] ?? []);

            $log->markSuccess(json_encode($shopifyProduct));
            Log::info("Product synced: ERP #{$erpId} → Shopify #{$shopifyProductId} [{$this->erp->driverName()}]", ['action' => $action]);

            return $shopifyProductId;
        } catch (\Throwable $e) {
            $log->markFailed($e->getMessage(), ['trace' => substr($e->getTraceAsString(), 0, 500)]);
            throw $e;
        }
    }

    // ── Private helpers ──────────────────────────────────────────────────

    private function resolveProductType(array $erpTemplate): ?string
    {
        $categoryId = is_array($erpTemplate['categ_id'] ?? null)
            ? (string) $erpTemplate['categ_id'][0]
            : (string) ($erpTemplate['categ_id'] ?? '');

        if (!$categoryId) return null;

        $mapped = $this->channelMappings->shopifyCategory($categoryId);
        if ($mapped) return $mapped;

        return is_array($erpTemplate['categ_id'] ?? null)
            ? ($erpTemplate['categ_id'][1] ?? null)
            : null;
    }

    private function remapSizeAttributes(array $attributeValues): array
    {
        foreach ($attributeValues as &$av) {
            $attrName = strtolower($av['attribute_id'][1] ?? '');

            if (!in_array($attrName, ['size', 'sizes', 'shoe size', 'clothing size'])) {
                continue;
            }

            $shopifyVal = $this->channelMappings->shopifySize($av['name'] ?? '');
            if ($shopifyVal) {
                $av['_mapped_name'] = $shopifyVal;
            }
        }
        unset($av);

        return $attributeValues;
    }

    private function syncVariantMappings(array $erpVariants, array $shopifyVariants): void
    {
        $shopifyBySku     = [];
        $shopifyByBarcode = [];

        foreach ($shopifyVariants as $sv) {
            if (!empty($sv['sku']))     $shopifyBySku[$sv['sku']]         = $sv;
            if (!empty($sv['barcode'])) $shopifyByBarcode[$sv['barcode']] = $sv;
        }

        foreach ($erpVariants as $erpVariant) {
            $sku     = $erpVariant['default_code'] ?? '';
            $barcode = $erpVariant['barcode'] ?? '';

            if ($sku && isset($shopifyBySku[$sku])) {
                $sv = $shopifyBySku[$sku];
            } elseif ($barcode && isset($shopifyByBarcode[$barcode])) {
                $sv = $shopifyByBarcode[$barcode];
            } elseif (count($erpVariants) === 1 && count($shopifyVariants) === 1) {
                $sv = $shopifyVariants[0];
            } else {
                continue;
            }

            $this->mappings->upsert(
                SyncMapping::TYPE_PRODUCT_VARIANT,
                (string) $erpVariant['id'],
                (string) $sv['id'],
                [
                    'shopify_secondary_id' => (string) ($sv['inventory_item_id'] ?? ''),
                    'odoo_reference'       => $sku ?: ($barcode ?: null),
                    'last_synced_at'       => now(),
                ]
            );
        }
    }
}
