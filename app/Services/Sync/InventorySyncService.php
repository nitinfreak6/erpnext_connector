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
 * InventorySyncService - Orchestrates inventory sync using UniversalSyncService
 */
class InventorySyncService
{
    public function __construct(
        private readonly ErpInterface           $erp,
        private readonly EcomInterface          $ecom,
        private readonly UniversalSyncService   $universalSync,
        private readonly MappingService         $mappings,
        private readonly ChannelMappingService  $channelMappings,
        private readonly SettingsService        $settings,
    ) {}

    public function isEnabled(): bool
    {
        return $this->settings->get('inventory_sync_enabled', false);
    }

    /**
     * Sync ERP inventory to ecommerce (stock levels)
     */
    public function syncInventoryToEcom(array $inventory): array
    {
        if (!$this->isEnabled()) {
            Log::info("InventorySyncService: inventory sync disabled");
            return [];
        }

        $results = [];

        try {
            $result = $this->universalSync->syncFromErpToEcom(
                entityType: 'inventory',
                erpData: $inventory,
                scope: null
            );

            Log::info("InventorySyncService: synced inventory to ecommerce", [
                'sku' => $inventory['sku'] ?? null,
                'quantity' => $inventory['quantity'] ?? null,
            ]);

            $results[] = $result;
        } catch (\Throwable $e) {
            Log::error("InventorySyncService: ERP→Ecom sync failed", [
                'error' => $e->getMessage(),
                'sku' => $inventory['sku'] ?? null,
            ]);
        }

        return $results;
    }

    /**
     * Sync ecommerce inventory back to ERP
     */
    public function syncInventoryToErp(array $inventory): array
    {
        if (!$this->isEnabled()) {
            Log::info("InventorySyncService: inventory sync disabled");
            return [];
        }

        $results = [];

        try {
            $result = $this->universalSync->syncFromEcomToErp(
                entityType: 'inventory',
                ecomData: $inventory,
                scope: null
            );

            Log::info("InventorySyncService: synced ecommerce inventory to ERP", [
                'sku' => $inventory['sku'] ?? null,
                'quantity' => $inventory['quantity'] ?? null,
            ]);

            $results[] = $result;
        } catch (\Throwable $e) {
            Log::error("InventorySyncService: Ecom→ERP sync failed", [
                'error' => $e->getMessage(),
                'sku' => $inventory['sku'] ?? null,
            ]);
        }

        return $results;
    }

    /**
     * Sync multiple inventory records at once
     */
    public function syncBatch(array $inventoryRecords, string $direction = 'erp_to_ecom'): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $results = [];

        foreach ($inventoryRecords as $inventory) {
            try {
                if ($direction === 'erp_to_ecom') {
                    $result = $this->syncInventoryToEcom($inventory);
                } else {
                    $result = $this->syncInventoryToErp($inventory);
                }

                $results = array_merge($results, $result);
            } catch (\Throwable $e) {
                Log::warning("InventorySyncService: batch sync failed for item", [
                    'error' => $e->getMessage(),
                    'sku' => $inventory['sku'] ?? null,
                ]);
            }
        }

        Log::info("InventorySyncService: batch sync completed", [
            'total' => count($inventoryRecords),
            'synced' => count($results),
        ]);

        return $results;
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