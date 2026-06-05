<?php

namespace App\Jobs\Ecom;

use App\Models\SyncMapping;
use App\Models\SyncQueueState;
use App\Services\Ecom\EcomInterface;
use App\Services\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Pull inventory levels from Shopify and store as pending.
 * Used when inventory_sync_mode = ecom_to_erp (Shopify → Odoo).
 * Post Stock button then pushes pending rows to Odoo.
 */
class FetchEcomInventoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('sync');
    }

    public function handle(EcomInterface $ecom, SettingsService $settings): void
    {
        $state  = SyncQueueState::forType('inventory');
        $driver = $ecom->driverName();

        Log::info("FetchEcomInventoryJob [{$driver}]: fetching inventory levels from Shopify");

        // Resolve Shopify location
        $locationId = $settings->get('shopify_location_id')
            ?? app(\App\Services\Shopify\ShopifyInventoryService::class)->getFirstLocationId();

        if (!$locationId) {
            Log::error("FetchEcomInventoryJob [{$driver}]: no shopify_location_id configured — aborting.");
            $state->update(['notes' => 'error:no_location_id', 'last_poll_at' => now()]);
            return;
        }

        // Collect all inventory_item_ids from stored product mappings.
        // Each product variant in Shopify has an inventory_item_id — these are stored
        // in the product metadata when FetchEcomProductsJob runs.
        $productMappings = SyncMapping::where('entity_type', 'product')
            ->whereIn('last_sync_direction', ['ecom_to_erp', 'shopify_to_erp', 'shopify_to_odoo'])
            ->whereNotNull('metadata')
            ->get();

        $inventoryItemIds = [];
        $itemToProduct    = [];  // inventory_item_id → ['ecom_id' => ..., 'sku' => ...]

        foreach ($productMappings as $pm) {
            $meta     = is_array($pm->metadata) ? $pm->metadata : json_decode($pm->metadata ?? '{}', true);
            $variants = $meta['variants'] ?? [];
            foreach ($variants as $variant) {
                $itemId = (string) ($variant['inventory_item_id'] ?? '');
                $sku    = $variant['sku'] ?? null;
                if (!$itemId || !$sku) continue;  // no SKU = can't match Odoo product, skip
                $inventoryItemIds[]    = $itemId;
                $itemToProduct[$itemId] = [
                    'ecom_id' => $pm->ecom_id,
                    'sku'     => $sku,
                ];
            }
        }

        if (empty($inventoryItemIds)) {
            Log::info("FetchEcomInventoryJob [{$driver}]: no inventory item IDs found in product mappings. Fetch products first.");
            $state->update(['notes' => 'nothing_changed', 'last_poll_at' => now()]);
            return;
        }

        // Shopify GraphQL caps node lookups at 250 — chunk to be safe
        $chunks  = array_chunk($inventoryItemIds, 100);
        $stored  = 0;
        $skipped = 0;

        foreach ($chunks as $chunk) {
            $levels = $ecom->getInventoryLevels($chunk, $locationId);

            foreach ($levels as $level) {
                $inventoryItemId = (string) ($level['inventory_item_id'] ?? '');
                if (!$inventoryItemId) continue;

                $existing = SyncMapping::where('entity_type', 'inventory')
                    ->where('ecom_id', $inventoryItemId)
                    ->where('ecom_driver', $driver)
                    ->first();

                if ($existing) {
                    $prevMeta = is_array($existing->metadata) ? $existing->metadata : json_decode($existing->metadata ?? '{}', true);
                    $prevQty  = $prevMeta['available'] ?? $prevMeta['quantity'] ?? null;
                    $newQty   = $level['available'] ?? $level['quantity'] ?? null;

                    // Skip if qty unchanged — regardless of status
                    // (posted + same qty = nothing to do; pending + same qty = already queued)
                    if ($prevQty !== null && $prevQty == $newQty) {
                        $skipped++;
                        continue;
                    }
                    // Qty changed — fall through to updateOrCreate to re-queue as pending
                }

                SyncMapping::updateOrCreate(
                    [
                        'entity_type' => 'inventory',
                        'ecom_id'     => $inventoryItemId,
                        'ecom_driver' => $driver,
                    ],
                    [
                        'ecom_status'         => 'pending',
                        'metadata'            => array_merge($level, [
                            'shopify_location_id' => $locationId,
                            'product_ecom_id'     => $itemToProduct[$inventoryItemId]['ecom_id'] ?? null,
                            'sku'                 => $itemToProduct[$inventoryItemId]['sku'] ?? null,
                        ]),
                        'last_synced_at'      => now(),
                        'last_sync_direction' => 'ecom_to_erp',
                    ]
                );
                $stored++;
            }
        }

        $notes = $stored === 0 ? 'nothing_changed' : "fetched:{$stored}";
        if ($skipped > 0) $notes .= ":skipped:{$skipped}";

        $state->update([
            'last_ecom_write_date' => now()->toIso8601String(),
            'last_poll_at'         => now(),
            'notes'                => $notes,
        ]);

        Log::info("FetchEcomInventoryJob [{$driver}]: stored={$stored} skipped={$skipped}");
    }
}