<?php

namespace App\Jobs\Ecom;

use App\Models\SyncLog;
use App\Models\SyncMapping;
use App\Services\Ecom\EcomInterface;
use App\Services\Erp\ErpInterface;
use App\Services\MappingService;
use App\Services\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PushInventoryToEcomJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly array $quant)
    {
        $this->onQueue('sync');
    }

    public function handle(
        EcomInterface   $ecom,
        ErpInterface    $erp,
        MappingService  $mappings,
        SettingsService $settings
    ): void {
        $erpProductId = (string) ($this->quant['product_id'][0] ?? '');

        if (!$erpProductId) {
            Log::warning('PushInventoryToEcomJob: quant has no product_id, skipping.');
            return;
        }

        // Find ecom product mapping — try variant first, fall back to product
        $mapping = $mappings->findByErpId('product_variant', $erpProductId);

        if (!$mapping) {
            $mapping = SyncMapping::where('entity_type', 'product')
                ->where('erp_id', $erpProductId)
                ->first();
        }

        if (!$mapping) {
            Log::debug("PushInventoryToEcomJob: no ecom mapping for ERP product #{$erpProductId}, skipping.");
            return;
        }

        // Resolve Shopify location ID from location mapping
        $erpLocationId    = (string) ($this->quant['location_id'][0] ?? '');
        $locationMap      = $settings->odooLocationMap();
        $shopifyLocationId = $locationMap[$erpLocationId] ?? null;

        if (!$shopifyLocationId) {
            // Fall back to first active Shopify location
            $shopifyLocationId = $settings->get('shopify_location_id');
        }

        if (!$shopifyLocationId) {
            Log::warning("PushInventoryToEcomJob: no Shopify location mapped for Odoo location #{$erpLocationId}. Add it in Settings → Location Mapping.");
            return;
        }

        $qty = $erp->availableQty($this->quant);

        $log = SyncLog::create([
            'direction'   => SyncLog::DIRECTION_ERP_TO_ECOM,
            'entity_type' => 'inventory',
            'entity_id'   => (string) $erpProductId,
            'action'      => 'update',
            'status'      => SyncLog::STATUS_PROCESSING,
            'request_payload' => json_encode([
                'erp_product_id'    => $erpProductId,
                'erp_location_id'   => $erpLocationId,
                'shopify_location_id' => $shopifyLocationId,
                'qty'               => $qty,
            ]),
        ]);

        try {
            // Pass the resolved Shopify location ID directly
            $ecom->updateInventory($mapping->ecom_id, $qty, $shopifyLocationId);
            $log->markSuccess("qty={$qty} location={$shopifyLocationId}");
            Log::info("PushInventoryToEcomJob [{$ecom->driverName()}]: ERP #{$erpProductId} qty={$qty} location={$shopifyLocationId} → ecom #{$mapping->ecom_id}");
        } catch (\Throwable $e) {
            $log->markFailed($e->getMessage());
            Log::error("PushInventoryToEcomJob: failed for ERP #{$erpProductId}: " . $e->getMessage());
            throw $e;
        }
    }
}