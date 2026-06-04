<?php

namespace App\Services\Shopify;

use Illuminate\Support\Facades\Log;

class ShopifyOrderService
{
    public function __construct(private readonly ShopifyGraphQLService $graphql) {}

    // ── Fragments ────────────────────────────────────────────────────────

    private function orderFragment(): string
    {
        return <<<'GQL'
        fragment OrderFields on Order {
            id
            name
            email
            createdAt
            updatedAt
            cancelledAt
            displayFinancialStatus
            displayFulfillmentStatus
            currencyCode
            note
            tags
            customer {
                id
                email
                firstName
                lastName
            }
            billingAddress {
                firstName lastName
                address1 address2
                city zip
                countryCodeV2
                provinceCode
                phone
            }
            shippingAddress {
                firstName lastName
                address1 address2
                city zip
                countryCodeV2
                provinceCode
                phone
            }
            lineItems(first: 100) {
                edges {
                    node {
                        id
                        title
                        variantTitle
                        quantity
                        originalUnitPriceSet { shopMoney { amount currencyCode } }
                        variant { id sku }
                        taxLines {
                            title
                            ratePercentage
                            priceSet { shopMoney { amount } }
                        }
                    }
                }
            }
            shippingLines(first: 10) {
                edges {
                    node {
                        id
                        title
                        originalPriceSet { shopMoney { amount currencyCode } }
                        carrierIdentifier
                    }
                }
            }
            totalPriceSet { shopMoney { amount currencyCode } }
            subtotalPriceSet { shopMoney { amount currencyCode } }
            totalShippingPriceSet { shopMoney { amount currencyCode } }
            totalTaxSet { shopMoney { amount currencyCode } }
        }
        GQL;
    }

    // ── Public API ───────────────────────────────────────────────────────

    /**
     * Get a single order by numeric ID.
     */
    /**
     * Create an order in Shopify via draftOrderCreate + draftOrderComplete.
     * Converts Odoo order structure to Shopify DraftOrderInput.
     */
    public function create(array $orderData): array
    {
        $input = $this->buildDraftOrderInput($orderData);

        $mutation = <<<'GQL'
        mutation draftOrderCreate($input: DraftOrderInput!) {
            draftOrderCreate(input: $input) {
                draftOrder {
                    id
                    order { id name }
                }
                userErrors { field message }
            }
        }
        GQL;

        $data   = $this->graphql->query($mutation, ['input' => $input]);
        $errors = $this->graphql->extractUserErrors($data, 'draftOrderCreate');

        if (!empty($errors)) {
            throw new \RuntimeException('Shopify draftOrderCreate errors: ' . implode('; ', $errors));
        }

        $draftOrder = $data['draftOrderCreate']['draftOrder'];
        $draftId    = $draftOrder['id'];

        // Complete the draft order immediately to create a real order
        $completeMutation = <<<'GQL'
        mutation draftOrderComplete($id: ID!) {
            draftOrderComplete(id: $id) {
                draftOrder {
                    order { id name }
                }
                userErrors { field message }
            }
        }
        GQL;

        $completeData   = $this->graphql->query($completeMutation, ['id' => $draftId]);
        $completeErrors = $this->graphql->extractUserErrors($completeData, 'draftOrderComplete');

        if (!empty($completeErrors)) {
            throw new \RuntimeException('Shopify draftOrderComplete errors: ' . implode('; ', $completeErrors));
        }

        $order = $completeData['draftOrderComplete']['draftOrder']['order'];
        return ['id' => $this->fromGid($order['id']), 'name' => $order['name']];
    }

    /**
     * Build Shopify DraftOrderInput from Odoo order structure.
     */
    private function buildDraftOrderInput(array $order): array
    {
        $input = [];

        // Note / reference
        if (!empty($order['name'])) {
            $input['note'] = 'ERP Order: ' . $order['name'];
        }
        if (!empty($order['client_order_ref'])) {
            $input['poNumber'] = (string) $order['client_order_ref'];
        }

        // Customer — resolve by email if available
        if (!empty($order['partner_id'])) {
            $email = is_array($order['partner_id']) ? ($order['partner_id'][1] ?? null) : null;
            if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $input['email'] = $email;
            }
        }

        // Line items from enriched order_line data
        $lineItems = [];
        foreach ($order['order_line'] ?? [] as $line) {
            if (!is_array($line)) continue;

            $qty      = (float) ($line['product_uom_qty'] ?? 1);
            $price    = (float) ($line['price_unit'] ?? 0);
            $title    = $line['name'] ?? 'Product';
            $sku      = null;

            // product_id = [id, "name"] from Odoo
            if (is_array($line['product_id'] ?? null)) {
                $sku = $line['product_id'][1] ?? null;
            }

            $item = [
                'title'    => $title,
                'quantity' => (int) max(1, $qty),
                'originalUnitPrice' => number_format($price, 2, '.', ''),
            ];

            if ($sku) {
                $item['sku'] = $sku;
            }

            $lineItems[] = $item;
        }

        if (empty($lineItems)) {
            throw new \RuntimeException('Shopify draftOrderCreate: no line items found in order. Ensure order lines are enriched before calling create().');
        }

        $input['lineItems'] = $lineItems;

        return $input;
    }

    public function get(string $orderId): ?array
    {
        $query = $this->orderFragment() . <<<'GQL'
        query getOrder($id: ID!) {
            order(id: $id) { ...OrderFields }
        }
        GQL;

        try {
            $data = $this->graphql->query($query, [
                'id' => $this->toGid('Order', $orderId),
            ]);
            return $data['order'] ? $this->normalizeOrder($data['order']) : null;
        } catch (\Throwable $e) {
            Log::warning("ShopifyOrderService::get failed for #{$orderId}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * List orders with optional filters.
     */
    public function list(array $params = []): array
    {
        $limit  = $params['limit'] ?? 50;
        $status = $params['status'] ?? 'any';

        // Build query filter string
        $filters = [];
        if ($status !== 'any') {
            $filters[] = "status:{$status}";
        }
        if (!empty($params['updated_at_min'])) {
            // Use updated_at filter — only fetch orders changed since cursor
            $filters[] = "updated_at:>={$params['updated_at_min']}";
        } elseif (!empty($params['created_at_min'])) {
            $filters[] = "created_at:>={$params['created_at_min']}";
        }
        $queryStr = implode(' AND ', $filters);

        $gqlQuery = $this->orderFragment() . <<<GQL
        query listOrders(\$first: Int!, \$query: String) {
            orders(first: \$first, query: \$query, sortKey: CREATED_AT, reverse: true) {
                edges { node { ...OrderFields } }
                pageInfo { hasNextPage endCursor }
            }
        }
        GQL;

        $data   = $this->graphql->query($gqlQuery, [
            'first' => (int) $limit,
            'query' => $queryStr ?: null,
        ]);

        $orders = array_map(
            fn($edge) => $this->normalizeOrder($edge['node']),
            $data['orders']['edges'] ?? []
        );

        return $orders;  // Return orders directly, not wrapped
    }

    /**
     * Get orders created after a given timestamp.
     */
    public function getCreatedAfter(string $isoDatetime, int $limit = 250): array
    {
        $allOrders = [];
        $cursor    = null;
        $fetched   = 0;
        $batchSize = min($limit, 50);

        $gqlQuery = $this->orderFragment() . <<<'GQL'
        query ordersAfter($first: Int!, $after: String, $query: String) {
            orders(first: $first, after: $after, query: $query, sortKey: CREATED_AT) {
                edges { node { ...OrderFields } cursor }
                pageInfo { hasNextPage endCursor }
            }
        }
        GQL;

        do {
            $data  = $this->graphql->query($gqlQuery, [
                'first' => $batchSize,
                'after' => $cursor,
                'query' => "created_at:>={$isoDatetime} status:any",
            ]);

            $edges = $data['orders']['edges'] ?? [];

            foreach ($edges as $edge) {
                $allOrders[] = $this->normalizeOrder($edge['node']);
                $cursor      = $edge['cursor'];
                $fetched++;
                if ($fetched >= $limit) break 2;
            }

            $hasMore = $data['orders']['pageInfo']['hasNextPage'] ?? false;

        } while ($hasMore && $fetched < $limit);

        return $allOrders;
    }

    /**
     * Cancel an order.
     */
    public function cancel(string $orderId, string $reason = 'OTHER'): array
    {
        $mutation = <<<'GQL'
        mutation orderCancel($orderId: ID!, $reason: OrderCancelReason!, $notifyCustomer: Boolean!) {
            orderCancel(orderId: $orderId, reason: $reason, notifyCustomer: $notifyCustomer) {
                job { id done }
                userErrors { field message }
            }
        }
        GQL;

        $data   = $this->graphql->query($mutation, [
            'orderId'        => $this->toGid('Order', $orderId),
            'reason'         => strtoupper($reason),
            'notifyCustomer' => false,
        ]);

        $errors = $this->graphql->extractUserErrors($data, 'orderCancel');
        if (!empty($errors)) {
            throw new \RuntimeException('Shopify orderCancel errors: ' . implode('; ', $errors));
        }

        return $data['orderCancel'] ?? [];
    }

    // ── Normalizer ───────────────────────────────────────────────────────

    /**
     * Normalize GraphQL order to REST-compatible shape.
     * Keeps OrderSyncService, FulfillmentSyncService unchanged.
     */
    private function normalizeOrder(array $o): array
    {
        $lineItems = array_map(function ($edge) {
            $n = $edge['node'];
            return [
                'id'            => $this->fromGid($n['id']),
                'title'         => $n['title'],
                'variant_title' => $n['variantTitle'] ?? '',
                'quantity'      => $n['quantity'],
                'price'         => $n['originalUnitPriceSet']['shopMoney']['amount'] ?? '0.00',
                'variant_id'    => isset($n['variant']['id']) ? $this->fromGid($n['variant']['id']) : null,
                'sku'           => $n['variant']['sku'] ?? '',
                'tax_lines'     => array_map(fn($t) => [
                    'title' => $t['title'],
                    'rate'  => $t['ratePercentage'],
                    'price' => $t['priceSet']['shopMoney']['amount'] ?? '0',
                ], $n['taxLines'] ?? []),
            ];
        }, $o['lineItems']['edges'] ?? []);

        $shippingLines = array_map(function ($edge) {
            $n = $edge['node'];
            return [
                'id'    => $this->fromGid($n['id']),
                'title' => $n['title'],
                'price' => $n['originalPriceSet']['shopMoney']['amount'] ?? '0.00',
            ];
        }, $o['shippingLines']['edges'] ?? []);

        $billing  = $o['billingAddress']  ?? [];
        $shipping = $o['shippingAddress'] ?? [];

        return [
            'id'               => $this->fromGid($o['id']),
            'name'             => $o['name'],
            'email'            => $o['email'] ?? $o['customer']['email'] ?? '',
            'created_at'       => $o['createdAt'],
            'updated_at'       => $o['updatedAt'],
            'cancelled_at'     => $o['cancelledAt'],
            'financial_status' => strtolower($o['displayFinancialStatus'] ?? ''),
            'fulfillment_status' => strtolower($o['displayFulfillmentStatus'] ?? ''),
            'currency'         => $o['currencyCode'],
            'note'             => $o['note'] ?? '',
            'tags'             => $o['tags'] ?? '',
            'total_price'      => $o['totalPriceSet']['shopMoney']['amount'] ?? '0.00',
            'subtotal_price'   => $o['subtotalPriceSet']['shopMoney']['amount'] ?? '0.00',
            'total_tax'        => $o['totalTaxSet']['shopMoney']['amount'] ?? '0.00',
            'line_items'       => $lineItems,
            'shipping_lines'   => $shippingLines,
            'billing_address'  => $this->normalizeAddress($billing),
            'shipping_address' => $this->normalizeAddress($shipping),
            'customer'         => $o['customer'] ? [
                'id'         => $this->fromGid($o['customer']['id']),
                'email'      => $o['customer']['email'] ?? '',
                'first_name' => $o['customer']['firstName'] ?? '',
                'last_name'  => $o['customer']['lastName']  ?? '',
            ] : null,
        ];
    }

    private function normalizeAddress(array $addr): array
    {
        if (empty($addr)) return [];
        return [
            'first_name'    => $addr['firstName']    ?? '',
            'last_name'     => $addr['lastName']     ?? '',
            'address1'      => $addr['address1']     ?? '',
            'address2'      => $addr['address2']     ?? '',
            'city'          => $addr['city']         ?? '',
            'zip'           => $addr['zip']          ?? '',
            'country_code'  => $addr['countryCodeV2'] ?? '',
            'province_code' => $addr['provinceCode'] ?? '',
            'phone'         => $addr['phone']        ?? '',
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