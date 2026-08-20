<?php

namespace App\Jobs\Ecom;

use App\Models\SyncMapping;
use App\Models\SyncQueueState;
use App\Services\ChannelMappingService;
use App\Services\Ecom\EcomInterface;
use App\Services\SettingsService;
use App\Services\Sync\InventoryItemCatalog;
use App\Services\Sync\InventorySyncService;
use App\Services\Sync\SyncEntityState;
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

        $locationId = app(ChannelMappingService::class)->defaultShopifyWarehouseLocationId();

        if (!$locationId) {
            Log::error("FetchEcomInventoryJob [{$driver}]: no Shopify warehouse location configured — aborting.");
            $state->update(['notes' => 'error:no_location_id', 'last_poll_at' => now()]);
            return;
        }

        [$inventoryItemIds, $itemToProduct] = InventoryItemCatalog::collectForDriver($driver, $ecom);

        if (empty($inventoryItemIds)) {
            $productCount = SyncMapping::where('entity_type', 'product')
                ->where('ecom_driver', $driver)
                ->whereNotNull('ecom_id')
                ->count();

            $note = $productCount > 0
                ? 'error:no_tracked_inventory'
                : 'error:fetch_products_first';

            Log::info("FetchEcomInventoryJob [{$driver}]: no inventory item IDs found ({$note}).");
            $state->update(['notes' => $note, 'last_poll_at' => now()]);
            return;
        }

        $inventoryItemIds = array_values(array_unique($inventoryItemIds));
        $chunks           = array_chunk($inventoryItemIds, 100);
        $stored           = 0;
        $skipped          = 0;

        $inventorySync = app(InventorySyncService::class);

        foreach ($chunks as $chunk) {
            $levels = $ecom->getInventoryLevels($chunk, $locationId);

            foreach ($levels as $level) {
                $inventoryItemId = $inventorySync->resolveSyncEntityEcomId($level);
                if (!$inventoryItemId) {
                    continue;
                }

                $existing = SyncMapping::where('entity_type', 'inventory')
                    ->where('ecom_id', $inventoryItemId)
                    ->where('ecom_driver', $driver)
                    ->first();

                $levelMeta = $inventorySync->buildMappingSourcePayload($level, $locationId, [
                    'product_ecom_id' => $itemToProduct[$inventoryItemId]['ecom_id'] ?? null,
                    'sku'             => $itemToProduct[$inventoryItemId]['sku'] ?? null,
                ]);

                $changed = SyncEntityState::markFetched(
                    'inventory',
                    ['ecom_id' => $inventoryItemId, 'ecom_driver' => $driver],
                    $levelMeta,
                    $existing,
                    'ecom_to_erp'
                );

                if ($changed) {
                    $stored++;
                } else {
                    $skipped++;
                }
            }
        }

        $notes = $stored === 0 ? 'nothing_changed' : "fetched:{$stored}";
        if ($skipped > 0) {
            $notes .= ":skipped:{$skipped}";
        }

        $state->update([
            'last_ecom_write_date' => now()->toIso8601String(),
            'last_poll_at'         => now(),
            'notes'                => $notes,
        ]);

        Log::info("FetchEcomInventoryJob [{$driver}]: stored={$stored} skipped={$skipped}");
    }
}
