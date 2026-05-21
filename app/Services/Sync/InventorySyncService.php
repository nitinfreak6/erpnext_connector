<?php

namespace App\Services\Sync;

use App\Models\ChannelMapping;
use App\Models\SyncLog;
use App\Models\SyncMapping;
use App\Services\Erp\ErpInterface;
use App\Services\MappingService;
use App\Services\Shopify\ShopifyInventoryService;
use Illuminate\Support\Facades\Log;

class InventorySyncService
{
    public function __construct(
        private readonly ErpInterface            $erp,              // ← was OdooInventoryService
        private readonly ShopifyInventoryService $shopifyInventory,
        private readonly MappingService          $mappings,
    ) {}

    /**
     * Sync a single stock quant record to Shopify inventory.
     * The quant array format is ERP-agnostic: product_id, location_id,
     * quantity, reserved_quantity, write_date.
     */
    public function syncQuant(array $quant): bool
    {
        $erpProductId  = is_array($quant['product_id'])  ? $quant['product_id'][0]  : $quant['product_id'];
        $erpLocationId = is_array($quant['location_id']) ? $quant['location_id'][0] : $quant['location_id'];

        // ── 1. Resolve Shopify variant mapping ───────────────────────────
        $variantMapping = $this->mappings->findByOdooId(
            SyncMapping::TYPE_PRODUCT_VARIANT,
            (string) $erpProductId
        );

        if (!$variantMapping || !$variantMapping->shopify_secondary_id) {
            $this->logSkipped($erpProductId, $erpLocationId, 'missing_variant_mapping',
                'No variant mapping for this ERP product variant.');
            Log::debug("InventorySyncService: no variant mapping for ERP product #{$erpProductId}");
            return false;
        }

        // ── 2. Resolve Shopify location ──────────────────────────────────
        $shopifyLocationId = $this->resolveShopifyLocation((string) $erpLocationId);

        if (!$shopifyLocationId) {
            $this->logSkipped($erpProductId, $erpLocationId, 'missing_shopify_location_map',
                'No Shopify location mapped for this ERP location.');
            Log::debug("InventorySyncService: no Shopify location for ERP location #{$erpLocationId}");
            return false;
        }

        // ── 3. Calculate available qty (delegated to ERP adapter) ────────
        $available = $this->erp->availableQty($quant);

        // ── 4. Push to Shopify ───────────────────────────────────────────
        $log = SyncLog::create([
            'direction'       => 'erp_to_ecom',
            'entity_type'     => SyncMapping::TYPE_INVENTORY_ITEM,
            'entity_id'       => (string) $erpProductId,
            'action'          => 'update',
            'status'          => SyncLog::STATUS_PROCESSING,
            'request_payload' => json_encode([
                'inventory_item_id'   => $variantMapping->shopify_secondary_id,
                'shopify_location_id' => $shopifyLocationId,
                'erp_location_id'     => (string) $erpLocationId,
                'available'           => $available,
            ]),
        ]);

        try {
            $this->shopifyInventory->setLevel(
                $variantMapping->shopify_secondary_id,
                $shopifyLocationId,
                $available
            );

            $log->markSuccess("Set to {$available}");
            Log::info("InventorySyncService: ERP product #{$erpProductId} qty={$available} → Shopify location {$shopifyLocationId}");

            return true;
        } catch (\Throwable $e) {
            $log->markFailed($e->getMessage());
            throw $e;
        }
    }

    // ── Private helpers ──────────────────────────────────────────────────

    private function resolveShopifyLocation(string $erpLocationId): ?string
    {
        // 1 — DB mapping (editable from the Mappings UI)
        $mapping = ChannelMapping::ofType(ChannelMapping::TYPE_WAREHOUSE)
            ->forChannel(ChannelMapping::CHANNEL_SHOPIFY)
            ->active()
            ->where('odoo_id', $erpLocationId)
            ->first();

        if ($mapping?->external_id) {
            return $mapping->external_id;
        }

        // 2 — Legacy config fallback (odoo.location_map)
        $configMap = config('odoo.location_map', []);
        return $configMap[$erpLocationId] ?? null;
    }

    private function logSkipped(
        int|string $erpProductId,
        int|string $erpLocationId,
        string $reason,
        string $notes
    ): void {
        $log = SyncLog::create([
            'direction'       => 'erp_to_ecom',
            'entity_type'     => SyncMapping::TYPE_INVENTORY_ITEM,
            'entity_id'       => (string) $erpProductId,
            'action'          => 'update',
            'status'          => SyncLog::STATUS_SKIPPED,
            'request_payload' => json_encode([
                'erp_product_id'  => $erpProductId,
                'erp_location_id' => $erpLocationId,
                'reason'          => $reason,
            ]),
        ]);

        $log->markSkipped($notes, [
            'erp_product_id'  => (string) $erpProductId,
            'erp_location_id' => (string) $erpLocationId,
        ]);
    }
}