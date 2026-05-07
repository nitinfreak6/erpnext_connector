<?php

namespace App\Services\Sync;

use App\Models\ChannelMapping;
use App\Models\SyncLog;
use App\Models\SyncMapping;
use App\Services\MappingService;
use App\Services\Odoo\OdooInventoryService;
use App\Services\Shopify\ShopifyInventoryService;
use Illuminate\Support\Facades\Log;

class InventorySyncService
{
    public function __construct(
        private readonly OdooInventoryService    $odooInventory,
        private readonly ShopifyInventoryService $shopifyInventory,
        private readonly MappingService          $mappings,
    ) {}

    /**
     * Sync a stock quant record to Shopify inventory.
     */
    public function syncQuant(array $quant): bool
    {
        $odooProductId  = is_array($quant['product_id'])  ? $quant['product_id'][0]  : $quant['product_id'];
        $odooLocationId = is_array($quant['location_id']) ? $quant['location_id'][0] : $quant['location_id'];

        // ── 1. Resolve Shopify variant mapping ───────────────────────────
        $variantMapping = $this->mappings->findByOdooId(
            SyncMapping::TYPE_PRODUCT_VARIANT,
            (string) $odooProductId
        );

        if (!$variantMapping || !$variantMapping->shopify_secondary_id) {
            $this->logSkipped($odooProductId, $odooLocationId, 'missing_variant_mapping',
                'No variant mapping for this Odoo product variant.');
            Log::debug("InventorySyncService: no variant mapping for Odoo product #{$odooProductId}");
            return false;
        }

        // ── 2. Resolve Shopify location via ChannelMapping ───────────────
        //    First try the channel_mappings table (DB-driven, editable from UI).
        //    Fall back to the legacy config array for backwards compatibility.
        $shopifyLocationId = $this->resolveShopifyLocation((string) $odooLocationId);

        if (!$shopifyLocationId) {
            $this->logSkipped($odooProductId, $odooLocationId, 'missing_shopify_location_map',
                'No Shopify location mapped for this Odoo internal location.');
            Log::debug("InventorySyncService: no Shopify location for Odoo location #{$odooLocationId}");
            return false;
        }

        // ── 3. Calculate available qty ───────────────────────────────────
        $available = $this->odooInventory->availableQty($quant);

        // ── 4. Push to Shopify ───────────────────────────────────────────
        $log = SyncLog::create([
            'direction'       => SyncLog::DIRECTION_ODOO_TO_SHOPIFY,
            'entity_type'     => SyncMapping::TYPE_INVENTORY_ITEM,
            'entity_id'       => (string) $odooProductId,
            'action'          => 'update',
            'status'          => SyncLog::STATUS_PROCESSING,
            'request_payload' => json_encode([
                'inventory_item_id'  => $variantMapping->shopify_secondary_id,
                'shopify_location_id' => $shopifyLocationId,
                'odoo_location_id'   => (string) $odooLocationId,
                'available'          => $available,
            ]),
        ]);

        try {
            $this->shopifyInventory->setLevel(
                $variantMapping->shopify_secondary_id,
                $shopifyLocationId,
                $available
            );

            $log->markSuccess("Set to {$available}");
            Log::info("InventorySyncService: Odoo product #{$odooProductId} qty={$available} → Shopify location {$shopifyLocationId}");

            return true;
        } catch (\Throwable $e) {
            $log->markFailed($e->getMessage());
            throw $e;
        }
    }

    // ────────────────────────────────────────────────────────────────────
    // Private helpers
    // ────────────────────────────────────────────────────────────────────

    /**
     * Resolve Odoo location ID → Shopify location ID.
     *
     * Priority:
     *   1. channel_mappings table (type=warehouse, channel=shopify|both)
     *   2. Legacy config('odoo.location_map') array
     */
    private function resolveShopifyLocation(string $odooLocationId): ?string
    {
        // 1 — DB mapping (editable from the Mappings UI)
        $mapping = ChannelMapping::ofType(ChannelMapping::TYPE_WAREHOUSE)
            ->forChannel(ChannelMapping::CHANNEL_SHOPIFY)
            ->active()
            ->where('odoo_id', $odooLocationId)
            ->first();

        if ($mapping?->external_id) {
            return $mapping->external_id;
        }

        // 2 — Legacy config fallback
        $configMap = config('odoo.location_map', []);
        return $configMap[$odooLocationId] ?? null;
    }

    /**
     * Create a skipped sync log entry.
     */
    private function logSkipped(
        int|string $odooProductId,
        int|string $odooLocationId,
        string $reason,
        string $notes
    ): void {
        $log = SyncLog::create([
            'direction'       => SyncLog::DIRECTION_ODOO_TO_SHOPIFY,
            'entity_type'     => SyncMapping::TYPE_INVENTORY_ITEM,
            'entity_id'       => (string) $odooProductId,
            'action'          => 'update',
            'status'          => SyncLog::STATUS_SKIPPED,
            'request_payload' => json_encode([
                'odoo_product_id'  => $odooProductId,
                'odoo_location_id' => $odooLocationId,
                'reason'           => $reason,
            ]),
        ]);

        $log->markSkipped($notes, [
            'odoo_product_id'  => (string) $odooProductId,
            'odoo_location_id' => (string) $odooLocationId,
        ]);
    }
}