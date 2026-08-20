<?php

namespace App\Jobs\Ecom;

use App\Models\SyncMapping;
use App\Services\Sync\InventorySyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Push an e-commerce inventory level to ERP (ecom_to_erp direction).
 */
class PushInventoryToErpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly array $inventoryLevel)
    {
        $this->onQueue('sync');
    }

    public function handle(InventorySyncService $inventorySync): void
    {
        $inventoryItemId = app(InventorySyncService::class)->resolveSyncEntityEcomId($this->inventoryLevel);

        $inventoryMapping = $inventoryItemId
            ? SyncMapping::where('entity_type', 'inventory')->where('ecom_id', $inventoryItemId)->first()
            : null;

        try {
            $inventorySync->syncInventoryToErp($this->inventoryLevel, $inventoryMapping);
        } catch (\Throwable $e) {
            Log::error("PushInventoryToErpJob: failed for inventory_item#{$inventoryItemId}: " . $e->getMessage());
            throw $e;
        }
    }
}
