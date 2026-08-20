<?php

namespace App\Jobs\Ecom;

use App\Models\SyncMapping;
use App\Services\SettingsService;
use App\Services\Sync\InventorySyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PushInventoryToEcomJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly array $quant,
        private readonly ?int $syncMappingId = null,
    ) {
        $this->onQueue('sync');
    }

    public function handle(InventorySyncService $inventorySync, SettingsService $settings): void
    {
        $inventoryMapping = $this->syncMappingId !== null
            ? SyncMapping::find($this->syncMappingId)
            : null;

        if ($inventoryMapping === null) {
            $erpId = $inventorySync->inventorySyncErpIdFromQuant($this->quant);
            if ($erpId === '' || $erpId === '0') {
                throw new \RuntimeException(
                    'PushInventoryToEcomJob: quant missing ERP product reference — check inventory field configs.'
                );
            }

            $inventoryMapping = SyncMapping::where('entity_type', 'inventory')
                ->where('erp_id', $erpId)
                ->where('erp_driver', $settings->erpDriver())
                ->first();
        }

        $inventorySync->syncInventoryToEcom($this->quant, $inventoryMapping);
    }
}
