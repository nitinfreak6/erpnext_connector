<?php

namespace App\Services\Sync;

use App\Exceptions\ShopifyApiException;
use App\Models\ProductFieldConfig;
use App\Models\SyncLog;
use App\Models\SyncMapping;
use App\Services\ChannelMappingService;
use App\Services\Erp\ErpInterface;
use App\Services\MappingService;
use App\Services\SettingsService;
use App\Services\Shopify\ShopifyProductService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProductSyncService
{
    public function __construct(
        private readonly ErpInterface          $erp,
        private readonly ShopifyProductService $shopifyProducts,
        private readonly MappingService        $mappings,
        private readonly ChannelMappingService $channelMappings,
        private readonly SettingsService       $settings,
    ) {}

    // ── Direction helpers ────────────────────────────────────────────────

    /**
     * Returns true when the configured mode is ERP → Ecom (default one-way).
     */
    public function isErpToEcom(): bool
    {
        return $this->settings->productSyncMode() === 'erp_to_ecom';
    }

    /**
     * Returns true when the configured mode is Ecom → ERP (reversed one-way).
     */
    public function isEcomToErp(): bool
    {
        return $this->settings->productSyncMode() === 'ecom_to_erp';
    }

    /**
     * Returns true when bidirectional mode is active.
     */
    public function isBidirectional(): bool
    {
        return $this->settings->productSyncMode() === 'bidirectional';
    }

    // ── ERP → Shopify (default direction) ───────────────────────────────

    /**
     * Sync a single ERP product template to E-commerce platform.
     *
     * Pass $cachedVariants and $cachedAttributeValues from the JSON cache
     * to avoid calling the ERP again. If not provided, falls back to ERP API.
     */
    public function syncProduct(
        array  $erpTemplate,
        ?array $cachedVariants        = null,
        ?array $cachedAttributeValues = null,
    ): string {
        // Route to the correct direction
        if ($this->isEcomToErp()) {
            throw new \LogicException(
                'syncProduct() is for ERP → Ecom direction. ' .
                'Use syncEcomProductToErp() when product_sync_mode = ecom_to_erp.'
            );
        }

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
            'direction' => SyncLog::DIRECTION_ERP_TO_ECOM,
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

    // ── Ecom → ERP (reversed direction) ──────────────────────────────────

    /**
     * Sync an e-commerce product into the ERP.
     *
     * Called when product_fetch_from = 'ecom' and product_post_to = 'erp'.
     *
     * @param  array $ecomProduct  Raw ecom product payload (REST or GraphQL normalised)
     * @return int|string          The ERP product ID that was created or updated
     */
    public function syncEcomProductToErp(array $ecomProduct): int|string
    {
        $ecomId = (string) ($ecomProduct['id'] ?? $ecomProduct['ecom_id'] ?? '');

        if (! $ecomId) {
            throw new \InvalidArgumentException('syncEcomProductToErp: missing ecommerce product ID.');
        }

        // ── Check for an existing mapping (ecom_id → erp_id) ──────────
        $mapping = $this->mappings->findByEcomId(SyncMapping::TYPE_PRODUCT, $ecomId);

        // Normalise the ecom payload into the flat ERP-agnostic structure
        // the ErpInterface expects.  The adapter must implement upsertProduct().
        $erpPayload = $this->normaliseEcomToErp($ecomProduct);

        $log = SyncLog::create([
            'direction'       => 'ecom_to_erp',
            'entity_type'     => SyncMapping::TYPE_PRODUCT,
            'entity_id'       => $ecomId,
            'action'          => $mapping ? 'update' : 'create',
            'status'          => SyncLog::STATUS_PROCESSING,
            'request_payload' => json_encode($erpPayload),
        ]);

        try {
            if ($mapping) {
                $erpId = $this->erp->upsertProduct(
                    array_merge($erpPayload, ['id' => $mapping->erp_id])
                );
                $action = 'update';
            } else {
                $erpId = $this->erp->upsertProduct($erpPayload);
                $action = 'create';
            }

            $this->mappings->upsert(
                SyncMapping::TYPE_PRODUCT,
                (string) $erpId,
                $ecomId,
                [
                    'last_synced_at' => now(),
                    'last_sync_direction' => 'ecom_to_erp',
                ]
            );

            $log->markSuccess(json_encode(['erp_id' => $erpId]));
            Log::info(
                "Product synced: Ecom #{$ecomId} → ERP #{$erpId} [{$this->erp->driverName()}]",
                ['action' => $action]
            );

            return $erpId;
        } catch (\Throwable $e) {
            $log->markFailed($e->getMessage(), ['trace' => substr($e->getTraceAsString(), 0, 500)]);
            throw $e;
        }
    }

    /**
     * Dispatch the correct sync based on the current direction setting.
     *
     * For 'erp_to_ecom':   expects an ERP template array.
     * For 'ecom_to_erp':   expects an ecom product array (must include 'id').
     * For 'bidirectional': call each direction separately with the matching payload.
     *                      This method runs ERP → Ecom by default; pass
     *                      $reverseForBidi = true to run Ecom → ERP leg.
     */
    public function syncAuto(
        array $product,
        ?array $cachedVariants = null,
        ?array $cachedAttributeValues = null,
        bool $reverseForBidi = false,
    ): string|int {
        $mode = $this->settings->productSyncMode();

        if ($mode === 'ecom_to_erp' || ($mode === 'bidirectional' && $reverseForBidi)) {
            return $this->syncEcomProductToErp($product);
        }

        return $this->syncProduct($product, $cachedVariants, $cachedAttributeValues);
    }

    // ── Private helpers ──────────────────────────────────────────────────

    /**
     * Convert an e-commerce product payload to the flat normalised structure
     * the ERP adapter's upsertProduct() method expects.
     * 
     * Now uses ProductFieldConfig instead of hardcoded mappings!
     */
    private function normaliseEcomToErp(array $ecomProduct): array
    {
        $configs = $this->getReverseFieldConfigs();
        $variants = $ecomProduct['variants'] ?? [];
        $firstVariant = $variants[0] ?? [];
        
        $erpPayload = [
            'type'           => 'consu',  // Safe default for Odoo
            '_source'        => $this->settings->ecomDriver(),
            '_ecom_id'       => (string) ($ecomProduct['id'] ?? ''),
            '_variants_raw'  => $variants,
        ];

        // Map template-level fields
        foreach ($configs['template'] as $config) {
            if (!$config['is_active'] || $config['reverse_transform'] === 'skip') {
                continue;
            }

            $shopifyField = $config['shopify_field'];
            $odooField = $config['odoo_field'];
            $rawValue = $ecomProduct[$shopifyField] ?? null;

            if ($rawValue !== null) {
                $erpPayload[$odooField] = $this->applyReverseTransform(
                    $rawValue, 
                    $config['reverse_transform'] ?? null,
                    $config
                );
            }
        }

        // Map variant-level fields (use first variant)
        foreach ($configs['variant'] as $config) {
            if (!$config['is_active'] || $config['reverse_transform'] === 'skip') {
                continue;
            }

            $shopifyField = $config['shopify_field'];
            $odooField = $config['odoo_field'];
            $rawValue = $firstVariant[$shopifyField] ?? null;

            if ($rawValue !== null) {
                $erpPayload[$odooField] = $this->applyReverseTransform(
                    $rawValue, 
                    $config['reverse_transform'] ?? null,
                    $config
                );
            }
        }

        return $erpPayload;
    }

    /**
     * Get field configs grouped by scope, cached for performance
     */
    private function getReverseFieldConfigs(): array
    {
        return Cache::remember('product_field_configs_reverse_shopify', 60, function () {
            $allConfigs = ProductFieldConfig::where('channel', 'shopify')
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get();

            return [
                'template' => $allConfigs->where('scope', 'template')->toArray(),
                'variant' => $allConfigs->where('scope', 'variant')->toArray(),
            ];
        });
    }

    /**
     * Apply reverse transform (Shopify → Odoo)
     */
    private function applyReverseTransform(mixed $value, ?string $transform, array $config): mixed
    {
        if (!$transform) {
            return $value;
        }

        return match($transform) {
            'strip_tags' => strip_tags((string) $value),
            'parse_float' => (float) $value,
            'parse_float_nullable' => $value ? (float) $value : null,
            'status_to_boolean' => in_array(strtolower($value), ['active', 'true', '1']),
            'pass_through' => $value,
            'skip' => null,
            default => $value,
        };
    }

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