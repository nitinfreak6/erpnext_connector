<?php

namespace App\Jobs\Amazon;

use App\Models\SyncLog;
use App\Models\SyncMapping;
use App\Services\Amazon\AmazonInventoryService;
use App\Services\Erp\ErpInterface;
use App\Services\MappingService;
use App\Services\Sync\AmazonProductSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PushInventoryToAmazonJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int   $tries   = 3;
    public array $backoff = [60, 300, 900];
    public int   $timeout = 60;

    public function __construct(private readonly array $quant)
    {
        $this->onQueue('sync');
    }

    public function uniqueId(): string
    {
        $productId = is_array($this->quant['product_id'])
            ? $this->quant['product_id'][0]
            : $this->quant['product_id'];

        return "amazon_inventory_{$productId}";
    }

    public function handle(
        AmazonInventoryService $amazonInventory,
        MappingService         $mappings,
        ErpInterface           $erp
    ): void {
        if (config('amazon.fulfillment_channel') === 'FBA') {
            return;
        }

        $productId = is_array($this->quant['product_id'])
            ? $this->quant['product_id'][0]
            : $this->quant['product_id'];

        $variantMapping = $mappings->findByErpId(
            AmazonProductSyncService::ENTITY_VARIANT,
            (string) $productId
        );

        if (!$variantMapping) {
            $variantMapping = $mappings->findByOdooId(
                AmazonProductSyncService::ENTITY_VARIANT,
                (string) $productId
            );
        }

        if (!$variantMapping) {
            Log::debug("PushInventoryToAmazonJob: no Amazon mapping for ERP product #{$productId}, skipping.");
            return;
        }

        $sku       = $variantMapping->erp_reference ?? $variantMapping->odoo_reference;
        $available = $erp->availableQty($this->quant);

        $log = SyncLog::create([
            'direction'       => 'erp_to_ecom',
            'entity_type'     => 'amazon_inventory',
            'entity_id'       => (string) $productId,
            'action'          => 'update',
            'status'          => SyncLog::STATUS_PROCESSING,
            'request_payload' => json_encode(['sku' => $sku, 'qty' => $available]),
        ]);

        try {
            $amazonInventory->updateQuantity($sku, $available);
            $log->markSuccess("Set to {$available}");
            Log::info("PushInventoryToAmazonJob [{$erp->driverName()}]: SKU={$sku} qty={$available}");
        } catch (\Throwable $e) {
            $log->markFailed($e->getMessage());
            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('PushInventoryToAmazonJob failed', ['error' => $e->getMessage()]);
    }
}