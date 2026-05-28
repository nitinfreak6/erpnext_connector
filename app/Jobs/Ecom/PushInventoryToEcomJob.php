<?php

namespace App\Jobs\Ecom;

use App\Services\Ecom\EcomInterface;
use App\Services\MappingService;
use App\Services\Erp\ErpInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * FIX #17: Replaces PushInventoryToShopifyJob.
 * Uses EcomInterface — works with any ecom driver.
 */
class PushInventoryToEcomJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly array $quant)
    {
        $this->onQueue('sync');
    }

    public function handle(EcomInterface $ecom, ErpInterface $erp, MappingService $mappings): void
    {
        $erpProductId = (string) ($this->quant['product_id'][0] ?? '');

        if (!$erpProductId) {
            Log::warning('PushInventoryToEcomJob: quant has no product_id, skipping.');
            return;
        }

        // Find the ecom variant mapping
        $mapping = $mappings->findByErpId('product_variant', $erpProductId);

        if (!$mapping) {
            Log::debug("PushInventoryToEcomJob: no ecom mapping for ERP product #{$erpProductId}, skipping.");
            return;
        }

        $qty = $erp->availableQty($this->quant);

        try {
            $ecom->updateInventory($mapping->ecom_id, $qty);
            Log::info("PushInventoryToEcomJob [{$ecom->driverName()}]: ERP #{$erpProductId} qty={$qty} → ecom #{$mapping->ecom_id}");
        } catch (\Throwable $e) {
            Log::error("PushInventoryToEcomJob: failed for ERP #{$erpProductId}: " . $e->getMessage());
            throw $e;
        }
    }
}
