<?php

namespace App\Services\Shopify;

use Illuminate\Support\Facades\Log;

class ShopifyFulfillmentService
{
    public function __construct(private readonly ShopifyGraphQLService $graphql) {}

    /**
     * Create a fulfillment for an order via GraphQL fulfillmentCreateV2.
     *
     * $orderId         → numeric Shopify order ID
     * $fulfillmentData → REST-style payload from buildPayload()
     */
    public function create(string $orderId, array $fulfillmentData): array
    {
        // Resolve fulfillment order IDs first — Shopify GraphQL requires them
        $fulfillmentOrderIds = $this->getFulfillmentOrderIds($orderId);

        if (empty($fulfillmentOrderIds)) {
            throw new \RuntimeException("No open fulfillment orders found for Shopify order #{$orderId}");
        }

        $input = $this->buildGraphQLInput($orderId, $fulfillmentData, $fulfillmentOrderIds);

        $mutation = <<<'GQL'
        mutation fulfillmentCreateV2($fulfillment: FulfillmentV2Input!) {
            fulfillmentCreateV2(fulfillment: $fulfillment) {
                fulfillment {
                    id
                    status
                    trackingInfo { number company url }
                    fulfillmentLineItems(first: 50) {
                        edges {
                            node {
                                id
                                quantity
                                lineItem { id title }
                            }
                        }
                    }
                }
                userErrors { field message }
            }
        }
        GQL;

        $data   = $this->graphql->query($mutation, ['fulfillment' => $input]);
        $errors = $this->graphql->extractUserErrors($data, 'fulfillmentCreateV2');

        if (!empty($errors)) {
            throw new \RuntimeException('Shopify fulfillmentCreateV2 errors: ' . implode('; ', $errors));
        }

        return $this->normalizeFulfillment($data['fulfillmentCreateV2']['fulfillment']);
    }

    /**
     * Get fulfillments for an order.
     */
    public function getForOrder(string $orderId): array
    {
        $query = <<<'GQL'
        query getFulfillments($id: ID!) {
            order(id: $id) {
                fulfillments(first: 20) {
                    id
                    status
                    createdAt
                    trackingInfo { number company url }
                }
            }
        }
        GQL;

        try {
            $data = $this->graphql->query($query, [
                'id' => $this->toGid('Order', $orderId),
            ]);

            return array_map(
                fn($f) => $this->normalizeFulfillment($f),
                $data['order']['fulfillments'] ?? []
            );
        } catch (\Throwable $e) {
            Log::warning("ShopifyFulfillmentService::getForOrder failed: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Build fulfillment payload from Odoo picking data.
     * Returns REST-style array — converted to GraphQL in create().
     */
    public function buildPayload(array $picking, array $moves, array $shopifyLineItems): array
    {
        $lineItems = [];

        foreach ($moves as $move) {
            $productOdooId = is_array($move['product_id'])
                ? $move['product_id'][0]
                : $move['product_id'];

            foreach ($shopifyLineItems as $lineItem) {
                if (isset($lineItem['_odoo_product_id']) && $lineItem['_odoo_product_id'] == $productOdooId) {
                    $lineItems[] = [
                        'id'       => $lineItem['id'],
                        'quantity' => (int) $move['quantity_done'],
                    ];
                    break;
                }
            }
        }

        $payload = [
            'line_items'      => $lineItems,
            'notify_customer' => true,
        ];

        if (!empty($picking['carrier_tracking_ref'])) {
            $payload['tracking_number']  = $picking['carrier_tracking_ref'];
            $payload['tracking_company'] = is_array($picking['carrier_id'])
                ? $picking['carrier_id'][1]
                : '';
        }

        return $payload;
    }

    // ── Private helpers ──────────────────────────────────────────────────

    /**
     * Get open fulfillment order IDs for a Shopify order.
     * GraphQL fulfillmentCreateV2 requires fulfillmentOrderId, not orderId.
     */
    private function getFulfillmentOrderIds(string $orderId): array
    {
        $query = <<<'GQL'
        query getFulfillmentOrders($id: ID!) {
            order(id: $id) {
                fulfillmentOrders(first: 20) {
                    edges {
                        node {
                            id
                            status
                            lineItems(first: 50) {
                                edges {
                                    node {
                                        id
                                        remainingQuantity
                                        lineItem { id }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        GQL;

        $data   = $this->graphql->query($query, [
            'id' => $this->toGid('Order', $orderId),
        ]);

        $ids = [];
        foreach ($data['order']['fulfillmentOrders']['edges'] ?? [] as $edge) {
            $fo = $edge['node'];
            // Only include open/in_progress fulfillment orders
            if (in_array($fo['status'], ['OPEN', 'IN_PROGRESS', 'SCHEDULED'])) {
                $ids[] = [
                    'id'         => $fo['id'],
                    'lineItems'  => $fo['lineItems']['edges'] ?? [],
                ];
            }
        }

        return $ids;
    }

    /**
     * Convert REST-style payload to GraphQL FulfillmentV2Input.
     */
    private function buildGraphQLInput(string $orderId, array $payload, array $fulfillmentOrders): array
    {
        // Map Shopify line item IDs to fulfillment order line item IDs
        $requestedLineItems = [];
        $lineItemIdMap      = [];

        foreach ($fulfillmentOrders as $fo) {
            foreach ($fo['lineItems'] as $edge) {
                $foli = $edge['node'];
                $lineItemId = $this->fromGid($foli['lineItem']['id']);
                $lineItemIdMap[$lineItemId] = $foli['id'];
            }
        }

        foreach ($payload['line_items'] ?? [] as $item) {
            $foLineItemId = $lineItemIdMap[(string)$item['id']] ?? null;
            if ($foLineItemId) {
                $requestedLineItems[] = [
                    'fulfillmentOrderLineItemId' => $foLineItemId,
                    'quantity'                   => $item['quantity'],
                ];
            }
        }

        // Use first fulfillment order ID as primary
        $primaryFoId = $fulfillmentOrders[0]['id'];

        $input = [
            'lineItemsByFulfillmentOrder' => [
                [
                    'fulfillmentOrderId'       => $primaryFoId,
                    'fulfillmentOrderLineItems' => $requestedLineItems ?: null,
                ],
            ],
            'notifyCustomer' => $payload['notify_customer'] ?? true,
        ];

        // Tracking info
        if (!empty($payload['tracking_number'])) {
            $input['trackingInfo'] = [
                'number'  => $payload['tracking_number'],
                'company' => $payload['tracking_company'] ?? '',
            ];
        }

        return $input;
    }

    private function normalizeFulfillment(array $f): array
    {
        return [
            'id'               => isset($f['id']) ? $this->fromGid($f['id']) : null,
            'status'           => strtolower($f['status'] ?? ''),
            'tracking_number'  => $f['trackingInfo'][0]['number']  ?? null,
            'tracking_company' => $f['trackingInfo'][0]['company'] ?? null,
            'tracking_url'     => $f['trackingInfo'][0]['url']     ?? null,
        ];
    }

    // ── GID helpers ──────────────────────────────────────────────────────

    private function toGid(string $type, string $id): string
    {
        if (str_starts_with($id, 'gid://')) return $id;
        return "gid://shopify/{$type}/{$id}";
    }

    private function fromGid(string $gid): string
    {
        return (string) last(explode('/', $gid));
    }
}