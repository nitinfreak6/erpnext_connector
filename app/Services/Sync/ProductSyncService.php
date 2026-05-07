<?php

namespace App\Services\Sync;

use App\Exceptions\ShopifyApiException;
use App\Models\SyncLog;
use App\Models\SyncMapping;
use App\Services\ChannelMappingService;
use App\Services\MappingService;
use App\Services\Odoo\OdooProductService;
use App\Services\Shopify\ShopifyProductService;
use Illuminate\Support\Facades\Log;

class ProductSyncService
{
    public function __construct(
        private readonly OdooProductService    $odooProducts,
        private readonly ShopifyProductService $shopifyProducts,
        private readonly MappingService        $mappings,
        private readonly ChannelMappingService $channelMappings,
    ) {}

    /**
     * Sync a single Odoo product template to Shopify.
     */
    public function syncProduct(array $odooTemplate): string
    {
        $odooId = (string) $odooTemplate['id'];

        // Fetch variants and attribute values
        $variants = $this->odooProducts->getVariantsForTemplates([$odooTemplate['id']]);

        $avIds = [];
        foreach ($variants as $v) {
            $avIds = array_merge($avIds, $v['product_template_attribute_value_ids'] ?? []);
        }
        $attributeValues = $avIds
            ? $this->odooProducts->getAttributeValues(array_unique($avIds))
            : [];

        // ── Wire: remap size attribute values via ProductSize mapping ────
        $attributeValues = $this->remapSizeAttributes($attributeValues);

        // ── Wire: resolve Shopify product_type via Category mapping ──────
        $shopifyProductType = $this->resolveProductType($odooTemplate);

        // Build payload (pass resolved product type so ShopifyProductService uses it)
        $payload = $this->shopifyProducts->buildPayload(
            array_merge($odooTemplate, ['_shopify_product_type' => $shopifyProductType]),
            $variants,
            $attributeValues
        );

        // Check existing mapping
        $mapping = $this->mappings->findByOdooId(SyncMapping::TYPE_PRODUCT, $odooId);

        $log = SyncLog::create([
            'direction'       => SyncLog::DIRECTION_ODOO_TO_SHOPIFY,
            'entity_type'     => SyncMapping::TYPE_PRODUCT,
            'entity_id'       => $odooId,
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
                            'odoo_id'    => $odooId,
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

            $this->mappings->upsert(SyncMapping::TYPE_PRODUCT, $odooId, $shopifyProductId, [
                'shopify_handle' => $shopifyProduct['handle'] ?? null,
                'last_synced_at' => now(),
            ]);

            $this->syncVariantMappings($variants, $shopifyProduct['variants'] ?? []);

            $log->markSuccess(json_encode(['shopify_product_id' => $shopifyProductId]));
            Log::info("Product synced: Odoo #{$odooId} → Shopify #{$shopifyProductId}", ['action' => $action]);

            return $shopifyProductId;
        } catch (\Throwable $e) {
            $log->markFailed($e->getMessage(), ['trace' => substr($e->getTraceAsString(), 0, 500)]);
            throw $e;
        }
    }

    // ────────────────────────────────────────────────────────────────────
    // Mapping helpers
    // ────────────────────────────────────────────────────────────────────

    /**
     * Resolve Shopify product_type from Odoo category via Category mapping.
     * Falls back to Odoo category name if no mapping found.
     */
    private function resolveProductType(array $odooTemplate): ?string
    {
        $categoryId = is_array($odooTemplate['categ_id'] ?? null)
            ? (string) $odooTemplate['categ_id'][0]
            : (string) ($odooTemplate['categ_id'] ?? '');

        if (!$categoryId) {
            return null;
        }

        // Try mapped value first
        $mapped = $this->channelMappings->shopifyCategory($categoryId);

        if ($mapped) {
            return $mapped;
        }

        // Fall back to Odoo category name (second element of array)
        return is_array($odooTemplate['categ_id'] ?? null)
            ? ($odooTemplate['categ_id'][1] ?? null)
            : null;
    }

    /**
     * Remap size attribute values using ProductSize mapping.
     * Translates Odoo size labels (e.g. "92/98") to Shopify labels (e.g. "2-3T").
     */
    private function remapSizeAttributes(array $attributeValues): array
    {
        foreach ($attributeValues as &$av) {
            $attrName = strtolower($av['attribute_id'][1] ?? '');

            // Only remap size-type attributes
            if (!in_array($attrName, ['size', 'sizes', 'shoe size', 'clothing size'])) {
                continue;
            }

            $odooValue  = $av['name'] ?? '';
            $shopifyVal = $this->channelMappings->shopifySize($odooValue);

            if ($shopifyVal) {
                $av['_mapped_name'] = $shopifyVal; // ShopifyProductService should use this if present
            }
        }
        unset($av);

        return $attributeValues;
    }

    /**
     * Match Odoo variants to Shopify variants by SKU and save mappings.
     */
    private function syncVariantMappings(array $odooVariants, array $shopifyVariants): void
    {
        $shopifyBySku     = [];
        $shopifyByBarcode = [];

        foreach ($shopifyVariants as $sv) {
            if (!empty($sv['sku']))     $shopifyBySku[$sv['sku']]         = $sv;
            if (!empty($sv['barcode'])) $shopifyByBarcode[$sv['barcode']] = $sv;
        }

        foreach ($odooVariants as $odooVariant) {
            $sku     = $odooVariant['default_code'] ?? '';
            $barcode = $odooVariant['barcode'] ?? '';

            if ($sku && isset($shopifyBySku[$sku])) {
                $sv = $shopifyBySku[$sku];
            } elseif ($barcode && isset($shopifyByBarcode[$barcode])) {
                $sv = $shopifyByBarcode[$barcode];
            } elseif (count($odooVariants) === 1 && count($shopifyVariants) === 1) {
                $sv = $shopifyVariants[0];
            } else {
                continue;
            }

            $this->mappings->upsert(
                SyncMapping::TYPE_PRODUCT_VARIANT,
                (string) $odooVariant['id'],
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