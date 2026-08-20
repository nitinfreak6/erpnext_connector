<?php

namespace App\Jobs\Erp;

use App\Models\SyncLog;
use App\Services\Erp\ErpInterface;
use App\Services\Sync\SyncEntityState;
use App\Services\Sync\UniversalSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Push a single e-commerce fulfillment to ERP (validate delivery / create Delivery Note).
 * Callers must inject erp_order_id and _ecom_order_id on the fulfillment array.
 */
class PushFulfillmentToErpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly array $fulfillment)
    {
        $this->onQueue('sync');
    }

    public function handle(ErpInterface $erp, UniversalSyncService $sync): int|string
    {
        $fulfillment   = $this->fulfillment;
        $fulfillmentId = (string) ($fulfillment['id'] ?? '?');
        $saleOrderId   = $fulfillment['erp_order_id'] ?? null;

        if ($saleOrderId === null || $saleOrderId === '' || $saleOrderId === 0 || $saleOrderId === '0') {
            Log::warning("PushFulfillmentToErpJob: fulfillment#{$fulfillmentId} missing erp_order_id — skipping.");

            throw new \RuntimeException(
                "Fulfillment #{$fulfillmentId} is missing erp_order_id. Post the sale order to the ERP first."
            );
        }

        $mapped = $sync->buildErpPayloadForEntity('dispatch', $fulfillment, 'header');

        if ($mapped === [] && $erp->dispatchFulfillmentRequiresMappedPayload()) {
            throw new \RuntimeException(
                'No dispatch field mappings produced an ERP payload. '
                . 'Add active dispatch field configs (entity=dispatch, direction ecom_to_erp) in Field Config.'
            );
        }

        $log = SyncLog::create([
            'direction'       => SyncLog::DIRECTION_ECOM_TO_ERP,
            'entity_type'     => 'dispatch',
            'entity_id'       => $fulfillmentId,
            'action'          => 'fulfill',
            'status'          => SyncLog::STATUS_PROCESSING,
            'request_payload' => json_encode([
                'mapped_payload' => $mapped,
                'fulfillment_id' => $fulfillmentId,
                'erp_order_id'   => $saleOrderId,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        try {
            $result    = $erp->applyFulfillmentToSaleOrder($saleOrderId, $mapped, $fulfillment);
            $pickingId = SyncEntityState::requireErpId(
                $result['picking_id'] ?? null,
                'Delivery note id',
            );
            $wire      = $result['wire'] ?? [];

            $log->update([
                'request_payload' => json_encode([
                    'mapped_payload' => $mapped,
                    'wire_payload'   => $wire,
                    'fulfillment_id' => $fulfillmentId,
                    'erp_order_id'   => $saleOrderId,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            $log->markSuccess(json_encode([
                'picking_id' => $pickingId,
                'erp_calls'  => $wire,
            ], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));

            Log::info("PushFulfillmentToErpJob [{$erp->driverName()}]: fulfillment#{$fulfillmentId} → picking#{$pickingId}");

            return $pickingId;
        } catch (\Throwable $e) {
            $wire = method_exists($erp, 'takeWireLog') ? $erp->takeWireLog() : [];

            $requestPayload = [
                'mapped_payload' => $mapped,
                'fulfillment_id' => $fulfillmentId,
                'erp_order_id'   => $saleOrderId,
            ];
            if ($wire !== []) {
                $requestPayload['wire_payload'] = $wire;
            }

            $log->update([
                'request_payload'  => json_encode($requestPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'response_payload' => json_encode([
                    'error'     => $e->getMessage(),
                    'erp_calls' => $wire,
                ], JSON_UNESCAPED_UNICODE),
            ]);
            $log->markFailed($e->getMessage());
            Log::error("PushFulfillmentToErpJob: fulfillment#{$fulfillmentId} failed: " . $e->getMessage());
            throw $e;
        }
    }
}
