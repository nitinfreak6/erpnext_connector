<?php

namespace App\Services\Sync;

use App\Models\SyncLog;
use App\Models\SyncMapping;
use App\Services\Ecom\EcomInterface;
use App\Services\Erp\ErpInterface;
use App\Services\MappingService;
use Illuminate\Support\Facades\Log;

/**
 * FIX #20: EcomInterface replaces ShopifyFulfillmentService + ShopifyOrderService.
 * Works with any ecom driver.
 */
class FulfillmentSyncService
{
    public function __construct(
        private readonly ErpInterface  $erp,
        private readonly EcomInterface $ecom,    // FIX: was ShopifyFulfillmentService + ShopifyOrderService
        private readonly MappingService $mappings,
    ) {}

    public function syncFulfillment(array $erpOrder): bool
    {
        $erpOrderId = (string) $erpOrder['id'];

        $orderMapping = $this->mappings->findByErpId(SyncMapping::TYPE_ORDER, $erpOrderId);

        if (!$orderMapping) {
            Log::debug("FulfillmentSyncService: no ecom mapping for ERP order #{$erpOrderId}, skipping.");
            return false;
        }

        $ecomOrderId = $orderMapping->ecom_id;

        $pickingIds = $erpOrder['picking_ids'] ?? [];
        if (empty($pickingIds)) {
            return false;
        }

        $pickings    = $this->erp->getPickings($pickingIds);
        $donePicking = null;

        foreach ($pickings as $picking) {
            if ($picking['state'] === 'done') {
                $donePicking = $picking;
                break;
            }
        }

        if (!$donePicking) {
            return false;
        }

        $moves = $donePicking['move_ids']
            ? $this->erp->getMoves($donePicking['move_ids'])
            : [];

        $fulfillmentData = [
            'tracking_number'  => $donePicking['carrier_tracking_ref'] ?? null,
            'tracking_company' => $donePicking['carrier_id'][1] ?? null,
            'line_items'       => $moves,
        ];

        $log = SyncLog::create([
            'direction'       => SyncLog::DIRECTION_ERP_TO_ECOM,
            'entity_type'     => 'fulfillment',
            'entity_id'       => $erpOrderId,
            'action'          => 'fulfill',
            'status'          => SyncLog::STATUS_PROCESSING,
            'request_payload' => json_encode($fulfillmentData),
        ]);

        try {
            // FIX: uses EcomInterface::createFulfillment() — not Shopify-specific
            $fulfillment = $this->ecom->createFulfillment($ecomOrderId, $fulfillmentData);
            $log->markSuccess(json_encode(['fulfillment_id' => $fulfillment['id'] ?? null]));
            Log::info("FulfillmentSyncService [{$this->ecom->driverName()}]: ERP #{$erpOrderId} → ecom #{$ecomOrderId}");
            return true;
        } catch (\Throwable $e) {
            $log->markFailed($e->getMessage());
            throw $e;
        }
    }

    public function syncCancellation(string $erpOrderId): bool
    {
        $orderMapping = $this->mappings->findByErpId(SyncMapping::TYPE_ORDER, $erpOrderId);

        if (!$orderMapping) {
            return false;
        }

        $log = SyncLog::create([
            'direction'   => SyncLog::DIRECTION_ERP_TO_ECOM,
            'entity_type' => SyncMapping::TYPE_ORDER,
            'entity_id'   => $erpOrderId,
            'action'      => 'cancel',
            'status'      => SyncLog::STATUS_PROCESSING,
        ]);

        try {
            // FIX: uses EcomInterface::cancelOrder() — not Shopify-specific
            $this->ecom->cancelOrder($orderMapping->ecom_id, 'Cancelled in ERP');
            $log->markSuccess('Cancelled');
            return true;
        } catch (\Throwable $e) {
            $log->markFailed($e->getMessage());
            throw $e;
        }
    }
}
