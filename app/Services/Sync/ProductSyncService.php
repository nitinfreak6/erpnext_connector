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
        return array_merge($template, [
            'id'               => $template['id'] ?? $template['name'] ?? '',
            'variants'         => $variants,
            'attribute_values' => $attributeValues,
        ]);
    }

    public function syncEcomProductToErp(array $ecomProduct): int|string
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

            if ($erpId === null || $erpId === '' || $erpId === false) {
                return 0;
            }

            return is_numeric($erpId) ? (int) $erpId : (string) $erpId;
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
            ->ordered()
            ->get();
    }
}
