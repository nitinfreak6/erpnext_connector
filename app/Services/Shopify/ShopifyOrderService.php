<?php

namespace App\Services\Shopify;

use App\Services\FieldMappingService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Log;

class ShopifyOrderService
{
    /** Shopify DraftOrderLineItemInput aliases — API glue only, not ERP field names. */
    private const DRAFT_LINE_FIELD_ALIASES = [
        'price'               => 'originalUnitPrice',
        'original_unit_price' => 'originalUnitPrice',
        'name'                => 'title',
    ];

    public function __construct(
        private readonly ShopifyGraphQLService $graphql,
        private readonly FieldMappingService $fieldMapping,
        private readonly SettingsService $settings,
    ) {}

    private function orderFragment(): string
    {
        return $this->fieldMapping->buildShopifyGraphqlFragment(
            'sales_order',
            $this->settings->ecomDriver(),
            $this->settings->erpDriver(),
            'Order'
        );
    }

    // ── Public API ───────────────────────────────────────────────────────

    /**
     * Get a single order by numeric ID.
     */
    /**
     * Create an order in Shopify via draftOrderCreate + draftOrderComplete.
     * Expects a field-config mapped payload (DraftOrderInput shape), not raw Odoo data.
     */
    public function create(array $orderData): array
    {
        if ($this->looksLikeRawOdooOrder($orderData)) {
            throw new \RuntimeException(
                'Sales order push rejected: received raw Odoo order data. '
                . 'Add active erp→ecom sales order field configs with direction erp_to_ecom in Field Config.'
            );
        }

        $input = $this->normalizeDraftOrderInput($orderData);

        if (empty($input['lineItems'])) {
            throw new \RuntimeException(
                'Shopify draftOrderCreate: no line items in mapped payload. '
                . 'Check sales order line field configs (scope=line) and the lineItems container mapping.'
            );
        }

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

        return [
            'id'         => $this->fromGid($order['id']),
            'name'       => $order['name'],
            'wire_input' => $input,
        ];
    }

    /**
     * Update an existing Shopify order (note, email, tags).
     * Line items cannot be changed on completed orders — those fields are ignored.
     */
    public function update(string|int $orderId, array $orderData): array
    {
        if ($this->looksLikeRawOdooOrder($orderData)) {
            throw new \RuntimeException(
                'Sales order update rejected: received raw Odoo order data. '
                . 'Add active erp→ecom sales order field configs with direction erp_to_ecom in Field Config.'
            );
        }

        $draftInput = $this->normalizeDraftOrderInput($orderData);
        $input      = [
            'id' => $this->toGid('Order', (string) $orderId),
        ];

        foreach (['email', 'note', 'tags'] as $key) {
            if (!empty($draftInput[$key])) {
                $input[$key] = $draftInput[$key];
            }
        }

        if (count($input) === 1) {
            throw new \RuntimeException(
                'Shopify orderUpdate: no updatable fields in mapped payload (note/email). '
                . 'Line item changes on existing Shopify orders are not supported via API.'
            );
        }

        if (!empty($orderData['lineItems']) || !empty($orderData['line_items'])) {
            Log::info('ShopifyOrderService::update: line item changes ignored — Shopify does not allow line edits on completed orders.');
        }

        $mutation = <<<'GQL'
        mutation orderUpdate($input: OrderInput!) {
            orderUpdate(input: $input) {
                order { id name email note }
                userErrors { field message }
            }
        }
        GQL;

        $data   = $this->graphql->query($mutation, ['input' => $input]);
        $errors = $this->graphql->extractUserErrors($data, 'orderUpdate');

        if (!empty($errors)) {
            throw new \RuntimeException('Shopify orderUpdate errors: ' . implode('; ', $errors));
        }

        $order = $data['orderUpdate']['order'] ?? [];

        return [
            'id'         => $this->fromGid($order['id'] ?? $this->toGid('Order', (string) $orderId)),
            'name'       => $order['name'] ?? null,
            'email'      => $order['email'] ?? null,
            'note'       => $order['note'] ?? null,
            'wire_input' => $input,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function normalizeDraftOrderInput(array $payload): array
    {
        $input = [];

        foreach (['email', 'note', 'poNumber', 'phone'] as $key) {
            $value = $this->pickPayloadScalar($payload, $key);

            if ($value !== null) {
                $input[$key] = $value;
            }
        }

        if (!empty($input['email'])) {
            $email = (string) $input['email'];
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)
                && preg_match('/[\w.%+-]+@[\w.-]+\.[A-Za-z]{2,}/', $email, $matches)) {
                $email = $matches[0];
            }

            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $input['email'] = $email;
            } else {
                unset($input['email']);
            }
        }

        $rawLines = null;
        foreach (['lineItems', 'line_items'] as $key) {
            if (!empty($payload[$key]) && is_array($payload[$key])) {
                $rawLines = $payload[$key];
                break;
            }
        }

        $lineItems = [];
        foreach ($rawLines ?? [] as $index => $line) {
            if (!is_array($line)) {
                continue;
            }

            $normalized = $this->normalizeDraftOrderLineItem($line, (int) $index);
            if ($normalized !== null) {
                $lineItems[] = $normalized;
            }
        }

        if ($lineItems !== []) {
            $input['lineItems'] = $lineItems;
        }

        return $input;
    }

    /**
     * Pass through field-config line item keys — no Odoo/ERP field fallbacks.
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>|null
     */
    private function normalizeDraftOrderLineItem(array $line, int $index): ?array
    {
        if ($line === []) {
            return null;
        }

        $item = [];
        foreach ($line as $key => $value) {
            if (!is_string($key) || str_starts_with($key, '_') || $value === null || $value === '') {
                continue;
            }

            $shopifyKey = self::DRAFT_LINE_FIELD_ALIASES[$key] ?? $key;
            $item[$shopifyKey] = $value;
        }

        if (empty($item['title'])) {
            throw new \RuntimeException(
                'Shopify draftOrderCreate: line item #' . ($index + 1) . ' missing title in mapped payload. '
                . 'Add a line-scope field config for lineItems.title (or line_items.title).'
            );
        }

        if (!isset($item['quantity']) || !is_numeric($item['quantity'])) {
            throw new \RuntimeException(
                'Shopify draftOrderCreate: line item #' . ($index + 1) . ' missing quantity in mapped payload. '
                . 'Add a line-scope field config for lineItems.quantity (or line_items.quantity). '
                . 'Wrong paths like line_items1.quantity are rejected — fix the ecom_field prefix.'
            );
        }

        $item['title']    = trim(strip_tags((string) $item['title']));
        $item['quantity'] = (int) max(1, (float) $item['quantity']);

        if (isset($item['originalUnitPrice']) && $item['originalUnitPrice'] !== '') {
            $item['originalUnitPrice'] = number_format((float) $item['originalUnitPrice'], 2, '.', '');
        }

        if (isset($item['sku'])) {
            $item['sku'] = (string) $item['sku'];
        }

        return $item;
    }

    /** @param  array<string, mixed>  $payload */
    private function pickPayloadScalar(array $payload, string $key): mixed
    {
        $candidates = match ($key) {
            'poNumber' => ['poNumber', 'po_number'],
            default    => [$key],
        };

        foreach ($candidates as $candidate) {
            $value = $payload[$candidate] ?? null;
            if ($value !== null && $value !== '' && $value !== false) {
                return $this->scalarFromMappedValue($value);
            }
        }

        return null;
    }

    private function scalarFromMappedValue(mixed $value): mixed
    {
        if (is_array($value) && array_key_exists(1, $value)) {
            return $value[1];
        }

        return $value;
    }

    /** @param  array<string, mixed>  $data */
    private function looksLikeRawOdooOrder(array $data): bool
    {
        return isset($data['order_line'])
            || isset($data['partner_id'])
            || isset($data['amount_total'])
            || isset($data['partner_invoice_id']);
    }

    /**
     * @deprecated Raw Odoo conversion — use field-config mapped payloads instead.
     */
    private function buildDraftOrderInput(array $order): array
    {
        return $this->normalizeDraftOrderInput($order);
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

    // ── GraphQL decode (structural only — field sync is config-driven) ───

    /**
     * Decode GraphQL order → storable JSON (camelCase, GIDs → ids, edges flattened).
     * Which fields map to ERP is defined in product_field_configs — same as products.
     */
    private function decodeGraphqlOrder(array $node): array
    {
        $decoded = $this->decodeGraphqlValue($node);

        return is_array($decoded) ? $this->enrichFetchedOrder($decoded) : [];
    }

    /** Sync cursors only — not business field mapping. */
    private function enrichFetchedOrder(array $order): array
    {
        if (!empty($order['updatedAt'])) {
            $order['updated_at'] = $order['updatedAt'];
        }

        if (!empty($order['createdAt'])) {
            $order['created_at'] = $order['createdAt'];
        }

        return $order;
    }

    private function decodeGraphqlValue(mixed $value): mixed
    {
        if (is_string($value) && str_starts_with($value, 'gid://')) {
            return $this->fromGid($value);
        }

        if (!is_array($value)) {
            return $value;
        }

        if (array_key_exists('edges', $value) && is_array($value['edges'])) {
            return array_map(
                fn ($edge) => $this->decodeGraphqlValue($edge['node'] ?? $edge),
                $value['edges']
            );
        }

        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = $this->decodeGraphqlValue($item);
        }

        return $out;
    }

    /** @deprecated Alias for decodeGraphqlOrder */
    private function normalizeOrder(array $o): array
    {
        return $this->decodeGraphqlOrder($o);
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