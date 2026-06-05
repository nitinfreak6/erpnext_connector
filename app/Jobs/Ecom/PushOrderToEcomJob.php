<?php

namespace App\Jobs\Ecom;

use App\Models\SyncLog;
use App\Models\SyncMapping;
use App\Services\Ecom\EcomInterface;
use App\Services\Erp\ErpInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PushOrderToEcomJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int   $tries   = 3;
    public array $backoff = [60, 300, 900];

    public function __construct(private readonly int $erpOrderId)
    {
        $this->onQueue('sync');
    }

    public function handle(EcomInterface $ecom, ErpInterface $erp): void
    {
        $driver = $ecom->driverName();

        // Look up the pending mapping stored by fetch() — entity_type is 'sales_order'
        $mapping = SyncMapping::whereIn('entity_type', ['sales_order', 'order'])
            ->where('erp_id', (string) $this->erpOrderId)
            ->first();

        // If already pushed and has an ecom_id, skip
        if ($mapping && $mapping->ecom_id) {
            Log::debug("PushOrderToEcomJob: ERP order #{$this->erpOrderId} already mapped to {$driver} #{$mapping->ecom_id} — skipping.");
            return;
        }

        $erpOrder = $erp->getOrder($this->erpOrderId);

        if (!$erpOrder) {
            Log::warning("PushOrderToEcomJob: ERP order #{$this->erpOrderId} not found in {$erp->driverName()}");
            return;
        }

        // order_line contains only IDs — fetch full line item data before sending to Shopify
        $lineIds = array_filter(
            is_array($erpOrder['order_line'] ?? null) ? $erpOrder['order_line'] : [],
            fn($v) => is_int($v) || (is_string($v) && ctype_digit($v))
        );

        if (!empty($lineIds)) {
            $lines = $erp->getOrderLines(array_values($lineIds));
            $erpOrder['order_line'] = $lines;
        } else {
            Log::warning("PushOrderToEcomJob: ERP order #{$this->erpOrderId} has no order lines");
        }

        $log = SyncLog::create([
            'direction'       => 'erp_to_ecom',
            'entity_type'     => 'order',
            'entity_id'       => (string) $this->erpOrderId,
            'action'          => $mapping?->ecom_id ? 'update' : 'create',
            'status'          => 'processing',
            'request_payload' => json_encode($erpOrder),
        ]);

        try {
            $result      = $ecom->createOrder($erpOrder);
            $ecomOrderId = (string) ($result['id'] ?? $result['order']['id'] ?? '');

            if (!$ecomOrderId) {
                throw new \RuntimeException("No order ID returned from {$driver}.");
            }

            // Update the existing pending mapping rather than creating a duplicate row
            if ($mapping) {
                $mapping->update([
                    'entity_type'         => 'sales_order',
                    'ecom_id'             => $ecomOrderId,
                    'ecom_driver'         => $ecom->driverName(),
                    'ecom_handle'         => $erpOrder['name'] ?? $mapping->ecom_handle,
                    'ecom_status'         => 'posted',
                    'last_synced_at'      => now(),
                    'last_sync_direction' => 'erp_to_ecom',
                ]);
            } else {
                SyncMapping::create([
                    'entity_type'         => 'sales_order',
                    'erp_id'              => (string) $this->erpOrderId,
                    'erp_driver'          => $erp->driverName(),
                    'ecom_id'             => $ecomOrderId,
                    'ecom_driver'         => $ecom->driverName(),
                    'ecom_handle'         => $erpOrder['name'] ?? null,
                    'ecom_status'         => 'posted',
                    'last_synced_at'      => now(),
                    'last_sync_direction' => 'erp_to_ecom',
                ]);
            }

            $log->markSuccess(json_encode(['ecom_order_id' => $ecomOrderId]));

            Log::info("PushOrderToEcomJob [{$driver}]: ERP order #{$this->erpOrderId} → {$driver} #{$ecomOrderId}");

        } catch (\Throwable $e) {
            $log->markFailed($e->getMessage());
            Log::error("PushOrderToEcomJob [{$driver}]: failed for ERP order #{$this->erpOrderId} — " . $e->getMessage());
            throw $e;
        }
    }
}