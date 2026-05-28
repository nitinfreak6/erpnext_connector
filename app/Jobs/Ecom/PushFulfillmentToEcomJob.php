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
 * FIX #16: Replaces PushFulfillmentToShopifyJob.
 * Uses EcomInterface — works with any ecom driver.
 */
class PushFulfillmentToEcomJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly array $erpOrder)
    {
        $this->onQueue('sync');
    }

    public function handle(EcomInterface $ecom, ErpInterface $erp, MappingService $mappings): void
    {
        $erpOrderId = (string) $this->erpOrder['id'];

        $mapping = $mappings->findByErpId('order', $erpOrderId);

        if (!$mapping) {
            Log::debug("PushFulfillmentToEcomJob: no ecom mapping for ERP order #{$erpOrderId}, skipping.");
            return;
        }

        $ecomOrderId = $mapping->ecom_id;

        $pickingIds = $this->erpOrder['picking_ids'] ?? [];

        if (empty($pickingIds)) {
            return;
        }

        $pickings    = $erp->getPickings($pickingIds);
        $donePicking = null;

        foreach ($pickings as $picking) {
            if ($picking['state'] === 'done') {
                $donePicking = $picking;
                break;
            }
        }

        if (!$donePicking) {
            return;
        }

        $moves = $donePicking['move_ids']
            ? $erp->getMoves($donePicking['move_ids'])
            : [];

        $fulfillmentData = [
            'tracking_number'  => $donePicking['carrier_tracking_ref'] ?? null,
            'tracking_company' => isset($donePicking['carrier_id'][1]) ? $donePicking['carrier_id'][1] : null,
            'line_items'       => $moves,
        ];

        try {
            $ecom->createFulfillment($ecomOrderId, $fulfillmentData);
            Log::info("PushFulfillmentToEcomJob [{$ecom->driverName()}]: ERP #{$erpOrderId} → ecom #{$ecomOrderId}");
        } catch (\Throwable $e) {
            Log::error("PushFulfillmentToEcomJob: failed for ERP #{$erpOrderId}: " . $e->getMessage());
            throw $e;
        }
    }
}
