<?php

namespace App\Services\Ecom;

/**
 * E-commerce Platform Interface
 * 
 * Abstraction layer for e-commerce platforms (Shopify, WooCommerce, Magento, etc.)
 * This interface ensures all ecommerce adapters provide the same methods,
 * allowing the sync engine to work with any platform without code changes.
 * 
 * Implementation pattern matches ErpInterface.
 */
interface EcomInterface
{
    /**
     * Return the driver name: 'shopify' | 'woocommerce' | 'magento'
     */
    public function driverName(): string;

    // ══════════════════════════════════════════════════════════════════════
    // PRODUCTS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Fetch products from the e-commerce platform.
     * 
     * @param array $filters Filters like ['updated_since' => '2024-01-01', 'status' => 'active']
     * @return array Array of product arrays with normalized structure
     */
    public function getProducts(array $filters = []): array;

    /**
     * Fetch a single product by ID.
     * 
     * @param string|int $id The ecommerce platform's product ID
     * @return array Normalized product array
     */
    public function getProduct(string|int $id): array;

    /**
     * Create a product in the e-commerce platform.
     * 
     * @param array $payload Normalized product data
     * @return array Created product with ID
     */
    public function createProduct(array $payload): array;

    /**
     * Update an existing product.
     * 
     * @param string|int $id The ecommerce platform's product ID
     * @param array $payload Normalized product data
     * @return array Updated product data
     */
    public function updateProduct(string|int $id, array $payload): array;

    /**
     * Delete a product (archive/unpublish).
     * 
     * @param string|int $id The ecommerce platform's product ID
     */
    public function deleteProduct(string|int $id): void;

    /**
     * Get variants for specific products.
     * 
     * @param array $productIds Array of product IDs
     * @return array Array of variant arrays
     */
    public function getVariants(array $productIds): array;

    // ══════════════════════════════════════════════════════════════════════
    // ORDERS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Fetch orders from the e-commerce platform.
     * 
     * @param array $filters Filters like ['created_since' => '2024-01-01', 'status' => 'pending']
     * @return array Array of order arrays with normalized structure
     */
    public function getOrders(array $filters = []): array;

    /**
     * Fetch a single order by ID.
     * 
     * @param string|int $id The ecommerce platform's order ID
     * @return array Normalized order array
     */
    public function getOrder(string|int $id): array;

    /**
     * Create an order in the e-commerce platform.
     * 
     * @param array $orderData Normalized order data
     * @return array Created order with ID
     */
    public function createOrder(array $orderData): array;

    /**
     * Update order status/tracking.
     * 
     * @param string|int $id The ecommerce platform's order ID
     * @param array $updates Updates like ['status' => 'fulfilled', 'tracking' => '...']
     */
    public function updateOrder(string|int $id, array $updates): void;

    /**
     * Cancel an order.
     * 
     * @param string|int $id The ecommerce platform's order ID
     * @param string|null $reason Cancellation reason
     */
    public function cancelOrder(string|int $id, ?string $reason = null): void;

    // ══════════════════════════════════════════════════════════════════════
    // INVENTORY
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Update inventory quantity for a variant/SKU.
     * 
     * @param string|int $variantId The variant/inventory item ID
     * @param int $quantity New quantity
     * @param string|null $locationId Optional: warehouse/location ID
     */
    public function updateInventory(string|int $variantId, int $quantity, ?string $locationId = null): void;

    /**
     * Get current inventory levels.
     * 
     * @param array $variantIds Array of variant IDs
     * @return array Array of ['variant_id' => quantity]
     */
    public function getInventoryLevels(array $variantIds): array;

    // ══════════════════════════════════════════════════════════════════════
    // CUSTOMERS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Fetch customers from the e-commerce platform.
     * 
     * @param array $filters Filters like ['updated_since' => '2024-01-01']
     * @return array Array of customer arrays
     */
    public function getCustomers(array $filters = []): array;

    /**
     * Create a customer.
     * 
     * @param array $customerData Normalized customer data
     * @return array Created customer with ID
     */
    public function createCustomer(array $customerData): array;

    /**
     * Update a customer.
     * 
     * @param string|int $id The customer ID
     * @param array $customerData Updated customer data
     * @return array Updated customer
     */
    public function updateCustomer(string|int $id, array $customerData): array;

    // ══════════════════════════════════════════════════════════════════════
    // WEBHOOKS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Register webhooks for specific topics.
     * 
     * Topics: 'orders/create', 'orders/update', 'products/create', 'products/update',
     *         'inventory/update', 'customers/create'
     * 
     * @param array $topics Array of webhook topics to register
     * @return array Registered webhook details
     */
    public function registerWebhooks(array $topics): array;

    /**
     * Unregister all webhooks for this integration.
     */
    public function unregisterAllWebhooks(): void;

    /**
     * List currently registered webhooks.
     * 
     * @return array Array of webhook configurations
     */
    public function listWebhooks(): array;

    /**
     * Verify webhook signature/authenticity.
     * 
     * @param string $payload Raw webhook payload
     * @param string $signature Signature header from webhook request
     * @return bool True if authentic
     */
    public function verifyWebhook(string $payload, string $signature): bool;

    // ══════════════════════════════════════════════════════════════════════
    // FULFILLMENT
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Create a fulfillment for an order.
     * 
     * @param string|int $orderId The order ID
     * @param array $fulfillmentData Tracking number, line items, etc.
     * @return array Created fulfillment
     */
    public function createFulfillment(string|int $orderId, array $fulfillmentData): array;

    /**
     * Update fulfillment tracking.
     * 
     * @param string|int $fulfillmentId The fulfillment ID
     * @param array $updates Updates like ['tracking_number' => '...']
     */
    public function updateFulfillment(string|int $fulfillmentId, array $updates): void;
}