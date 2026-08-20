<?php

namespace App\Services\Shopify;

use App\Services\MappingService;
use Illuminate\Support\Facades\Log;

/**
 * Shopify fulfillmentCreate (FulfillmentInput) — field-config mapped payloads pass through
 * with structural normalization only. See:
 * https://shopify.dev/docs/api/admin-graphql/latest/mutations/fulfillmentCreate
 */
class ShopifyFulfillmentService
{
    public function __construct(
        private readonly ShopifyGraphQLService $graphql,
        private readonly ShopifyOrderService $orders,
    ) {}

    /** FulfillmentInput fields only — mutation-level args (e.g. message) are extracted separately. */
    private const FULFILLMENT_INPUT_KEYS = [
        'lineItemsByFulfillmentOrder',
        'notifyCustomer',
        'originAddress',
        'trackingInfo',
    ];

    /**
     * @param  array<string, mixed>  $fulfillmentData  Field-config mapped payload
     */
    public function create(string $orderId, array $fulfillmentData): array
    {
        if (!$this->hasOpenFulfillmentOrders($orderId)) {
            Log::info(
                "ShopifyFulfillmentService: order #{$orderId} has no open fulfillment orders — already fulfilled or closed."
            );

            return ['id' => null, 'status' => 'already_fulfilled', 'skipped' => true];
        }

        [$input, $message] = $this->prepareWireInput($orderId, $fulfillmentData);

        if (empty($input['lineItemsByFulfillmentOrder'])) {
            throw new \RuntimeException(
                'Dispatch mapped payload is missing the nested line structure required by field configs. '
                . 'Add line-scope field configs for the wire root, group fields, and line items array.'
            );
        }

        $mutation = <<<'GQL'
        mutation fulfillmentCreate($fulfillment: FulfillmentInput!, $message: String) {
            fulfillmentCreate(fulfillment: $fulfillment, message: $message) {
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

        $variables = [
            'fulfillment' => $input,
            'message'     => $message !== null && $message !== '' ? $message : null,
        ];

        $data   = $this->graphql->query($mutation, $variables);
        $errors = $this->graphql->extractUserErrors($data, 'fulfillmentCreate');

        if (!empty($errors)) {
            throw new \RuntimeException('Shopify fulfillmentCreate errors: ' . implode('; ', $errors));
        }

        $wireLog = array_filter([
            'fulfillment' => $input,
            'message'     => $message,
        ]);

        return array_merge(
            $this->normalizeFulfillment($data['fulfillmentCreate']['fulfillment']),
            ['wire_input' => $wireLog]
        );
    }

    /** Resolve Shopify order id → open FulfillmentOrder GID (for field-config fulfillmentOrderId). */
    public function resolveFulfillmentOrderId(string $orderId): ?string
    {
        $orders = $this->getFulfillmentOrderIds($orderId);

        return $orders[0]['id'] ?? null;
    }

    /** Resolve Odoo product_id or ERPNext item_code → FulfillmentOrderLineItem GID. */
    public function resolveFulfillmentOrderLineItemId(string $orderId, mixed $productRef): ?string
    {
        if (is_string($productRef)) {
            $skuRef = trim($productRef);
            if ($skuRef !== '' && !is_numeric($skuRef)) {
                $resolved = $this->resolveFulfillmentLineItemBySku($orderId, $skuRef);
                if ($resolved !== null) {
                    return $resolved;
                }
            }
        }

        $odooProductId = $this->extractOdooProductId($productRef);
        if ($odooProductId === null) {
            return null;
        }

        $orderNumeric = $this->fromGid($this->toGid('Order', $orderId));
        $variantIds   = $this->resolveShopifyVariantNumericIdsForOdooProduct($odooProductId);
        $productIds   = $this->resolveShopifyProductNumericIdsForOdooProduct($odooProductId);
        $skus         = $this->resolveSkusForOdooProduct($odooProductId);
        $odooProduct  = $this->readOdooProductVariant($odooProductId);
        $odooName     = strtolower(trim((string) ($odooProduct['name'] ?? '')));

        foreach ($this->getFulfillmentOrderIds($orderNumeric) as $fo) {
            foreach ($fo['lineItems'] as $edge) {
                $foli     = $edge['node'] ?? [];
                $foliGid  = (string) ($foli['id'] ?? '');
                $lineItem = is_array($foli['lineItem'] ?? null) ? $foli['lineItem'] : [];

                if ($foliGid === '') {
                    continue;
                }

                $variantNumeric = isset($lineItem['variant']['id'])
                    ? $this->fromGid((string) $lineItem['variant']['id'])
                    : null;
                $lineProductId = isset($lineItem['variant']['product']['id'])
                    ? $this->fromGid((string) $lineItem['variant']['product']['id'])
                    : null;
                $lineSku = trim((string) ($lineItem['sku'] ?? $lineItem['variant']['sku'] ?? ''));
                $lineTitle = strtolower(trim((string) ($lineItem['title'] ?? '')));

                if ($variantNumeric !== null && in_array($variantNumeric, $variantIds, true)) {
                    return $foliGid;
                }

                if ($lineProductId !== null && in_array($lineProductId, $productIds, true)) {
                    return $foliGid;
                }

                if ($lineSku !== '' && $this->skuMatches($lineSku, $skus)) {
                    return $foliGid;
                }

                if ($odooName !== '' && $lineTitle !== '' && ($lineTitle === $odooName || str_contains($lineTitle, $odooName))) {
                    return $foliGid;
                }
            }
        }

        $shopifyLineItemId = $this->findOrderLineItemIdForOdooProduct($orderNumeric, $odooProductId);
        if ($shopifyLineItemId === null) {
            Log::warning(
                "ShopifyFulfillmentService: no Shopify line item for Odoo product_id {$odooProductId} "
                . "on order #{$orderNumeric} — check product SyncMapping (variant ids tried: "
                . implode(', ', $variantIds)
                . '; product ids tried: '
                . implode(', ', $productIds)
                . '; skus tried: '
                . implode(', ', $skus) . ')'
            );

            return null;
        }

        $fulfillmentOrders = $this->getFulfillmentOrderIds($orderNumeric);
        $lineItemIdMap     = $this->buildOrderLineItemToFulfillmentLineItemMap($fulfillmentOrders);

        return $lineItemIdMap[$shopifyLineItemId] ?? null;
    }

    private function resolveFulfillmentLineItemBySku(string $orderId, string $sku): ?string
    {
        $sku = trim($sku);
        if ($sku === '') {
            return null;
        }

        $candidates = array_values(array_unique(array_filter([
            $sku,
            $this->normalizeSku($sku),
            str_starts_with($sku, '#') ? substr($sku, 1) : '#' . $sku,
        ])));

        $mapping = \App\Models\SyncMapping::query()
            ->whereIn('entity_type', ['product', 'product_variant'])
            ->where(function ($q) use ($candidates) {
                foreach ($candidates as $candidate) {
                    $q->orWhere('erp_id', $candidate)
                        ->orWhere('erp_reference', $candidate);
                }
            })
            ->first();

        if ($mapping !== null) {
            $variantIds = $this->variantIdsFromMappingPayload($mapping);
            foreach ([$mapping->ecom_id, $mapping->ecom_secondary_id] as $ecomId) {
                if ($ecomId === null || $ecomId === '') {
                    continue;
                }
                $variantIds = array_merge(
                    $variantIds,
                    $this->expandEcomIdToVariantNumericIds((string) $ecomId, $mapping),
                );
            }
            $variantIds = array_values(array_unique($variantIds));

            $orderNumeric = $this->fromGid($this->toGid('Order', $orderId));
            foreach ($this->getFulfillmentOrderIds($orderNumeric) as $fo) {
                foreach ($fo['lineItems'] as $edge) {
                    $foli     = $edge['node'] ?? [];
                    $foliGid  = (string) ($foli['id'] ?? '');
                    $lineItem = is_array($foli['lineItem'] ?? null) ? $foli['lineItem'] : [];
                    $variantNumeric = isset($lineItem['variant']['id'])
                        ? $this->fromGid((string) $lineItem['variant']['id'])
                        : null;

                    if ($foliGid !== '' && $variantNumeric !== null && in_array($variantNumeric, $variantIds, true)) {
                        return $foliGid;
                    }
                }
            }
        }

        $orderNumeric = $this->fromGid($this->toGid('Order', $orderId));

        foreach ($this->getFulfillmentOrderIds($orderNumeric) as $fo) {
            foreach ($fo['lineItems'] as $edge) {
                $foli     = $edge['node'] ?? [];
                $foliGid  = (string) ($foli['id'] ?? '');
                $lineItem = is_array($foli['lineItem'] ?? null) ? $foli['lineItem'] : [];

                if ($foliGid === '') {
                    continue;
                }

                $lineSku = trim((string) ($lineItem['sku'] ?? $lineItem['variant']['sku'] ?? ''));
                if ($lineSku !== '' && $this->skuMatches($lineSku, $candidates)) {
                    return $foliGid;
                }
            }
        }

        return null;
    }

    /** @return array<int, array<string, mixed>> */
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
                    fulfillmentLineItems(first: 50) {
                        edges {
                            node {
                                id
                                quantity
                                lineItem { id title sku variant { id sku } }
                            }
                        }
                    }
                }
            }
        }
        GQL;

        try {
            $data = $this->graphql->query($query, [
                'id' => $this->toGid('Order', $orderId),
            ]);

            return array_map(
                fn ($f) => $this->normalizeFulfillmentRecord($f),
                $data['order']['fulfillments'] ?? []
            );
        } catch (\Throwable $e) {
            Log::warning("ShopifyFulfillmentService::getForOrder failed: " . $e->getMessage());

            return [];
        }
    }

    // ── Wire input (structural only — no field defaults) ─────────────────

    /**
     * Split mutation-level args from FulfillmentInput and validate IDs.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: array<string, mixed>, 1: ?string}
     */
    private function prepareWireInput(string $orderId, array $payload): array
    {
        unset($payload['_ecom_order_id'], $payload['_push']);

        $message = isset($payload['message']) ? $this->normalizeMessage($payload['message']) : null;
        unset($payload['message']);

        $input = array_intersect_key($payload, array_flip(self::FULFILLMENT_INPUT_KEYS));

        if (isset($input['lineItemsByFulfillmentOrder']) && is_array($input['lineItemsByFulfillmentOrder'])) {
            $input['lineItemsByFulfillmentOrder'] = array_values(array_map(
                fn ($group) => $this->normalizeFulfillmentOrderGroup($orderId, is_array($group) ? $group : []),
                $input['lineItemsByFulfillmentOrder']
            ));
        }

        return [$input, $message];
    }

    private function normalizeMessage(mixed $value): ?string
    {
        if ($value === null || $value === false || $value === '') {
            return null;
        }

        $text = trim(strip_tags(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return $text !== '' ? $text : null;
    }

    /** @param  array<string, mixed>  $group */
    private function normalizeFulfillmentOrderGroup(string $orderId, array $group): array
    {
        $group['fulfillmentOrderId'] = $this->normalizeFulfillmentOrderId(
            $orderId,
            $group['fulfillmentOrderId'] ?? null
        );

        $items = $group['fulfillmentOrderLineItems'] ?? null;
        if ($items === null) {
            return $group;
        }

        if (!is_array($items)) {
            $group['fulfillmentOrderLineItems'] = null;

            return $group;
        }

        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $id  = $item['id'] ?? $item['fulfillmentOrderLineItemId'] ?? null;
            $qty = (int) ($item['quantity'] ?? 0);
            if ($qty <= 0) {
                foreach (['quantity_done', 'product_uom_qty'] as $key) {
                    if (isset($item[$key]) && is_numeric($item[$key]) && (float) $item[$key] > 0) {
                        $qty = (int) round((float) $item[$key]);
                        break;
                    }
                }
            }

            if ($id === null || $id === '' || $qty <= 0) {
                continue;
            }

            $normalized[] = [
                'id'       => $this->normalizeFulfillmentOrderLineItemId($orderId, $id),
                'quantity' => $qty,
            ];
        }

        $group['fulfillmentOrderLineItems'] = $normalized !== [] ? $normalized : null;

        return $group;
    }

    private function normalizeFulfillmentOrderId(string $orderId, mixed $foId): string
    {
        $orderNumeric = $this->fromGid($this->toGid('Order', $orderId));

        if ($foId === null || $foId === '' || $foId === false) {
            return $this->requireFulfillmentOrderId($orderNumeric);
        }

        $raw = trim((string) $foId);

        if (str_starts_with($raw, 'gid://shopify/FulfillmentOrder/')) {
            return $raw;
        }

        if (str_starts_with($raw, 'gid://shopify/Order/')) {
            Log::warning('ShopifyFulfillmentService: fulfillmentOrderId is an Order GID — resolving open FulfillmentOrder via API');

            return $this->requireFulfillmentOrderId($orderNumeric);
        }

        $rawNumeric = ctype_digit($raw) ? $raw : null;

        // Common mis-map: _ecom_order_id (Shopify order id) used without resolve_fulfillment_order_id transform
        if ($rawNumeric === $orderNumeric) {
            Log::warning('ShopifyFulfillmentService: fulfillmentOrderId equals Shopify order id — resolving FulfillmentOrder via API');

            return $this->requireFulfillmentOrderId($orderNumeric);
        }

        if ($rawNumeric !== null) {
            foreach ($this->getFulfillmentOrderIds($orderNumeric) as $fo) {
                if ($this->fromGid($fo['id']) === $rawNumeric) {
                    return $fo['id'];
                }
            }

            Log::warning(
                "ShopifyFulfillmentService: fulfillmentOrderId {$rawNumeric} is not an open fulfillment order "
                . "on order {$orderNumeric} — using first open fulfillment order"
            );

            return $this->requireFulfillmentOrderId($orderNumeric);
        }

        throw new \RuntimeException(
            'Invalid fulfillmentOrderId "' . $raw . '". '
            . 'Map lineItemsByFulfillmentOrder.fulfillmentOrderId to _ecom_order_id '
            . 'with transform resolve_fulfillment_order_id (Shopify order id ≠ fulfillment order id).'
        );
    }

    private function requireFulfillmentOrderId(string $orderNumeric): string
    {
        $resolved = $this->resolveFulfillmentOrderId($orderNumeric);
        if ($resolved === null) {
            throw new \RuntimeException(
                'Could not resolve fulfillmentOrderId — no open fulfillment orders on Shopify order #' . $orderNumeric . '.'
            );
        }

        return $resolved;
    }

    private function normalizeFulfillmentOrderLineItemId(string $orderId, mixed $id): string
    {
        $raw = trim((string) $id);

        if ($raw === '') {
            throw new \RuntimeException('Empty fulfillment order line item id.');
        }

        if (str_starts_with($raw, 'gid://shopify/FulfillmentOrderLineItem/')) {
            return $raw;
        }

        if (ctype_digit($raw)) {
            foreach ($this->getFulfillmentOrderIds($orderId) as $fo) {
                foreach ($fo['lineItems'] as $edge) {
                    $foliGid = $edge['node']['id'] ?? '';
                    if ($foliGid !== '' && $this->fromGid($foliGid) === $raw) {
                        return $foliGid;
                    }
                }
            }

            // Common mis-map: Odoo product_id used without resolve_fulfillment_line_item_id transform
            $resolved = $this->resolveFulfillmentOrderLineItemId($orderId, (int) $raw);
            if ($resolved !== null) {
                Log::warning(
                    "ShopifyFulfillmentService: line item id {$raw} resolved from Odoo product_id to FulfillmentOrderLineItem"
                );

                return $resolved;
            }

            throw new \RuntimeException(
                'Invalid fulfillment order line item id "' . $raw . '". '
                . 'Map lineItemsByFulfillmentOrder.fulfillmentOrderLineItems.id to product_id '
                . 'with transform resolve_fulfillment_line_item_id. '
                . 'Ensure the Odoo product is synced to Shopify (SyncMapping for erp_id '
                . $raw . ' or its product template, with a valid Shopify variant id).'
            );
        }

        // Odoo many2one [id, "Name"] or other non-numeric product reference
        $resolved = $this->resolveFulfillmentOrderLineItemId($orderId, $id);
        if ($resolved !== null) {
            return $resolved;
        }

        throw new \RuntimeException(
            'Invalid fulfillment order line item id "' . $raw . '". '
            . 'Map lineItemsByFulfillmentOrder.fulfillmentOrderLineItems.id to product_id '
            . 'with transform resolve_fulfillment_line_item_id. '
            . 'Ensure the Odoo product is synced to Shopify (SyncMapping for erp_id '
            . $raw . ' or its product template, with a valid Shopify variant id).'
        );
    }

    /**
     * Build wire payload for sync logs (success or failure).
     *
     * @param  array<string, mixed>  $fulfillmentData
     * @return array<string, mixed>
     */
    public function buildWireInputForLog(string $orderId, array $fulfillmentData): array
    {
        [$input, $message] = $this->prepareWireInput($orderId, $fulfillmentData);

        return array_filter([
            'fulfillment' => $input,
            'message'     => $message,
        ]);
    }

    private function hasOpenFulfillmentOrders(string $orderId): bool
    {
        return $this->getFulfillmentOrderIds($orderId) !== [];
    }

    /** @return array<string, string> */
    private function buildOrderLineItemToFulfillmentLineItemMap(array $fulfillmentOrders): array
    {
        $map = [];

        foreach ($fulfillmentOrders as $fo) {
            foreach ($fo['lineItems'] as $edge) {
                $foli       = $edge['node'];
                $lineItemId = $this->fromGid($foli['lineItem']['id']);
                $map[$lineItemId] = $foli['id'];
            }
        }

        return $map;
    }

    private function findOrderLineItemIdForOdooProduct(string $orderId, int $odooProductId): ?string
    {
        $variantIds = $this->resolveShopifyVariantNumericIdsForOdooProduct($odooProductId);
        $productIds = $this->resolveShopifyProductNumericIdsForOdooProduct($odooProductId);
        $skus       = $this->resolveSkusForOdooProduct($odooProductId);

        $order = $this->orders->get($orderId);
        if ($order === null) {
            return null;
        }

        foreach ($order['line_items'] ?? [] as $lineItem) {
            if (!is_array($lineItem)) {
                continue;
            }

            $variantId = isset($lineItem['variant_id']) ? (string) $lineItem['variant_id'] : null;
            $productId = isset($lineItem['product_id']) ? (string) $lineItem['product_id'] : null;
            $lineId    = isset($lineItem['id']) ? (string) $lineItem['id'] : null;
            $lineSku   = trim((string) ($lineItem['sku'] ?? ''));

            if ($lineId === null) {
                continue;
            }

            if ($variantId !== null && in_array($variantId, $variantIds, true)) {
                return $lineId;
            }

            if ($productId !== null && in_array($productId, $productIds, true)) {
                return $lineId;
            }

            if ($lineSku !== '' && $this->skuMatches($lineSku, $skus)) {
                return $lineId;
            }
        }

        return null;
    }

    private function resolveProductMappingForOdooProduct(int $odooProductId): ?\App\Models\SyncMapping
    {
        $mappings = app(MappingService::class);

        $directVariant = $mappings->findByErpId('product_variant', (string) $odooProductId);
        if ($directVariant !== null) {
            return $directVariant;
        }

        try {
            $templateId = app(\App\Services\Erp\ErpInterface::class)
                ->resolveTemplateIdForVariant($odooProductId);

            if ($templateId !== null && $templateId !== '') {
                $byTemplate = $mappings->findByErpId('product', (string) $templateId);
                if ($byTemplate !== null) {
                    return $byTemplate;
                }
            }
        } catch (\Throwable $e) {
            Log::debug(
                'ShopifyFulfillmentService: template lookup for product_id '
                . $odooProductId . ': ' . $e->getMessage()
            );
        }

        $directProduct = $mappings->findByErpId('product', (string) $odooProductId);
        if ($directProduct !== null) {
            return $directProduct;
        }

        $skuCandidates = [];
        $odooSku = $this->readOdooProductVariantSku($odooProductId);
        if ($odooSku !== null && $odooSku !== '') {
            $skuCandidates[] = $odooSku;
        }

        foreach ($skuCandidates as $sku) {
            $normalized = $this->normalizeSku($sku);
            $candidates = array_values(array_unique(array_filter([
                $sku,
                $normalized,
                $normalized !== '' ? '#' . $normalized : '',
            ])));

            $bySku = \App\Models\SyncMapping::query()
                ->whereIn('entity_type', ['product', 'product_variant'])
                ->where(function ($q) use ($candidates) {
                    foreach ($candidates as $candidate) {
                        $q->orWhere('erp_reference', $candidate)
                          ->orWhere('ecom_handle', $candidate);
                    }
                })
                ->first();

            if ($bySku !== null) {
                return $bySku;
            }

            $cache = \App\Models\ProductCache::query()
                ->where(function ($q) use ($candidates) {
                    foreach ($candidates as $candidate) {
                        $q->orWhere('default_code', $candidate);
                    }
                })
                ->first();

            if ($cache !== null) {
                $byCache = \App\Models\SyncMapping::query()
                    ->where('entity_type', 'product')
                    ->where('ecom_id', (string) ($cache->ecom_product_id ?? $cache->shopify_product_id ?? ''))
                    ->first();

                if ($byCache !== null) {
                    return $byCache;
                }
            }
        }

        return null;
    }

    /** @return list<string> */
    private function resolveShopifyVariantNumericIdsForOdooProduct(int $odooProductId): array
    {
        $mapping = $this->resolveProductMappingForOdooProduct($odooProductId);
        if ($mapping === null) {
            return [];
        }

        $ids = $this->variantIdsFromMappingPayload($mapping);

        foreach ([$mapping->ecom_id, $mapping->ecom_secondary_id] as $ecomId) {
            if ($ecomId === null || $ecomId === '') {
                continue;
            }
            $ids = array_merge($ids, $this->expandEcomIdToVariantNumericIds((string) $ecomId, $mapping));
        }

        return array_values(array_unique($ids));
    }

    /** @return list<string> */
    private function resolveShopifyProductNumericIdsForOdooProduct(int $odooProductId): array
    {
        $mapping = $this->resolveProductMappingForOdooProduct($odooProductId);
        if ($mapping === null) {
            return [];
        }

        $ids = [];
        $variantIds = $this->variantIdsFromMappingPayload($mapping);

        foreach ([$mapping->ecom_id, $mapping->ecom_secondary_id] as $ecomId) {
            if ($ecomId === null || $ecomId === '') {
                continue;
            }

            $numeric = $this->normalizeEcomNumericId((string) $ecomId);
            if ($numeric === '') {
                continue;
            }

            if ($variantIds === [] || !in_array($numeric, $variantIds, true)) {
                $ids[] = $numeric;
            }
        }

        $payload = $mapping->payload();
        $product = is_array($payload['product'] ?? null) ? $payload['product'] : $payload;
        if (!empty($product['id'])) {
            $ids[] = (string) $product['id'];
        }

        return array_values(array_unique($ids));
    }

    /** @return list<string> */
    private function resolveSkusForOdooProduct(int $odooProductId): array
    {
        $skus = [];

        $mapping = $this->resolveProductMappingForOdooProduct($odooProductId);
        if ($mapping?->erp_reference) {
            $skus[] = trim((string) $mapping->erp_reference);
        }

        if ($mapping !== null) {
            $payload = $mapping->payload();
            $product = is_array($payload['product'] ?? null) ? $payload['product'] : $payload;
            foreach ($product['variants'] ?? [] as $variant) {
                if (!is_array($variant)) {
                    continue;
                }
                $sku = trim((string) ($variant['sku'] ?? ''));
                if ($sku !== '') {
                    $skus[] = $sku;
                }
            }
        }

        $product = $this->readOdooProductVariant($odooProductId);
        foreach (['default_code', 'barcode'] as $key) {
            $code = trim((string) ($product[$key] ?? ''));
            if ($code !== '') {
                $skus[] = $code;
            }
        }

        return array_values(array_unique(array_filter($skus, fn ($s) => $s !== '')));
    }

    /** @return list<string> */
    private function variantIdsFromMappingPayload(?\App\Models\SyncMapping $mapping): array
    {
        if ($mapping === null) {
            return [];
        }

        $ids = [];

        foreach ([$mapping->payload(), $this->readCachedEcomProduct($mapping->ecom_id)] as $product) {
            if (!is_array($product)) {
                continue;
            }

            $productNode = is_array($product['product'] ?? null) ? $product['product'] : $product;

            foreach ($productNode['variants'] ?? [] as $variant) {
                if (!is_array($variant)) {
                    continue;
                }

                $variantId = $variant['id'] ?? null;
                if ($variantId !== null && $variantId !== '') {
                    $ids[] = (string) $variantId;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /** @return array<string, mixed>|null */
    private function readCachedEcomProduct(?string $ecomId): ?array
    {
        $ecomId = trim((string) $ecomId);
        if ($ecomId === '') {
            return null;
        }

        try {
            $path = 'ecom_products/' . $ecomId . '.json';
            if (!\Illuminate\Support\Facades\Storage::disk('local')->exists($path)) {
                return null;
            }

            $decoded = json_decode(
                \Illuminate\Support\Facades\Storage::disk('local')->get($path),
                true
            );

            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<string> */
    private function expandEcomIdToVariantNumericIds(string $ecomId, ?\App\Models\SyncMapping $mapping = null): array
    {
        $ecomId = trim($ecomId);
        if ($ecomId === '') {
            return [];
        }

        if ($mapping !== null) {
            $fromPayload = $this->variantIdsFromMappingPayload($mapping);
            $numericId   = $this->normalizeEcomNumericId($ecomId);

            if ($fromPayload !== [] && $numericId !== '' && !in_array($numericId, $fromPayload, true)) {
                return $fromPayload;
            }
        }

        if (str_contains($ecomId, 'ProductVariant/')) {
            return [$this->fromGid($ecomId)];
        }

        if (str_contains($ecomId, 'Product/')) {
            return $this->fetchVariantNumericIdsForProduct($this->fromGid($ecomId));
        }

        if (str_starts_with($ecomId, 'gid://')) {
            return [$this->fromGid($ecomId)];
        }

        $fromProduct = $this->fetchVariantNumericIdsForProduct($ecomId);
        if ($fromProduct !== []) {
            return $fromProduct;
        }

        // Variant-level mapping (inventory) when product expansion finds nothing.
        if (ctype_digit($ecomId)) {
            return [$ecomId];
        }

        return [];
    }

    private function normalizeEcomNumericId(string $ecomId): string
    {
        $ecomId = trim($ecomId);
        if ($ecomId === '') {
            return '';
        }

        if (str_starts_with($ecomId, 'gid://')) {
            return $this->fromGid($ecomId);
        }

        if (str_contains($ecomId, '/')) {
            return $this->fromGid($ecomId);
        }

        return $ecomId;
    }

    /** @return list<string> */
    private function fetchVariantNumericIdsForProduct(string $productId): array
    {
        $query = <<<'GQL'
        query productVariants($id: ID!) {
            product(id: $id) {
                variants(first: 100) {
                    edges { node { id } }
                }
            }
        }
        GQL;

        try {
            $data = $this->graphql->query($query, [
                'id' => $this->toGid('Product', $productId),
            ]);

            $ids = [];
            foreach ($data['product']['variants']['edges'] ?? [] as $edge) {
                if (!empty($edge['node']['id'])) {
                    $ids[] = $this->fromGid((string) $edge['node']['id']);
                }
            }

            return $ids;
        } catch (\Throwable $e) {
            Log::debug(
                'ShopifyFulfillmentService: fetchVariantNumericIdsForProduct failed for '
                . $productId . ': ' . $e->getMessage()
            );

            return [];
        }
    }

    /** @return array<string, mixed> */
    private function readOdooProductVariant(int $variantId): array
    {
        try {
            $rows = app(\App\Services\Odoo\OdooService::class)
                ->read('product.product', [$variantId], ['default_code', 'barcode', 'name']);

            return is_array($rows[0] ?? null) ? $rows[0] : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function readOdooProductVariantSku(int $variantId): ?string
    {
        $product = $this->readOdooProductVariant($variantId);
        foreach (['default_code', 'barcode'] as $key) {
            $code = trim((string) ($product[$key] ?? ''));
            if ($code !== '') {
                return $code;
            }
        }

        return null;
    }

    /** @param  list<string>  $candidates */
    private function skuMatches(string $lineSku, array $candidates): bool
    {
        $lineSku = $this->normalizeSku($lineSku);
        if ($lineSku === '') {
            return false;
        }

        foreach ($candidates as $candidate) {
            if ($this->normalizeSku($candidate) === $lineSku) {
                return true;
            }
        }

        return false;
    }

    private function normalizeSku(string $sku): string
    {
        return ltrim(strtolower(trim($sku)), '#');
    }

    private function extractOdooProductId(mixed $productRef): ?int
    {
        if (is_array($productRef)) {
            $id = $productRef[0] ?? null;

            return is_numeric($id) ? (int) $id : null;
        }

        return is_numeric($productRef) ? (int) $productRef : null;
    }

    /** @return list<array{id:string,lineItems:list<array{node:array}>}> */
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
                                        lineItem {
                                            id
                                            title
                                            sku
                                            variant {
                                                id
                                                sku
                                                product { id }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        GQL;

        $data = $this->graphql->query($query, [
            'id' => $this->toGid('Order', $orderId),
        ]);

        $ids = [];
        foreach ($data['order']['fulfillmentOrders']['edges'] ?? [] as $edge) {
            $fo = $edge['node'];
            if (in_array($fo['status'], ['OPEN', 'IN_PROGRESS', 'SCHEDULED'], true)) {
                $ids[] = [
                    'id'        => $fo['id'],
                    'lineItems' => $fo['lineItems']['edges'] ?? [],
                ];
            }
        }

        return $ids;
    }

    /** @return array<string, mixed> */
    private function normalizeFulfillmentRecord(array $f): array
    {
        $lineItems = [];
        foreach ($f['fulfillmentLineItems']['edges'] ?? [] as $edge) {
            $node = $edge['node'] ?? [];
            if (!is_array($node)) {
                continue;
            }

            $lineItems[] = [
                'id'       => isset($node['id']) ? $this->fromGid($node['id']) : null,
                'quantity' => $node['quantity'] ?? null,
                'lineItem' => [
                    'id'    => isset($node['lineItem']['id']) ? $this->fromGid($node['lineItem']['id']) : null,
                    'title' => $node['lineItem']['title'] ?? null,
                    'sku'   => $node['lineItem']['sku'] ?? ($node['lineItem']['variant']['sku'] ?? null),
                ],
            ];
        }

        $tracking = $f['trackingInfo'][0] ?? ($f['trackingInfo'] ?? []);

        return [
            'id'                 => isset($f['id']) ? $this->fromGid($f['id']) : null,
            'status'             => strtolower($f['status'] ?? ''),
            'createdAt'          => $f['createdAt'] ?? null,
            'trackingInfo'       => [
                'number'  => is_array($tracking) ? ($tracking['number'] ?? null) : null,
                'company' => is_array($tracking) ? ($tracking['company'] ?? null) : null,
                'url'     => is_array($tracking) ? ($tracking['url'] ?? null) : null,
            ],
            'fulfillmentLineItems' => $lineItems,
        ];
    }

    /** @return array<string, mixed> */
    private function normalizeFulfillment(array $f): array
    {
        $record = $this->normalizeFulfillmentRecord($f);

        return [
            'id'               => $record['id'],
            'status'           => $record['status'],
            'tracking_number'  => $record['trackingInfo']['number'] ?? null,
            'tracking_company' => $record['trackingInfo']['company'] ?? null,
            'tracking_url'     => $record['trackingInfo']['url'] ?? null,
        ];
    }

    private function toGid(string $type, string $id): string
    {
        if (str_starts_with($id, 'gid://')) {
            return $id;
        }

        return "gid://shopify/{$type}/{$id}";
    }

    private function fromGid(string $gid): string
    {
        return (string) last(explode('/', $gid));
    }
}
