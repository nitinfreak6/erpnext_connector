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

    // ── Field discovery ───────────────────────────────────────────────────

    /**
     * Field catalog per entity type. Moved here from the dashboard controllers
     * so the field-config menus stay driver-neutral — a new ecom adapter just
     * implements this method and its fields appear in the mapping UI.
     */
    public function getAvailableFields(string $entityType): array
    {
        return match ($entityType) {
            'product'     => array_merge($this->templateFields(), $this->variantFields()),
            'sales_order' => $this->orderFields(),
            'customer'    => $this->customerFields(),
            'dispatch'    => $this->fulfillmentFields(),
            default       => [],
        };
    }

    /** @return array<int,array{key:string,label:string,scope:string}> */
    private function templateFields(): array
    {
        $fields = [
            'title'           => 'Title',
            'descriptionHtml' => 'Description (HTML)',
            'vendor'          => 'Vendor',
            'productType'     => 'Product Type',
            'tags'            => 'Tags',
            'status'          => 'Status',
            'handle'          => 'Handle',
            'images'          => 'Images',
        ];
        return $this->shape($fields, fn($key) => 'template');
    }

    /** @return array<int,array{key:string,label:string,scope:string}> */
    private function variantFields(): array
    {
        $fields = [
            'sku'                                    => 'SKU',
            'price'                                  => 'Price',
            'compareAtPrice'                         => 'Compare At Price',
            'taxable'                                => 'Taxable',
            'inventoryPolicy'                        => 'Inventory Policy',
            'inventoryItem.sku'                      => 'Inventory SKU',
            'inventoryItem.barcode'                  => 'Barcode',
            'inventoryItem.tracked'                  => 'Inventory Tracked',
            'inventoryItem.requiresShipping'         => 'Requires Shipping',
            'inventoryItem.measurement.weight.value' => 'Weight',
            'inventoryItem.measurement.weight.unit'  => 'Weight Unit',
            'option1'                                => 'Option 1',
            'option2'                                => 'Option 2',
            'option3'                                => 'Option 3',
        ];
        return $this->shape($fields, fn($key) => 'variant');
    }

    /** @return array<int,array{key:string,label:string,scope:string}> */
    private function orderFields(): array
    {
        $fields = [
            'id'                                        => 'Order ID',
            'name'                                      => 'Order Name/Ref (#1001)',
            'email'                                     => 'Customer Email',
            'phone'                                     => 'Customer Phone',
            'note'                                      => 'Note',
            'tags'                                      => 'Tags',
            'total_price'                               => 'Total Price',
            'subtotal_price'                            => 'Subtotal Price',
            'total_tax'                                 => 'Total Tax',
            'total_discounts'                           => 'Total Discounts',
            'total_weight'                              => 'Total Weight',
            'currency'                                  => 'Currency',
            'presentment_currency'                      => 'Presentment Currency',
            'financial_status'                          => 'Financial Status',
            'fulfillment_status'                        => 'Fulfillment Status',
            'created_at'                                => 'Created At',
            'processed_at'                              => 'Processed At',
            'gateway'                                   => 'Payment Gateway',
            'payment_gateway_names'                     => 'Payment Gateway Names',
            'source_name'                               => 'Source Name',
            'referring_site'                            => 'Referring Site',
            'landing_site'                              => 'Landing Site',
            'cancel_reason'                             => 'Cancel Reason',
            'cancelled_at'                              => 'Cancelled At',
            'closed_at'                                 => 'Closed At',
            'number'                                    => 'Order Number',
            'order_number'                              => 'Order Number (full)',
            'token'                                     => 'Token',
            'cart_token'                                => 'Cart Token',
            'checkout_token'                            => 'Checkout Token',
            'test'                                      => 'Is Test Order',
            'confirmed'                                 => 'Confirmed',
            'customer.id'                               => 'Customer ID',
            'customer.email'                            => 'Customer Email (nested)',
            'customer.first_name'                       => 'Customer First Name',
            'customer.last_name'                        => 'Customer Last Name',
            'customer.phone'                            => 'Customer Phone (nested)',
            'customer.tags'                             => 'Customer Tags',
            'customer.note'                             => 'Customer Note',
            'customer.orders_count'                     => 'Customer Orders Count',
            'customer.total_spent'                      => 'Customer Total Spent',
            'billing_address.first_name'                => 'Billing First Name',
            'billing_address.last_name'                 => 'Billing Last Name',
            'billing_address.company'                   => 'Billing Company',
            'billing_address.address1'                  => 'Billing Address 1',
            'billing_address.address2'                  => 'Billing Address 2',
            'billing_address.city'                      => 'Billing City',
            'billing_address.zip'                       => 'Billing Zip',
            'billing_address.province'                  => 'Billing Province',
            'billing_address.province_code'             => 'Billing Province Code',
            'billing_address.country'                   => 'Billing Country',
            'billing_address.country_code'              => 'Billing Country Code',
            'billing_address.phone'                     => 'Billing Phone',
            'shipping_address.first_name'               => 'Shipping First Name',
            'shipping_address.last_name'                => 'Shipping Last Name',
            'shipping_address.company'                  => 'Shipping Company',
            'shipping_address.address1'                 => 'Shipping Address 1',
            'shipping_address.address2'                 => 'Shipping Address 2',
            'shipping_address.city'                     => 'Shipping City',
            'shipping_address.zip'                      => 'Shipping Zip',
            'shipping_address.province'                 => 'Shipping Province',
            'shipping_address.province_code'            => 'Shipping Province Code',
            'shipping_address.country'                  => 'Shipping Country',
            'shipping_address.country_code'             => 'Shipping Country Code',
            'shipping_address.phone'                    => 'Shipping Phone',
            'line_items'                                => 'Line Items (array — use as line_container)',
            'line_items.id'                             => 'Line Item ID',
            'line_items.title'                          => 'Line Item Title',
            'line_items.name'                           => 'Line Item Name (title + variant)',
            'line_items.sku'                            => 'Line Item SKU',
            'line_items.variant_id'                     => 'Line Item Variant ID',
            'line_items.product_id'                     => 'Line Item Product ID',
            'line_items.quantity'                       => 'Line Item Quantity',
            'line_items.price'                          => 'Line Item Price',
            'line_items.total_discount'                 => 'Line Item Total Discount',
            'line_items.grams'                          => 'Line Item Grams',
            'line_items.requires_shipping'              => 'Line Item Requires Shipping',
            'line_items.taxable'                        => 'Line Item Taxable',
            'line_items.fulfillment_status'             => 'Line Item Fulfillment Status',
            'line_items.vendor'                         => 'Line Item Vendor',
            'line_items.variant_title'                  => 'Line Item Variant Title',
            'line_items.price_set.presentment_money.amount'  => 'Line Item Presentment Price',
            'line_items.price_set.shop_money.amount'         => 'Line Item Shop Price',
            'tax_lines'                                 => 'Tax Lines',
            'tax_lines.title'                           => 'Tax Title',
            'tax_lines.rate'                            => 'Tax Rate',
            'tax_lines.price'                           => 'Tax Price',
            'shipping_lines'                            => 'Shipping Lines',
            'shipping_lines.title'                      => 'Shipping Title',
            'shipping_lines.price'                      => 'Shipping Price',
            'shipping_lines.code'                       => 'Shipping Code',
            'discount_codes'                            => 'Discount Codes',
            'discount_codes.code'                       => 'Discount Code',
            'discount_codes.amount'                     => 'Discount Amount',
            'discount_codes.type'                       => 'Discount Type',
        ];
        return $this->shape($fields, fn($key) => str_starts_with($key, 'line_items.') ? 'line' : 'header');
    }

    /** @return array<int,array{key:string,label:string,scope:string}> */
    private function customerFields(): array
    {
        $fields = [
            'id'                  => 'Customer ID',
            'email'               => 'Email',
            'first_name'          => 'First Name',
            'last_name'           => 'Last Name',
            'phone'               => 'Phone',
            'note'                => 'Note',
            'tags'                => 'Tags',
            'verified_email'      => 'Verified Email',
            'accepts_marketing'   => 'Accepts Marketing',
            'orders_count'        => 'Orders Count',
            'total_spent'         => 'Total Spent',
            'state'               => 'State (enabled/disabled)',
            'tax_exempt'          => 'Tax Exempt',
            'currency'            => 'Currency',
            'created_at'          => 'Created At',
            'updated_at'          => 'Updated At',
            'default_address.address1'      => 'Address 1',
            'default_address.address2'      => 'Address 2',
            'default_address.city'          => 'City',
            'default_address.zip'           => 'Zip',
            'default_address.province'      => 'Province',
            'default_address.province_code' => 'Province Code',
            'default_address.country'       => 'Country',
            'default_address.country_code'  => 'Country Code',
            'default_address.company'       => 'Company',
            'default_address.phone'         => 'Address Phone',
        ];
        return $this->shape($fields, fn($key) => 'header');
    }

    /** @return array<int,array{key:string,label:string,scope:string}> */
    private function fulfillmentFields(): array
    {
        $fields = [
            'id'                    => 'Fulfillment ID',
            'order_id'              => 'Order ID',
            'status'                => 'Status',
            'tracking_number'       => 'Tracking Number',
            'tracking_company'      => 'Tracking Company',
            'tracking_url'          => 'Tracking URL',
            'tracking_numbers'      => 'Tracking Numbers',
            'tracking_urls'         => 'Tracking URLs',
            'created_at'            => 'Created At',
            'updated_at'            => 'Updated At',
            'shipment_status'       => 'Shipment Status',
            'service'               => 'Service',
            'line_items.id'         => 'Line Item ID',
            'line_items.quantity'   => 'Line Item Quantity',
            'line_items.sku'        => 'Line Item SKU',
        ];
        return $this->shape($fields, fn($key) => str_starts_with($key, 'line_items.') ? 'line' : 'header');
    }

    /**
     * Turn a key => label map into the canonical descriptor list, deriving the
     * scope per key via $scopeFn.
     *
     * @param  array<string,string> $fields
     * @return array<int,array{key:string,label:string,scope:string}>
     */
    private function shape(array $fields, callable $scopeFn): array
    {
        return array_map(
            fn($key, $label) => ['key' => $key, 'label' => $label, 'scope' => $scopeFn($key)],
            array_keys($fields),
            array_values($fields)
        );
    }
}