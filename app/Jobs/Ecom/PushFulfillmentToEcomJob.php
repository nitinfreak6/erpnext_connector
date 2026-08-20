<?php

namespace App\Jobs\Ecom;

use App\Models\SyncLog;
use App\Services\Ecom\EcomInterface;
use App\Services\Sync\UniversalSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Push a single fulfilled stock.picking to ecom as a fulfillment.
 * Callers must inject _ecom_order_id into the picking array.
 *
 * Payload is built via UniversalSyncService + dispatch field configs (header + line).
 */
class PushFulfillmentToEcomJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly array $erpOrder)
    {
        $this->onQueue('sync');
    }

    public function handle(EcomInterface $ecom, UniversalSyncService $sync): void
    {
        $picking     = $this->erpOrder;
        $pickingId   = $picking['id'] ?? '?';
        $ecomOrderId = (string) ($picking['_ecom_order_id'] ?? '');

        if (!$ecomOrderId) {
            Log::warning("PushFulfillmentToEcomJob: picking#{$pickingId} missing _ecom_order_id — skipping.");
            return;
        }

        $erpPayload = $sync->enrichEntityLines('dispatch', array_merge($picking, [
            '_ecom_order_id' => $ecomOrderId,
        ]));

        $mapped = $sync->buildEcomPayloadForEntity('dispatch', $erpPayload, 'header', [
            'ecom_order_id' => $ecomOrderId,
            'picking_id'    => (string) $pickingId,
        ]);

        if ($mapped === []) {
            throw new \RuntimeException(
                'No dispatch field mappings produced a fulfillment payload. '
                . 'Add active dispatch field configs (entity=dispatch, direction erp_to_ecom) in Field Config.'
            );
        }

        if (array_key_exists('notify_customer', $mapped)) {
            $mapped['notify_customer'] = filter_var($mapped['notify_customer'], FILTER_VALIDATE_BOOLEAN);
        }

        $saleOrderId = (string) ($picking['erp_order_id']
            ?? (is_array($picking['sale_id'] ?? null) ? $picking['sale_id'][0] : ($picking['sale_id'] ?? $pickingId))
        );

        $log = SyncLog::create([
            'direction'       => SyncLog::DIRECTION_ERP_TO_ECOM,
            'entity_type'     => 'dispatch',
            'entity_id'       => (string) $pickingId,
            'action'          => 'fulfill',
            'status'          => SyncLog::STATUS_PROCESSING,
            'request_payload' => json_encode([
                'mapped_payload' => $mapped,
                'picking_id'     => (string) $pickingId,
                'erp_order_id'   => $saleOrderId,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        try {
            $result = $ecom->createFulfillment($ecomOrderId, $mapped);

            if (!empty($result['skipped'])) {
                $log->markSuccess(json_encode(['status' => 'already_fulfilled']));
                Log::info("PushFulfillmentToEcomJob: picking#{$pickingId} skipped — ecom#{$ecomOrderId} already fulfilled.");

                return;
            }

            $response = ['fulfillment_id' => $result['id'] ?? null];
            if (!empty($result['wire_input'])) {
                $log->update([
                    'request_payload' => json_encode([
                        'mapped_payload' => $mapped,
                        'wire_input'     => $result['wire_input'],
                        'picking_id'     => (string) $pickingId,
                        'erp_order_id'   => $saleOrderId,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            }

            $log->markSuccess(json_encode($response, JSON_UNESCAPED_UNICODE));
            Log::info("PushFulfillmentToEcomJob [{$ecom->driverName()}]: picking#{$pickingId} → ecom#{$ecomOrderId}");
        } catch (\Throwable $e) {
            $wireInput = null;
            try {
                $wireInput = app(\App\Services\Shopify\ShopifyFulfillmentService::class)
                    ->buildWireInputForLog($ecomOrderId, $mapped);
            } catch (\Throwable $wireEx) {
                Log::debug("PushFulfillmentToEcomJob: could not build wire log: " . $wireEx->getMessage());
            }

            $requestPayload = [
                'mapped_payload' => $mapped,
                'picking_id'     => (string) $pickingId,
                'erp_order_id'   => $saleOrderId,
            ];
            if ($wireInput !== null && $wireInput !== []) {
                $requestPayload['wire_input'] = $wireInput;
            }

            $log->update([
                'request_payload' => json_encode($requestPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'response_payload' => json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE),
            ]);
            $log->markFailed($e->getMessage());
            Log::error("PushFulfillmentToEcomJob: picking#{$pickingId} failed: " . $e->getMessage());
            throw $e;
        }
    }
}
