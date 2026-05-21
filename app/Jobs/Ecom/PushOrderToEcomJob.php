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

/**
 * PushOrderToEcomJob
 * 
 * Pushes a single ERP order TO ecom platform as a draft order.
 * Direction: erp_to_ecom
 */
class PushOrderToEcomJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly int $erpOrderId
    ) {
        $this->onQueue('sync');
    }

    public function handle(
        EcomInterface $ecom,
        ErpInterface $erp
    ): void {
        $driver = $ecom->driverName();
        
        try {
            // Get ERP order details
            $erpOrder = $erp->getOrder($this->erpOrderId);
            
            if (!$erpOrder) {
                Log::warning("PushOrderToEcomJob: ERP order #{$this->erpOrderId} not found");
                return;
            }

            // Check if already mapped
            $mapping = SyncMapping::where('entity_type', 'order')
                ->where('erp_id', (string) $this->erpOrderId)
                ->first();

            if ($mapping) {
                Log::debug("PushOrderToEcomJob: ERP order #{$this->erpOrderId} already mapped to {$driver} #{$mapping->ecom_id}");
                return;
            }

            $log = SyncLog::create([
                'direction' => 'erp_to_ecom',
                'entity_type' => 'order',
                'entity_id' => (string) $this->erpOrderId,
                'action' => 'create',
                'status' => 'processing',
                'request_payload' => json_encode($erpOrder),
            ]);

            // Create draft order in Shopify via GraphQL
            $draftOrderId = $this->createShopifyDraftOrder($erpOrder);

            // Create mapping
            SyncMapping::create([
                'entity_type' => 'order',
                'erp_id' => (string) $this->erpOrderId,
                'ecom_id' => $draftOrderId,
                'ecom_handle' => $erpOrder['name'] ?? null,
                'last_synced_at' => now(),
                'last_sync_direction' => 'erp_to_ecom',
            ]);

            $log->markSuccess(json_encode(['ecom_order_id' => $draftOrderId]));
            Log::info("PushOrderToEcomJob: Pushed ERP order #{$this->erpOrderId} ({$erpOrder['name']}) → {$driver} draft order #{$draftOrderId}");

        } catch (\Throwable $e) {
            if (isset($log)) {
                $log->markFailed($e->getMessage());
            }
            Log::error("PushOrderToEcomJob: Failed to push ERP order #{$this->erpOrderId}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Create draft order in Shopify using GraphQL
     */
    private function createShopifyDraftOrder(array $erpOrder): string
    {
        $graphql = app(\App\Services\Shopify\ShopifyGraphQLService::class);
        
        // Get customer mapping
        $customerId = null;
        if (!empty($erpOrder['partner_id'][0])) {
            $customerMapping = SyncMapping::where('entity_type', 'customer')
                ->where('erp_id', (string) $erpOrder['partner_id'][0])
                ->first();
            if ($customerMapping) {
                $customerId = 'gid://shopify/Customer/' . $customerMapping->ecom_id;
            }
        }

        // Build line items from order lines
        $lineItems = [];
        $orderLineIds = $erpOrder['order_line'] ?? [];
        if (!empty($orderLineIds)) {
            $erp = app(\App\Services\Erp\ErpInterface::class);
            $orderLines = $erp->getOrderLines($orderLineIds);
            
            foreach ($orderLines as $line) {
                $productId = $line['product_id'][0] ?? null;
                if (!$productId) continue;
                
                // Find variant mapping
                $variantMapping = SyncMapping::where('entity_type', 'product_variant')
                    ->where('erp_id', (string) $productId)
                    ->first();
                
                if (!$variantMapping) {
                    Log::warning("PushOrderToEcomJob: No mapping for product #{$productId}, skipping line");
                    continue;
                }
                
                $lineItems[] = [
                    'variantId' => 'gid://shopify/ProductVariant/' . $variantMapping->ecom_id,
                    'quantity' => (int) ($line['product_uom_qty'] ?? 1),
                ];
            }
        }

        if (empty($lineItems)) {
            throw new \Exception("No valid line items found for order");
        }

        // Create order mutation (use this if write_draft_orders scope unavailable)
        // For draft orders, uncomment the mutation below and comment this one
        $mutation = <<<'GQL'
        mutation orderCreate($order: OrderCreateOrderInput!) {
            orderCreate(order: $order) {
                order {
                    id
                    name
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GQL;

        $input = [
            'lineItems' => $lineItems,
            'note' => "Imported from ERP Order: {$erpOrder['name']}",
            'tags' => ['erp-import'],
        ];

        if ($customerId) {
            $input['customerId'] = $customerId;
        }

        $variables = ['order' => $input];

        $response = $graphql->query($mutation, $variables);

        // Debug: Log the full response to see structure
        Log::debug('Shopify orderCreate response', ['response' => $response]);

        // Check for userErrors (response is unwrapped, no 'data' key)
        if (!empty($response['orderCreate']['userErrors'])) {
            $errors = collect($response['orderCreate']['userErrors'])
                ->pluck('message')
                ->implode(', ');
            throw new \Exception("Shopify order creation failed: {$errors}");
        }

        // Get order ID from unwrapped response
        $orderGid = $response['orderCreate']['order']['id'] ?? null;
        if (!$orderGid) {
            Log::error('No order ID found in response', [
                'full_response' => $response,
                'response_keys' => array_keys($response ?? []),
            ]);
            throw new \Exception("No order ID returned from Shopify. Check logs for full response.");
        }

        // Extract numeric ID from GID
        preg_match('/\/(\d+)$/', $orderGid, $matches);
        $numericId = $matches[1] ?? $orderGid;
        
        Log::info("Shopify order created: #{$response['orderCreate']['order']['name']} (ID: {$numericId})");
        
        return $numericId;
    }
}