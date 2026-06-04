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
        $since  = $state->last_ecom_write_date ?? now()->subDays(30)->toIso8601String();

        Log::info("FetchEcomInventoryJob [{$driver}]: fetching inventory since {$since}");

        // Get inventory levels from Shopify
        $levels = $ecom->getInventoryLevels(['updated_at_min' => $since]);

        $stored  = 0;
        $skipped = 0;

        foreach ($levels as $level) {
            $inventoryItemId = (string) ($level['inventory_item_id'] ?? $level['id'] ?? '');
            $updatedAt       = $level['updated_at'] ?? null;

            if (!$inventoryItemId) continue;

            // Skip if unchanged since last sync
            $existing = SyncMapping::where('entity_type', 'inventory')
                ->where('ecom_id', $inventoryItemId)
                ->where('ecom_driver', $driver)
                ->first();

            if ($existing) {
                $prevMeta      = is_array($existing->metadata) ? $existing->metadata : json_decode($existing->metadata ?? '{}', true);
                $prevUpdatedAt = $prevMeta['updated_at'] ?? null;
                $prevQty       = $prevMeta['available'] ?? $prevMeta['quantity'] ?? null;
                $newQty        = $level['available'] ?? $level['quantity'] ?? null;

                if ($prevUpdatedAt && $updatedAt && $prevUpdatedAt === $updatedAt && $prevQty == $newQty) {
                    $skipped++;
                    continue;
                }

                // Also skip if pending and unchanged
                if ($existing->ecom_status === 'pending' && $prevUpdatedAt === $updatedAt) {
                    $skipped++;
                    continue;
                }
            }

            SyncMapping::updateOrCreate(
                [
                    'entity_type' => 'inventory',
                    'ecom_id'     => $inventoryItemId,
                    'ecom_driver' => $driver,
                ],
                [
                    'ecom_status'         => 'pending',
                    'metadata'            => $level,
                    'last_synced_at'      => now(),
                    'last_sync_direction' => 'ecom_to_erp',
                ]
            );
            $stored++;
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