<?php

namespace App\Jobs\Ecom;  // file lives in Jobs/Ecom/ — namespace corrected from Jobs\Erp

use App\Models\SyncLog;
use App\Models\SyncMapping;
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

        // ── Resolve SKU ───────────────────────────────────────────────────
        // Stored directly in metadata by FetchEcomInventoryJob (from variant.sku).
        // Fall back to looking up the product SyncMapping if missing (old rows).
        $sku = $level['sku'] ?? null;

        if (!$sku && !empty($level['product_ecom_id'])) {
            $productMapping = SyncMapping::where('entity_type', 'product')
                ->where('ecom_id', (string) $level['product_ecom_id'])
                ->whereNotNull('metadata')
                ->first();

            if ($productMapping) {
                $meta     = is_array($productMapping->metadata)
                    ? $productMapping->metadata
                    : json_decode($productMapping->metadata ?? '{}', true);

                // Find the variant whose inventory_item_id matches
                foreach ($meta['variants'] ?? [] as $variant) {
                    if ((string) ($variant['inventory_item_id'] ?? '') === (string) $inventoryItemId) {
                        $sku = $variant['sku'] ?? null;
                        break;
                    }
                }

                // If still no match (single-variant product), take first variant SKU
                if (!$sku && !empty($meta['variants'][0]['sku'])) {
                    $sku = $meta['variants'][0]['sku'];
                }
            }
        }

        Log::info("PushInventoryToErpJob: pushing inventory_item#{$inventoryItemId} sku={$sku} qty={$qty}");

        if (!$sku) {
            Log::warning("PushInventoryToErpJob: no SKU resolved for inventory_item#{$inventoryItemId} — skipping.");
            return;
        }

        // ── Resolve Odoo product by SKU ───────────────────────────────────
        $odoo      = app(\App\Services\Odoo\OdooService::class);
        $results   = $odoo->searchRead('product.product', [['default_code', '=', $sku]], ['id'], ['limit' => 1]);
        $productId = $results[0]['id'] ?? null;

        if (!$productId) {
            Log::warning("PushInventoryToErpJob: no Odoo product found for sku={$sku} — skipping.");
            return;
        }

        // ── Update stock.quant in Odoo ────────────────────────────────────
        try {
            $locationId = (int) ($settings->get('default_odoo_location_id') ?? 8);

            $quants = $odoo->searchRead('stock.quant', [
                ['product_id', '=', $productId],
                ['location_id', '=', $locationId],
            ], ['id', 'quantity'], ['limit' => 1]);

            if (!empty($quants)) {
                $odoo->executeKw('stock.quant', 'write', [[$quants[0]['id']], ['inventory_quantity' => $qty]]);
                try {
                    $odoo->executeKw('stock.quant', 'action_apply_inventory', [[$quants[0]['id']]]);
                } catch (\Throwable $ignored) {
                    Log::info("PushInventoryToErpJob: action_apply_inventory marshal error ignored (expected on SaaS 17/18).");
                }
            } else {
                try {
                    $quantId = $odoo->create('stock.quant', [
                        'product_id'         => $productId,
                        'location_id'        => $locationId,
                        'inventory_quantity' => $qty,
                    ]);
                    try {
                        $odoo->executeKw('stock.quant', 'action_apply_inventory', [[$quantId]]);
                    } catch (\Throwable $ignored) {
                        Log::info("PushInventoryToErpJob: action_apply_inventory marshal error ignored (expected on SaaS 17/18).");
                    }
                } catch (\Throwable $createErr) {
                    if (str_contains($createErr->getMessage(), 'consumables or services')) {
                        Log::warning("PushInventoryToErpJob: product#{$productId} (sku={$sku}) is a consumable/service — stock quant not applicable, skipping.");
                        return;  // not a failure — just not a storable product
                    }
                    throw $createErr;
                }
            }

            SyncLog::create([
                'direction'       => SyncLog::DIRECTION_ECOM_TO_ERP,
                'entity_type'     => 'inventory',
                'entity_id'       => (string) $inventoryItemId,
                'action'          => 'update_stock',
                'status'          => SyncLog::STATUS_SUCCESS,
                'request_payload' => json_encode(['qty' => $qty, 'sku' => $sku, 'product_id' => $productId]),
            ]);

            Log::info("PushInventoryToErpJob: updated product#{$productId} (sku={$sku}) qty={$qty} in Odoo");

        } catch (\Throwable $e) {
            SyncLog::create([
                'direction'       => SyncLog::DIRECTION_ECOM_TO_ERP,
                'entity_type'     => 'inventory',
                'entity_id'       => (string) $inventoryItemId,
                'action'          => 'update_stock',
                'status'          => SyncLog::STATUS_FAILED,
                'error_message'   => $e->getMessage(),
                'request_payload' => json_encode(['qty' => $qty, 'sku' => $sku]),
            ]);
            Log::error("PushInventoryToErpJob: failed for inventory_item#{$inventoryItemId}: " . $e->getMessage());
            throw $e;
        }
    }
}