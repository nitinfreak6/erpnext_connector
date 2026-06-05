<?php

namespace App\Services\Ecom;

interface EcomInterface
{
    public function driverName(): string;

    // ── Products ──────────────────────────────────────────────────────────

    public function getProducts(array $filters = []): array;
    public function getProduct(string|int $id): array;
    public function createProduct(array $payload): array;
    public function updateProduct(string|int $id, array $payload): array;
    public function deleteProduct(string|int $id): void;
    public function getVariants(array $productIds): array;

    /**
     * Sync a product from ERP data to this ecom platform.
     * Each adapter builds its own payload and calls its own API.
     * Returns the ecom platform's product ID.
     */
    public function syncProduct(array $erpTemplate, array $variants, array $attributeValues): string;

    // ── Orders ────────────────────────────────────────────────────────────

    public function getOrders(array $filters = []): array;
    public function getOrder(string|int $id): array;
    public function createOrder(array $orderData): array;
    public function updateOrder(string|int $id, array $updates): void;
    public function cancelOrder(string|int $id, ?string $reason = null): void;

    // ── Inventory ─────────────────────────────────────────────────────────

    public function updateInventory(string|int $variantId, int $quantity, ?string $locationId = null): void;
    public function getInventoryLevels(array $inventoryItemIds, string $locationId): array;

    // ── Customers ─────────────────────────────────────────────────────────

    public function getCustomers(array $filters = []): array;
    public function createCustomer(array $customerData): array;
    public function updateCustomer(string|int $id, array $customerData): array;

    // ── Webhooks ──────────────────────────────────────────────────────────

    public function registerWebhooks(array $topics): array;
    public function unregisterAllWebhooks(): void;
    public function listWebhooks(): array;
    public function verifyWebhook(string $payload, string $signature): bool;

    // ── Fulfillment ───────────────────────────────────────────────────────

    public function createFulfillment(string|int $orderId, array $fulfillmentData): array;
    public function updateFulfillment(string|int $fulfillmentId, array $updates): void;
}