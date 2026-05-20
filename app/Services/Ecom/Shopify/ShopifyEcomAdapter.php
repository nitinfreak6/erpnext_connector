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

/**
 * Shopify E-commerce Adapter
 * 
 * Implements EcomInterface for Shopify platform.
 * Wraps existing Shopify services into the unified interface.
 */
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
        $this->shopify = $shopify;
        $this->products = $products;
        $this->orders = $orders;
        $this->inventory = $inventory;
        $this->customers = $customers;
        $this->fulfillment = $fulfillment;
    }

    public function driverName(): string
    {
        return 'shopify';
    }

    // ══════════════════════════════════════════════════════════════════════
    // PRODUCTS
    // ══════════════════════════════════════════════════════════════════════

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

    public function getVariants(array $productIds): array
    {
        // Shopify variants are nested in products
        $variants = [];
        foreach ($productIds as $productId) {
            $product = $this->getProduct($productId);
            if (isset($product['variants'])) {
                $variants = array_merge($variants, $product['variants']);
            }
        }
        return $variants;
    }

    // ══════════════════════════════════════════════════════════════════════
    // ORDERS
    // ══════════════════════════════════════════════════════════════════════

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

    // ══════════════════════════════════════════════════════════════════════
    // INVENTORY
    // ══════════════════════════════════════════════════════════════════════

    public function updateInventory(string|int $variantId, int $quantity, ?string $locationId = null): void
    {
        $this->inventory->update($variantId, $quantity, $locationId);
    }

    public function getInventoryLevels(array $variantIds): array
    {
        return $this->inventory->getLevels($variantIds);
    }

    // ══════════════════════════════════════════════════════════════════════
    // CUSTOMERS
    // ══════════════════════════════════════════════════════════════════════

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

    // ══════════════════════════════════════════════════════════════════════
    // WEBHOOKS
    // ══════════════════════════════════════════════════════════════════════

    public function registerWebhooks(array $topics): array
    {
        $registered = [];
        $baseUrl = config('app.url');

        $topicToEndpoint = [
            'orders/create'      => '/webhook/shopify/orders/create',
            'orders/updated'     => '/webhook/shopify/orders/updated',
            'products/create'    => '/webhook/shopify/products/create',
            'products/update'    => '/webhook/shopify/products/update',
            'inventory_levels/update' => '/webhook/shopify/inventory/update',
            'customers/create'   => '/webhook/shopify/customers/create',
            'customers/update'   => '/webhook/shopify/customers/update',
        ];

        foreach ($topics as $topic) {
            if (!isset($topicToEndpoint[$topic])) {
                Log::warning("Unknown webhook topic: {$topic}");
                continue;
            }

            try {
                $webhook = $this->shopify->createWebhook(
                    $topic,
                    $baseUrl . $topicToEndpoint[$topic]
                );
                $registered[] = $webhook;
                Log::info("Registered Shopify webhook: {$topic}");
            } catch (\Throwable $e) {
                Log::error("Failed to register Shopify webhook: {$topic}", [
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $registered;
    }

    public function unregisterAllWebhooks(): void
    {
        $webhooks = $this->listWebhooks();
        foreach ($webhooks as $webhook) {
            try {
                $this->shopify->deleteWebhook($webhook['id']);
                Log::info("Unregistered Shopify webhook: {$webhook['topic']}");
            } catch (\Throwable $e) {
                Log::error("Failed to unregister webhook: {$webhook['id']}", [
                    'error' => $e->getMessage()
                ]);
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

    // ══════════════════════════════════════════════════════════════════════
    // FULFILLMENT
    // ══════════════════════════════════════════════════════════════════════

    public function createFulfillment(string|int $orderId, array $fulfillmentData): array
    {
        return $this->fulfillment->create($orderId, $fulfillmentData);
    }

    public function updateFulfillment(string|int $fulfillmentId, array $updates): void
    {
        $this->fulfillment->update($fulfillmentId, $updates);
    }
}