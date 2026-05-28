<?php

namespace App\Services\Sync;

use App\Models\ProductFieldConfig;
use App\Services\ChannelMappingService;
use App\Services\Ecom\EcomInterface;
use App\Services\Erp\ErpInterface;
use App\Services\MappingService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Log;

/**
 * ProductSyncService — orchestrates product sync via UniversalSyncService.
 * FIX #21: EcomInterface replaces ShopifyProductService — works with any ecom driver.
 */
class ProductSyncService
{
    public function __construct(
        private readonly ErpInterface          $erp,
        private readonly EcomInterface         $ecom,     // FIX: was ShopifyProductService
        private readonly UniversalSyncService  $universalSync,
        private readonly MappingService        $mappings,
        private readonly ChannelMappingService $channelMappings,
        private readonly SettingsService       $settings,
    ) {}

    public function isErpToEcom(): bool
    {
        return $this->settings->productSyncMode() === 'erp_to_ecom';
    }

    public function isEcomToErp(): bool
    {
        return $this->settings->productSyncMode() === 'ecom_to_erp';
    }

    public function isBidirectional(): bool
    {
        return $this->settings->productSyncMode() === 'bidirectional';
    }

    public function syncProduct(
        array  $erpTemplate,
        ?array $cachedVariants        = null,
        ?array $cachedAttributeValues = null,
    ): string {
        if ($this->isEcomToErp()) {
            throw new \LogicException('syncProduct() is for ERP → Ecom direction.');
        }

        $erpId = (string) $erpTemplate['id'];

        if ($cachedVariants === null) {
            $cachedVariants = $this->erp->getVariantsForProducts([$erpId]) ?? [];
        }

        if ($cachedAttributeValues === null) {
            $avIds = [];
            foreach ($cachedVariants as $v) {
                $avIds = array_merge($avIds, $v['product_template_attribute_value_ids'] ?? []);
            }
            $cachedAttributeValues = $avIds
                ? $this->erp->getAttributeValues(array_unique($avIds))
                : [];
        }

        $erpData = $this->normalizeErpProduct($erpTemplate, $cachedVariants, $cachedAttributeValues);

        try {
            $result = $this->universalSync->syncFromErpToEcom(
                entityType: 'product',
                erpData: $erpData,
                scope: null
            );

            $ecomId = $result['id'] ?? $result['ecom_id'] ?? null;
            Log::info("ProductSyncService: synced ERP #{$erpId} → {$this->ecom->driverName()} #{$ecomId}");
            return (string) $ecomId;
        } catch (\Throwable $e) {
            Log::error("ProductSyncService: sync failed for ERP #{$erpId}", ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function normalizeErpProduct(array $template, array $variants, array $attributeValues): array
    {
        return [
            'id'                   => $template['id'],
            'name'                 => $template['name'] ?? '',
            'list_price'           => $template['list_price'] ?? 0,
            'default_code'         => $template['default_code'] ?? '',
            'description_sale'     => $template['description_sale'] ?? '',
            'active'               => $template['active'] ?? true,
            'sale_ok'              => $template['sale_ok'] ?? true,
            'weight'               => $template['weight'] ?? null,
            'barcode'              => $template['barcode'] ?? null,
            'categ_id'             => $template['categ_id'] ?? null,
            'image_1920'           => $template['image_1920'] ?? null,
            'website_meta_keywords'=> $template['website_meta_keywords'] ?? null,
            'is_published'         => $template['is_published'] ?? false,
            'write_date'           => $template['write_date'] ?? now()->toDateTimeString(),
            'variants'             => $variants,
            'attribute_values'     => $attributeValues,
        ];
    }

    public function syncEcomProductToErp(array $ecomProduct): int
    {
        if ($this->isErpToEcom()) {
            throw new \LogicException('syncEcomProductToErp() is for Ecom → ERP direction.');
        }

        try {
            $result = $this->universalSync->syncFromEcomToErp(
                entityType: 'product',
                ecomData: $ecomProduct,
                scope: null
            );

            $erpId = $result['id'] ?? $result['erp_id'] ?? null;
            Log::info("ProductSyncService: synced {$this->ecom->driverName()} → ERP #{$erpId}");
            return (int) $erpId;
        } catch (\Throwable $e) {
            Log::error("ProductSyncService: ecom→erp sync failed", ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function getFieldConfigs(string $entityType, string $ecomDriver, string $erpDriver)
    {
        return ProductFieldConfig::query()
            ->where('entity_type', $entityType)
            ->where('ecom_driver', $ecomDriver)
            ->where('erp_driver', $erpDriver)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
    }
}
