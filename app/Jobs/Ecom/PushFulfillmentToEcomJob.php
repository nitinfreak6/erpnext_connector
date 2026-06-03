<?php

namespace App\Jobs\Ecom;

use App\Models\SyncLog;
use App\Models\SyncMapping;
use App\Models\ProductFieldConfig;
use App\Services\Ecom\EcomInterface;
use App\Services\Erp\ErpInterface;
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
 * Deliberately bypasses UniversalSyncService to avoid entity routing issues.
 * Fulfillment payload is built from dispatch field configs when they exist,
 * with a sensible fallback when they don't.
 */
class PushFulfillmentToEcomJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly array $erpOrder)
    {
        $this->onQueue('sync');
    }

    public function handle(ErpInterface $erp, EcomInterface $ecom): void
    {
        $picking     = $this->erpOrder;
        $pickingId   = $picking['id'] ?? '?';
        $ecomOrderId = (string) ($picking['_ecom_order_id'] ?? '');

        if (!$ecomOrderId) {
            Log::warning("PushFulfillmentToEcomJob: picking#{$pickingId} missing _ecom_order_id — skipping.");
            return;
        }

        // Enrich with stock.moves for line items
        $moves = [];
        $moveIds = array_filter(
            is_array($picking['move_ids'] ?? null) ? $picking['move_ids'] : [],
            fn($id) => is_int($id) || (is_string($id) && ctype_digit($id))
        );
        if (!empty($moveIds)) {
            try {
                $rawMoves = $erp->getMoves(array_values($moveIds));
                // Guard: only keep moves that are proper arrays with at least an id
                $moves = array_filter($rawMoves, fn($m) => is_array($m) && isset($m['id']));
            } catch (\Throwable $e) {
                Log::warning("PushFulfillmentToEcomJob: getMoves failed for picking#{$pickingId}: " . $e->getMessage());
            }
        }

        // Build fulfillment payload from dispatch field configs when available,
        // fall back to sensible defaults so dispatch works before configs are set up.
        $payload = $this->buildPayload($picking, $moves, $ecom->driverName(), $erp->driverName());

        // entity_id must match what OrdersController::dispatch_log queries:
        // it looks up dispatch SyncLog by ecom_id OR erp_id of the order mapping.
        // Use the sale order erp_id (erp_order_id) so the dashboard can find it.
        $saleOrderId = (string) ($picking['erp_order_id']
            ?? (is_array($picking['sale_id'] ?? null) ? $picking['sale_id'][0] : ($picking['sale_id'] ?? $pickingId))
        );

        $log = SyncLog::create([
            'direction'       => SyncLog::DIRECTION_ERP_TO_ECOM,
            'entity_type'     => 'dispatch',
            'entity_id'       => $saleOrderId,   // matches order mapping erp_id for dashboard lookup
            'action'          => 'fulfill',
            'status'          => SyncLog::STATUS_PROCESSING,
            'request_payload' => json_encode($payload),
        ]);

        try {
            $result = $ecom->createFulfillment($ecomOrderId, $payload);

            if (!empty($result['skipped'])) {
                // Order already fulfilled in Shopify — not an error, just skip
                $log->markSuccess(json_encode(['status' => 'already_fulfilled']));
                Log::info("PushFulfillmentToEcomJob: picking#{$pickingId} skipped — ecom#{$ecomOrderId} already fulfilled.");
                return;
            }

            $log->markSuccess(json_encode(['fulfillment_id' => $result['id'] ?? null]));
            Log::info("PushFulfillmentToEcomJob [{$ecom->driverName()}]: picking#{$pickingId} → ecom#{$ecomOrderId}");
        } catch (\Throwable $e) {
            $log->markFailed($e->getMessage());
            Log::error("PushFulfillmentToEcomJob: picking#{$pickingId} failed: " . $e->getMessage());
            throw $e;
        }
    }

    private function buildPayload(array $picking, array $moves, string $ecomDriver, string $erpDriver): array
    {
        // Load dispatch header field configs
        $headerConfigs = ProductFieldConfig::where('entity_type', 'dispatch')
            ->where('ecom_driver', $ecomDriver)
            ->where('erp_driver', $erpDriver)
            ->where('scope', 'header')
            ->where('is_active', true)
            ->where('transform', '!=', 'line_container')
            ->get()
            ->keyBy('ecom_field');

        // Load dispatch line field configs
        $lineConfigs = ProductFieldConfig::where('entity_type', 'dispatch')
            ->where('ecom_driver', $ecomDriver)
            ->where('erp_driver', $erpDriver)
            ->where('scope', 'line')
            ->where('is_active', true)
            ->get();

        $hasConfigs = $headerConfigs->isNotEmpty() || $lineConfigs->isNotEmpty();

        if ($hasConfigs) {
            return $this->buildFromConfigs($picking, $moves, $headerConfigs, $lineConfigs);
        }

        // ── Fallback: no dispatch field configs exist yet ─────────────────
        Log::info("PushFulfillmentToEcomJob: no dispatch field configs found, using defaults");
        return $this->buildDefault($picking, $moves);
    }

    private function buildFromConfigs(array $picking, array $moves, $headerConfigs, $lineConfigs): array
    {
        $payload = ['notify_customer' => true];

        // Map header fields
        foreach ($headerConfigs as $ecomField => $config) {
            if ($config->field_type === 'custom') {
                $payload[$ecomField] = $config->default_value;
                continue;
            }

            $erpField = $config->erp_field;
            if (!$erpField) continue;

            $value = $picking[$erpField] ?? null;

            // array_second transform: [id, "Name"] → "Name"
            if ($config->transform === 'array_second' && is_array($value)) {
                $value = $value[1] ?? null;
            }

            if ($value !== null) {
                $payload[$ecomField] = $value;
            }
        }

        // Map line fields from stock.moves
        if ($lineConfigs->isNotEmpty() && !empty($moves)) {
            $lineItems = [];
            foreach ($moves as $move) {
                $line = [];
                foreach ($lineConfigs as $config) {
                    $ecomKey = last(explode('.', $config->ecom_field)); // line_items.quantity → quantity
                    $value   = $move[$config->erp_field] ?? null;

                    if ($config->transform === 'array_second' && is_array($value)) {
                        $value = $value[1] ?? null;
                    }

                    if ($value !== null) {
                        $line[$ecomKey] = $value;
                    }
                }
                if (!empty($line)) {
                    $lineItems[] = $line;
                }
            }
            if (!empty($lineItems)) {
                $payload['line_items'] = $lineItems;
            }
        }

        return $payload;
    }

    private function buildDefault(array $picking, array $moves): array
    {
        $payload = ['notify_customer' => true];

        if (!empty($picking['carrier_tracking_ref'])) {
            $payload['tracking_number'] = $picking['carrier_tracking_ref'];
        }

        if (!empty($picking['carrier_id'])) {
            $payload['tracking_company'] = is_array($picking['carrier_id'] ?? null)
                ? ($picking['carrier_id'][1] ?? '')
                : (string) ($picking['carrier_id'] ?? '');
        }

        if (!empty($moves)) {
            $lineItems = [];
            foreach ($moves as $move) {
                if (!is_array($move)) continue;
                $lineItems[] = [
                    'quantity' => (int) ($move['quantity'] ?? $move['quantity_done'] ?? 0),
                    'sku'      => is_array($move['product_id'] ?? null)
                        ? ($move['product_id'][1] ?? '')
                        : (string) ($move['product_id'] ?? ''),
                ];
            }
            if (!empty($lineItems)) {
                $payload['line_items'] = $lineItems;
            }
        }

        return $payload;
    }
}