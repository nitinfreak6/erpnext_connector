<?php

namespace App\Jobs\Erp;

use App\Models\SyncLog;
use App\Services\Erp\ErpInterface;
use App\Services\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Push a Shopify inventory level to Odoo stock.quant.
 * Used when inventory_sync_mode = ecom_to_erp (Shopify → Odoo).
 */
class PushInventoryToErpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly array $inventoryLevel)
    {
        $this->onQueue('sync');
    }

    public function handle(ErpInterface $erp, SettingsService $settings): void
    {
        $level           = $this->inventoryLevel;
        $inventoryItemId = $level['inventory_item_id'] ?? $level['id'] ?? null;
        $qty             = (int) ($level['available'] ?? $level['quantity'] ?? 0);
        $sku             = $level['sku'] ?? null;

        Log::info("PushInventoryToErpJob: pushing inventory_item#{$inventoryItemId} qty={$qty}");

        // Resolve Odoo product by SKU or inventory_item_id
        $odoo      = app(\App\Services\Odoo\OdooService::class);
        $productId = null;

        if ($sku) {
            $results = $odoo->searchRead('product.product', [['default_code', '=', $sku]], ['id'], ['limit' => 1]);
            $productId = $results[0]['id'] ?? null;
        }

        if (!$productId) {
            Log::warning("PushInventoryToErpJob: could not resolve Odoo product for inventory_item#{$inventoryItemId} sku={$sku}");
            return;
        }

        // Update stock.quant in Odoo — set quantity for internal location
        // This uses inventory adjustment (apply_all) approach
        try {
            $locationId = $settings->get('default_odoo_location_id') ?? 8;

            // Search for existing quant
            $quants = $odoo->searchRead('stock.quant', [
                ['product_id', '=', $productId],
                ['location_id', '=', (int) $locationId],
            ], ['id', 'quantity'], ['limit' => 1]);

            if (!empty($quants)) {
                $odoo->executeKw('stock.quant', 'write', [[$quants[0]['id']], ['inventory_quantity' => $qty]]);
                $odoo->executeKw('stock.quant', 'action_apply_inventory', [[$quants[0]['id']]]);
            } else {
                // Create new quant
                $quantId = $odoo->create('stock.quant', [
                    'product_id'           => $productId,
                    'location_id'          => (int) $locationId,
                    'inventory_quantity'   => $qty,
                ]);
                $odoo->executeKw('stock.quant', 'action_apply_inventory', [[$quantId]]);
            }

            SyncLog::create([
                'direction'       => SyncLog::DIRECTION_ERP_TO_ECOM, // ecom_to_erp actually but reusing constant
                'entity_type'     => 'inventory',
                'entity_id'       => (string) $productId,
                'action'          => 'update_stock',
                'status'          => SyncLog::STATUS_SUCCESS,
                'request_payload' => json_encode(['qty' => $qty, 'sku' => $sku]),
            ]);

            Log::info("PushInventoryToErpJob: updated product#{$productId} qty={$qty} in Odoo");
        } catch (\Throwable $e) {
            Log::error("PushInventoryToErpJob: failed for inventory_item#{$inventoryItemId}: " . $e->getMessage());
            throw $e;
        }
    }
}