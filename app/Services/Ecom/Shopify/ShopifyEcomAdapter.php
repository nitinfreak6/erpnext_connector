<?php

namespace App\Services\Ecom\Shopify;

use App\Exceptions\ShopifyApiException;
use App\Services\Ecom\EcomInterface;
use App\Services\Shopify\ShopifyService;
use App\Services\Shopify\ShopifyProductService;
use App\Services\Shopify\ShopifyOrderService;
use App\Services\Shopify\ShopifyInventoryService;
use App\Services\Shopify\ShopifyCustomerService;
use App\Services\Shopify\ShopifyFulfillmentService;
use Illuminate\Support\Facades\Log;

class ShopifyEcomAdapter implements EcomInterface
{
    private ShopifyService $shopify;
    private ShopifyProductService $products;
    private ShopifyOrderService $orders;
    private ShopifyInventoryService $inventory;
    private ShopifyCustomerService $customers;
    private ShopifyFulfillmentService $fulfillment;

    public function __construct(
        ShopifyService $shopify,
        ShopifyProductService $products,
        ShopifyOrderService $orders,
        ShopifyInventoryService $inventory,
        ShopifyCustomerService $customers,
        ShopifyFulfillmentService $fulfillment
    ) {
        $this->shopify     = $shopify;
        $this->products    = $products;
        $this->orders      = $orders;
        $this->inventory   = $inventory;
        $this->customers   = $customers;
        $this->fulfillment = $fulfillment;
    }

    public function driverName(): string
    {
        return 'shopify';
    }

    // ── Products ──────────────────────────────────────────────────────────

    public function getProducts(array $filters = []): array
    {
        return $this->products->list($filters);
    }

    public function getProduct(string|int $id): array
    {
        return $this->products->get($id);
    }

    public function createProduct(array $payload): array
    {
        return $this->products->create($payload);
    }

    public function updateProduct(string|int $id, array $payload): array
    {
        return $this->products->update($id, $payload);
    }

    public function deleteProduct(string|int $id): void
    {
        $this->products->delete($id);
    }

    /**
     * Sync ERP product → Shopify.
     * Builds payload from field configs, creates or updates via GraphQL.
     * This is the only method PushProductToEcomJob calls — all Shopify
     * specifics stay inside this adapter.
     */
    public function syncProduct(array $erpTemplate, array $variants, array $attributeValues): string
    {
        $erpId = (string) $erpTemplate['id'];

        $payload = $this->products->buildPayload($erpTemplate, $variants, $attributeValues);

        if (empty($payload)) {
            throw new \RuntimeException(
                "ShopifyEcomAdapter: empty payload for ERP #{$erpId} — add field mappings in Product Field Config."
            );
        }

        $mapping = \App\Models\SyncMapping::where('entity_type', 'product')
            ->where('erp_id', $erpId)
            ->first();

        if ($mapping && $mapping->ecom_id) {
            $this->products->update($mapping->ecom_id, $payload);
            $shopifyId = $mapping->ecom_id;
        } else {
            $result    = $this->products->create($payload);
            $shopifyId = (string) ($result['id'] ?? $result['product']['id'] ?? '');

            if ($shopifyId) {
                \App\Models\SyncMapping::updateOrCreate(
                    ['entity_type' => 'product', 'erp_id' => $erpId],
                    [
                        'ecom_id'             => $shopifyId,
                        'ecom_driver'         => 'shopify',
                        'erp_driver'          => app(\App\Services\SettingsService::class)->erpDriver(),
                        'last_sync_direction' => 'erp_to_ecom',
                        'last_synced_at'      => now(),
                    ]
                );
            }
        }

        return $shopifyId;
    }

    public function getVariants(array $productIds): array
    {
        $variants = [];
        foreach ($productIds as $productId) {
            $product = $this->getProduct($productId);
            if (isset($product['variants'])) {
                $variants = array_merge($variants, $product['variants']);
            }
        }
        return $variants;
    }

    // ── Orders ────────────────────────────────────────────────────────────

    public function getOrders(array $filters = []): array
    {
        return $this->orders->list($filters);
    }

    public function getOrder(string|int $id): array
    {
        return $this->orders->get($id);
    }

    public function createOrder(array $orderData): array
    {
        return $this->orders->create($orderData);
    }

    public function updateOrder(string|int $id, array $updates): void
    {
        $this->orders->update($id, $updates);
    }

    public function cancelOrder(string|int $id, ?string $reason = null): void
    {
        $this->orders->cancel($id, $reason);
    }

    // ── Inventory ─────────────────────────────────────────────────────────

    public function updateInventory(string|int $variantId, int $quantity, ?string $locationId = null): void
    {
        $this->inventory->update($variantId, $quantity, $locationId);
    }

    public function getInventoryLevels(array $inventoryItemIds, string $locationId): array
    {
        return $this->inventory->getLevels($inventoryItemIds, $locationId);
    }

    // ── Customers ─────────────────────────────────────────────────────────

    public function getCustomers(array $filters = []): array
    {
        $result = $this->customers->list($filters);
        return $result['customers'] ?? [];
    }

    public function createCustomer(array $customerData): array
    {
        return $this->customers->create($customerData);
    }

    public function updateCustomer(string|int $id, array $customerData): array
    {
        return $this->customers->update($id, $customerData);
    }

    // ── Webhooks ──────────────────────────────────────────────────────────

    public function registerWebhooks(array $topics): array
    {
        $registered = [];
        $baseUrl    = config('app.url');

        $topicToEndpoint = [
            'orders/create'           => '/webhook/shopify/orders/create',
            'orders/updated'          => '/webhook/shopify/orders/updated',
            'products/create'         => '/webhook/shopify/products/create',
            'products/update'         => '/webhook/shopify/products/update',
            'inventory_levels/update' => '/webhook/shopify/inventory/update',
            'customers/create'        => '/webhook/shopify/customers/create',
            'customers/update'        => '/webhook/shopify/customers/update',
        ];

        foreach ($topics as $topic) {
            if (!isset($topicToEndpoint[$topic])) {
                Log::warning("ShopifyEcomAdapter: unknown webhook topic: {$topic}");
                continue;
            }

            try {
                $webhook      = $this->shopify->createWebhook($topic, $baseUrl . $topicToEndpoint[$topic]);
                $registered[] = $webhook;
                Log::info("ShopifyEcomAdapter: registered webhook: {$topic}");
            } catch (\Throwable $e) {
                Log::error("ShopifyEcomAdapter: failed to register webhook: {$topic} — " . $e->getMessage());
            }
        }

        return $registered;
    }

    public function unregisterAllWebhooks(): void
    {
        foreach ($this->listWebhooks() as $webhook) {
            try {
                $this->shopify->deleteWebhook($webhook['id']);
            } catch (\Throwable $e) {
                Log::error("ShopifyEcomAdapter: failed to unregister webhook #{$webhook['id']} — " . $e->getMessage());
            }
        }
    }

    public function listWebhooks(): array
    {
        return $this->shopify->listWebhooks();
    }

    public function verifyWebhook(string $payload, string $signature): bool
    {
        return $this->shopify->verifyWebhook($payload, $signature);
    }

    // ── Fulfillment ───────────────────────────────────────────────────────

    public function createFulfillment(string|int $orderId, array $fulfillmentData): array
    {
        return $this->fulfillment->create($orderId, $fulfillmentData);
    }

    public function updateFulfillment(string|int $fulfillmentId, array $updates): void
    {
        $this->fulfillment->update($fulfillmentId, $updates);
    }
}