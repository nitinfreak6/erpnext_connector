<?php

namespace App\Services\Erp\Odoo;

use App\Services\Erp\ErpInterface;
use App\Services\Odoo\OdooCustomerService;
use App\Services\Odoo\OdooInventoryService;
use App\Services\Odoo\OdooOrderService;
use App\Services\Odoo\OdooProductService;

/**
 * OdooErpAdapter
 *
 * Wraps the four existing Odoo service classes behind ErpInterface.
 * The existing OdooProductService, OdooInventoryService, OdooOrderService,
 * and OdooCustomerService are UNCHANGED — this adapter is the only new file
 * needed for Odoo. All sync logic continues to work identically.
 */
class OdooErpAdapter implements ErpInterface
{
    public function __construct(
        private readonly OdooProductService   $products,
        private readonly OdooInventoryService $inventory,
        private readonly OdooOrderService     $orders,
        private readonly OdooCustomerService  $customers,
    ) {}

    // ── Products ─────────────────────────────────────────────────────────

    public function getProductsModifiedSince(string $writeDate): array
    {
        return $this->products->getModifiedSince($writeDate);
    }

    public function getAllActiveProducts(int $offset = 0, int $limit = 100): array
    {
        return $this->products->getAllActive($offset, $limit);
    }
	
	public function getProductByIdFull(int|string $erpId): ?array
	{
		return $this->products->getByIdFull((int) $erpId);
	}

    public function getProductById(int|string $erpId): ?array
    {
        return $this->products->getById((int) $erpId);
    }

    public function getVariantsForProducts(array $productIds): array
    {
        return $this->products->getVariantsForTemplates($productIds);
    }

    public function resolveTemplateIdForVariant(int|string $variantId): ?string
    {
        $templateId = $this->products->resolveTemplateIdForVariant((int) $variantId);

        return $templateId !== null && $templateId > 0 ? (string) $templateId : null;
    }
	
	public function getVendorsForTemplate(int $templateId): array
	{
		return $this->products->getVendorsForTemplate($templateId);
	}

    public function getAttributeValues(array $valueIds): array
    {
        return $this->products->getAttributeValues($valueIds);
    }

    public function getCategory(int $categoryId): ?array
    {
        return $this->products->getCategory($categoryId);
    }

    /**
     * Create or update a product in Odoo
     * 
     * @param array $productData Product data with optional 'id' for update
     * @return int|string The Odoo product ID
     */
    public function upsertProduct(array $productData): int|string
    {
        // Strip internal tracking fields that don't exist in Odoo
        $internalFields = ['_source', '_ecom_id', '_variants_raw', '_shopify_product_type','_primary_vendor'];
        foreach ($internalFields as $field) {
            unset($productData[$field]);
        }

        // Get the underlying OdooService from OdooProductService
        $odooService = app(\App\Services\Odoo\OdooService::class);

        // If ID is provided, update existing product
        if (!empty($productData['id'])) {
            $productId = (int) $productData['id'];

            $vals = $this->sanitizeProductTemplatePayload(
                $this->extractPreMappedOdooFields($productData),
                $productId
            );

            if (empty($vals)) {
                throw new \InvalidArgumentException(
                    'OdooErpAdapter::upsertProduct: empty update payload — check ecom→erp field mappings.'
                );
            }

            // Use Odoo's write method to update product.template
            $odooService->write('product.template', [$productId], $vals);

            return $productId;
        }

        // Otherwise create new product using product.template
        return $this->createProduct($productData);
    }

    // ── Inventory ────────────────────────────────────────────────────────

    public function getInventoryModifiedSince(string $writeDate, ?int $locationId = null): array
    {
        return $this->inventory->getModifiedSince($writeDate, $locationId);
    }

    public function getInventoryForProducts(array $productIds): array
    {
        return $this->inventory->getAllForProducts($productIds);
    }

    public function availableQty(array $quant): int
    {
        return $this->inventory->availableQty($quant);
    }

    public function updateInventoryLevel(array $payload): void
    {
        $this->inventory->updateLevel($payload);
    }

    public function normalizeInventoryQuant(array $quant): array
    {
        return $this->inventory->normalizeQuant($quant);
    }

    public function inventoryProductKeyFromQuant(array $quant): string
    {
        return $this->inventory->productKeyFromQuant($quant);
    }

    public function inventoryLocationKeyFromQuant(array $quant): string
    {
        return $this->inventory->locationKeyFromQuant($quant);
    }

    public function inventorySyncErpIdFromQuant(array $quant): string
    {
        return $this->inventory->inventorySyncErpIdFromQuant($quant);
    }

    public function defaultInventoryFetchLocationId(): ?int
    {
        return $this->inventory->defaultInventoryFetchLocationId();
    }

    public function formatInventoryLocationForWrite(mixed $locationId): int|string
    {
        return $this->inventory->formatInventoryLocationForWrite($locationId);
    }

    public function resolveProductIdByReference(string $reference): ?int
    {
        return $this->inventory->resolveProductIdByReference($reference);
    }

    // ── Orders ───────────────────────────────────────────────────────────

    public function getOrdersModifiedSince(string $writeDate, bool $onlyErpOrigin = false): array
    {
        return $this->orders->getModifiedSince($writeDate, $onlyErpOrigin);
    }

    public function getOrder(int|string $orderId): ?array
    {
        $orders = $this->orders->getById([$orderId]);
        return $orders[0] ?? null;
    }

    public function getOrderLines(array $lineIds): array
    {
        return $this->orders->getOrderLines($lineIds);
    }

    public function getPickings(array $pickingIds): array
    {
        return array_map(
            fn (array $picking) => $this->normalizePicking($picking),
            $this->orders->getPickings($pickingIds),
        );
    }

    public function getMoves(array $moveIds, ?array $fields = null): array
    {
        // If no explicit field list, derive from dispatch entity field configs.
        // This replaces the hardcoded ['id','product_id','quantity_done',...] that
        // broke on Odoo 17 and ignored user-configured fields entirely.
        if ($fields === null) {
            try {
                $sync   = app(\App\Services\Sync\UniversalSyncService::class);
                $fields = $sync->buildDispatchMoveReadFields('erp_to_ecom');
            } catch (\Throwable) {
                $fields = ['id'];
            }
        }

        return $this->orders->getMoves($moveIds, $fields);
    }

    public function createOrder(array $orderData, array $sourceOrder = []): int|string
    {
        $payload = $this->prepareSaleOrderPayload($orderData, $sourceOrder);

        return $this->orders->createFromShopify($payload);
    }

    public function updateOrder(int|string $orderId, array $orderData, array $sourceOrder = []): bool
    {
        $payload = $this->prepareSaleOrderPayload($orderData, $sourceOrder);

        $readonly = [
            'delivery_status', 'invoice_status', 'amount_tax',
            'amount_total', 'amount_untaxed', 'state',
            'picking_ids', 'invoice_ids', 'write_date', 'create_date',
        ];
        $payload = array_diff_key($payload, array_flip($readonly));

        $orderState = $this->resolveSaleOrderState($orderId);
        if ($orderState !== null && $orderState !== 'draft') {
            $payload = $this->stripLockedSaleOrderFieldsForConfirmed($payload, $orderState);
        }

        if (!empty($payload['order_line'])) {
            $payload['order_line'] = $this->reconcileOrderLineCommandsForUpdate($orderId, $payload['order_line']);
            if ($payload['order_line'] === []) {
                unset($payload['order_line']);
            }
        }

        if (empty($payload)) {
            return true;
        }

        return (bool) app(\App\Services\Odoo\OdooService::class)
            ->write('sale.order', [$orderId], $payload);
    }

    private function resolveSaleOrderState(int $orderId): ?string
    {
        $rows = $this->orders->getById([$orderId]);

        return isset($rows[0]['state']) ? (string) $rows[0]['state'] : null;
    }

    /** @param  array<string, mixed>  $payload */
    private function stripLockedSaleOrderFieldsForConfirmed(array $payload, string $state): array
    {
        unset(
            $payload['pricelist_id'],
            $payload['partner_id'],
            $payload['partner_invoice_id'],
            $payload['partner_shipping_id'],
            $payload['warehouse_id'],
            $payload['payment_term_id'],
            $payload['fiscal_position_id'],
            $payload['journal_id'],
            $payload['company_id'],
            $payload['currency_id'],
        );

        if (in_array($state, ['sale', 'done', 'cancel'], true)) {
            unset($payload['date_order']);
        }

        return $payload;
    }

    /**
     * Build the final sale.order values sent to Odoo (after channel mappings, SKU resolve, line fallback).
     */
    private function prepareSaleOrderPayload(array $orderData, array $sourceOrder = []): array
    {
        $source = !empty($sourceOrder) ? $sourceOrder : $orderData;

        // Strip readonly/computed Odoo fields that cannot be set on create
        $readonly = [
            'delivery_status', 'invoice_status', 'amount_tax',
            'amount_total', 'amount_untaxed', 'state',
            'picking_ids', 'invoice_ids', 'write_date', 'create_date',
        ];
        $payload = array_diff_key($orderData, array_flip($readonly));

        foreach (array_keys($payload) as $key) {
            if (preg_match('/^[a-z][a-z0-9_]*$/', (string) $key)) {
                continue;
            }
            if (in_array($key, ['Customer', 'customer'], true) && empty($payload['partner_id'])) {
                $payload['partner_id'] = $payload[$key];
            }
            unset($payload[$key]);
        }

        // Use ChannelMappingService to resolve values from the Mappings UI
        // This is where Warehouse, Payment, Pricelist, Sales Rep, Tax etc. are applied
        $channelMappings = app(\App\Services\ChannelMappingService::class);
        $ecomDriver      = app(\App\Services\SettingsService::class)->ecomDriver();

        // Warehouse / delivery location
        if (!empty($payload['warehouse_id']) && !is_int($payload['warehouse_id'])) {
            $mapped = $channelMappings->resolve('warehouse', $ecomDriver, (string) $payload['warehouse_id']);
            if ($mapped) $payload['warehouse_id'] = (int) $mapped;
        }

        // Payment journal — map from ecom payment gateway name
        if (!empty($source['payment_gateway'] ?? $source['gateway'] ?? null)) {
            $gateway = $source['payment_gateway'] ?? $source['gateway'];
            $journalId = $channelMappings->resolveReverse('payment', $ecomDriver, (string) $gateway);
            if ($journalId) $payload['journal_id'] = (int) $journalId;
        }

        // Pricelist — resolved after payload build (search_read, not read — mapped ID 1 may exist but be unusable)

        // Sales Order Type
        // type_id (sale.order.type) — only available if sale_order_type module is installed.
        // Removed from payload for Odoo 17+ compatibility; the module was split/renamed.
        // Re-enable by adding type_id mapping via ChannelMapping if your Odoo has the module.
        // $orderTypeId = $channelMappings->resolveReverse('sales_order_type', $ecomDriver, $ecomDriver);
        // if ($orderTypeId) $payload['type_id'] = (int) $orderTypeId;

        // Sales Rep / Salesperson
        $salesRepId = $channelMappings->resolveReverse('sales_rep', $ecomDriver, $ecomDriver);
        if ($salesRepId) {
            $this->setMany2OneIfExists($payload, 'user_id', 'res.users', (int) $salesRepId);
        }

        // Sales Team (crm.team) — mapped from channel type
        $teamId = $channelMappings->resolveReverse('channel', $ecomDriver, $ecomDriver);
        if ($teamId) {
            $this->setMany2OneIfExists($payload, 'team_id', 'crm.team', (int) $teamId);
        }

        // Tax lines — resolve each Shopify tax title → Odoo account.tax ID
        if (!empty($source['tax_lines']) && is_array($source['tax_lines'])) {
            $taxIds = [];
            foreach ($source['tax_lines'] as $taxLine) {
                $taxTitle = $taxLine['title'] ?? '';
                if ($taxTitle) {
                    $taxId = $channelMappings->resolveReverse('tax', $ecomDriver, $taxTitle);
                    if ($taxId) $taxIds[] = (int) $taxId;
                }
            }
            // Only set if we resolved all taxes — don't override Odoo product taxes with partial list
            // Instead store as note for reference
            if (!empty($taxIds)) {
                \Illuminate\Support\Facades\Log::debug("OdooErpAdapter: resolved tax IDs from Shopify tax_lines", ['tax_ids' => $taxIds]);
            }
        }

        // Source / origin reference from ecom order name
        if (empty($payload['client_order_ref']) && !empty($source['name'])) {
            $payload['client_order_ref'] = (string) $source['name'];
        }
        if (empty($payload['origin']) && !empty($source['name'])) {
            $payload['origin'] = $ecomDriver . ' ' . $source['name'];
        }

        // partner_id must be an integer — resolve from email string if needed
        if (isset($payload['partner_id']) && !is_int($payload['partner_id'])) {
            $email     = (string) $payload['partner_id'];
            $partnerId = $this->resolveOrCreatePartner($email, $source);
            if (!$partnerId) {
                throw new \RuntimeException("Cannot create order: could not resolve partner for '{$email}'");
            }
            $payload['partner_id'] = $partnerId;
        }

        // ── Resolve product_id strings → Odoo integer IDs on each line ─────
        // UniversalSyncService writes the SKU string from line_items.sku into
        // product_id (via the array_second transform which is a no-op on strings).
        // We resolve it here to a product.product integer ID.
        // If resolution fails we REMOVE product_id rather than passing false/null —
        // Odoo will keep the line as a description-only line instead of erroring.
        if (!empty($payload['order_line'])) {
            foreach ($payload['order_line'] as &$command) {
                // ORM tuple format: [0, 0, {lineData}]
                if (!is_array($command) || !isset($command[2]) || !is_array($command[2])) {
                    continue;
                }
                $line = &$command[2];

                if (isset($line['product_id']) && !is_int($line['product_id'])) {
                    $sku      = (string) $line['product_id'];
                    $resolved = empty($sku) ? false : $this->resolveProductId($sku);

                    if ($resolved !== false) {
                        $line['product_id'] = $resolved;
                    } else {
                        // Don't send false/null — Odoo rejects it; unset keeps the line
                        // as description-only so the order still creates successfully.
                        unset($line['product_id']);
                    }
                }
            }
            unset($command, $line); // release references
        }

        // ── Fallback: if UniversalSyncService produced no order_line commands,
        // build them directly from the raw ecom line_items array.
        if (empty($payload['order_line']) && !empty($source['line_items'])) {
            $fallbackLines = [];

            foreach ($source['line_items'] as $item) {
                $lineData = [
                    'name'            => ($item['title'] ?? 'Product')
                                         . (!empty($item['variant_title']) ? ' - ' . $item['variant_title'] : ''),
                    'product_uom_qty' => (float) ($item['quantity'] ?? 1),
                    'price_unit'      => (float) ($item['price'] ?? 0),
                ];

                // Try to resolve Odoo product_id from variant SKU or title
                $sku = $item['sku'] ?? $item['title'] ?? '';
                if (!empty($sku)) {
                    $resolved = $this->resolveProductId((string) $sku);
                    if ($resolved !== false) {
                        $lineData['product_id'] = $resolved;
                    }
                }

                $fallbackLines[] = [0, 0, $lineData];
            }

            // Append shipping as a separate line
            if (!empty($source['shipping_lines'][0]['price'])) {
                $shipping = $source['shipping_lines'][0];
                $fallbackLines[] = [0, 0, [
                    'name'            => 'Shipping: ' . ($shipping['title'] ?? 'Standard'),
                    'product_uom_qty' => 1,
                    'price_unit'      => (float) $shipping['price'],
                ]];
            }

            if (!empty($fallbackLines)) {
                $payload['order_line'] = $fallbackLines;
                \Illuminate\Support\Facades\Log::info(
                    'OdooErpAdapter::prepareSaleOrderPayload — used fallback line builder (' . count($fallbackLines) . ' lines)'
                );
            }
        }

        $this->dropMissingMany2Ones($payload, [
            'team_id'       => 'crm.team',
            'user_id'       => 'res.users',
            'journal_id'    => 'account.journal',
            'warehouse_id'  => 'stock.warehouse',
            'partner_id'    => 'res.partner',
        ]);

        $this->ensureSaleOrderPricelist($payload, $source);

        return $payload;
    }

    /**
     * On sale.order write, [0, 0, {...}] creates NEW lines — duplicate products on re-push.
     * Match incoming lines to existing sale.order.line rows and emit [1, id, {...}] updates instead.
     *
     * @param  list<mixed>  $commands
     * @return list<mixed>
     */
    private function reconcileOrderLineCommandsForUpdate(int $orderId, array $commands): array
    {
        $hasCreateCommands = false;
        foreach ($commands as $command) {
            if (is_array($command) && ($command[0] ?? null) === 0 && ($command[1] ?? null) === 0) {
                $hasCreateCommands = true;
                break;
            }
        }

        if (!$hasCreateCommands) {
            return $commands;
        }

        $orders = $this->orders->getById([$orderId]);
        $lineIds = $orders[0]['order_line'] ?? [];
        if (!is_array($lineIds) || $lineIds === []) {
            return $commands;
        }

        $ids = array_values(array_filter(array_map(
            fn ($id) => is_array($id) ? ($id[0] ?? null) : $id,
            $lineIds
        )));

        if ($ids === []) {
            return $commands;
        }

        $normalizer     = app(\App\Services\Odoo\OdooFieldNormalizer::class);
        $existingLines  = $this->orders->getOrderLines($ids);
        $poolByProduct  = [];

        foreach ($existingLines as $line) {
            $lineId    = (int) ($line['id'] ?? 0);
            $productId = $normalizer->extractMany2OneId($line['product_id'] ?? null);
            if ($lineId <= 0 || !$productId) {
                continue;
            }

            $poolByProduct[$productId][] = ['id' => $lineId, 'line' => $line];
        }

        $reconciled = [];

        foreach ($commands as $command) {
            if (!is_array($command) || ($command[0] ?? null) !== 0 || ($command[1] ?? null) !== 0) {
                $reconciled[] = $command;
                continue;
            }

            $lineData = $command[2] ?? null;
            if (!is_array($lineData)) {
                continue;
            }

            $productId = $normalizer->extractMany2OneId($lineData['product_id'] ?? null);

            if ($productId && !empty($poolByProduct[$productId])) {
                $match = array_shift($poolByProduct[$productId]);
                if ($this->saleOrderLineDataChanged($match['line'], $lineData)) {
                    $reconciled[] = [1, $match['id'], $lineData];
                }

                continue;
            }

            $reconciled[] = $command;
        }

        foreach ($poolByProduct as $entries) {
            foreach ($entries as $entry) {
                $reconciled[] = [2, $entry['id']];
            }
        }

        return $reconciled;
    }

    /** @param array<string, mixed> $existing @param array<string, mixed> $incoming */
    private function saleOrderLineDataChanged(array $existing, array $incoming): bool
    {
        $normalizer = app(\App\Services\Odoo\OdooFieldNormalizer::class);

        $existingProduct = $normalizer->extractMany2OneId($existing['product_id'] ?? null);
        $incomingProduct = $normalizer->extractMany2OneId($incoming['product_id'] ?? null);
        if ($existingProduct !== $incomingProduct) {
            return true;
        }

        if (abs((float) ($existing['product_uom_qty'] ?? 0) - (float) ($incoming['product_uom_qty'] ?? 0)) > 0.0001) {
            return true;
        }

        if (abs((float) ($existing['price_unit'] ?? 0) - (float) ($incoming['price_unit'] ?? 0)) > 0.0001) {
            return true;
        }

        $existingName = trim(strip_tags((string) ($existing['name'] ?? '')));
        $incomingName = trim(strip_tags((string) ($incoming['name'] ?? '')));

        return $existingName !== $incomingName;
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $source */
    private function ensureSaleOrderPricelist(array &$payload, array $source): void
    {
        $currency = (string) ($source['currency'] ?? $source['presentment_currency'] ?? '');
        $preferred = isset($payload['pricelist_id']) && is_numeric($payload['pricelist_id'])
            ? (int) $payload['pricelist_id']
            : null;

        if ($preferred === null && $currency !== '') {
            $ecomDriver = app(\App\Services\SettingsService::class)->ecomDriver();
            $mapped = app(\App\Services\ChannelMappingService::class)
                ->resolveReverse('pricelist', $ecomDriver, $currency);
            if ($mapped) {
                $preferred = (int) $mapped;
            }
        }

        $resolved = $this->resolveUsablePricelistId($currency !== '' ? $currency : null, $preferred);
        if ($resolved) {
            if ($preferred !== null && $preferred !== $resolved) {
                \Illuminate\Support\Facades\Log::warning(
                    "OdooErpAdapter: pricelist {$preferred} is not usable for sale orders; using {$resolved} instead"
                );
            }
            $payload['pricelist_id'] = $resolved;

            return;
        }

        unset($payload['pricelist_id']);
        \Illuminate\Support\Facades\Log::warning(
            'OdooErpAdapter: no usable Odoo pricelist found for API user — update Mappings → Pricelist or create an active pricelist in Odoo'
        );
    }

    private function resolveUsablePricelistId(?string $currencyCode, ?int $preferredId = null): ?int
    {
        $candidates = $this->searchUsablePricelists($currencyCode);
        if (empty($candidates)) {
            $candidates = $this->searchUsablePricelists(null);
        }
        if (empty($candidates)) {
            return null;
        }

        $ids = array_map(fn (array $row) => (int) $row['id'], $candidates);

        if ($preferredId !== null && in_array($preferredId, $ids, true)) {
            return $preferredId;
        }

        return $ids[0];
    }

    /** @return array<int, array<string, mixed>> */
    private function searchUsablePricelists(?string $currencyCode): array
    {
        $odoo   = app(\App\Services\Odoo\OdooService::class);
        $domain = [['active', '=', true]];

        if ($currencyCode !== null && $currencyCode !== '') {
            $currencyRows = $odoo->searchRead(
                'res.currency',
                [['name', '=', strtoupper($currencyCode)]],
                ['id'],
                ['limit' => 1]
            );
            if (!empty($currencyRows[0]['id'])) {
                $domain[] = ['currency_id', '=', (int) $currencyRows[0]['id']];
            }
        }

        return $odoo->searchRead(
            'product.pricelist',
            $domain,
            ['id', 'name', 'currency_id'],
            ['limit' => 10, 'order' => 'id asc']
        );
    }

    /** @param array<string, mixed> $payload */
    private function setMany2OneIfExists(array &$payload, string $field, string $model, int $id): void
    {
        if ($id <= 0) {
            unset($payload[$field]);

            return;
        }

        if ($this->odooRecordSearchable($model, $id)) {
            $payload[$field] = $id;

            return;
        }

        unset($payload[$field]);
        \Illuminate\Support\Facades\Log::warning("OdooErpAdapter: skipped {$field}={$id} — {$model} record not searchable for current Odoo user");
    }

    /** @param array<string, mixed> $payload @param array<string, string> $fields */
    private function dropMissingMany2Ones(array &$payload, array $fields): void
    {
        foreach ($fields as $field => $model) {
            if (!isset($payload[$field]) || !is_numeric($payload[$field])) {
                continue;
            }

            $id = (int) $payload[$field];
            if ($id <= 0 || !$this->odooRecordSearchable($model, $id)) {
                unset($payload[$field]);
                if ($id > 0) {
                    \Illuminate\Support\Facades\Log::warning("OdooErpAdapter: removed invalid {$field}={$id} for {$model}");
                }
            }
        }
    }

    private function odooRecordSearchable(string $model, int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        try {
            $domain = [['id', '=', $id]];
            if (in_array($model, ['product.pricelist', 'crm.team', 'res.users', 'res.partner'], true)) {
                $domain[] = ['active', '=', true];
            }

            $rows = app(\App\Services\Odoo\OdooService::class)->searchRead(
                $model,
                $domain,
                ['id'],
                ['limit' => 1]
            );

            return !empty($rows[0]['id']);
        } catch (\Throwable) {
            return false;
        }
    }

    public function getOrderById(int $orderId): ?array
    {
        $orders = $this->orders->getById([$orderId]);
        if (empty($orders)) {
            return null;
        }

        $order = $orders[0];
        $lineIds = $order['order_line'] ?? [];

        if (is_array($lineIds) && !empty($lineIds)) {
            $ids = array_map(fn ($id) => is_array($id) ? ($id[0] ?? $id) : $id, $lineIds);
            $order['order_line_detail'] = $this->orders->getOrderLines(array_values(array_filter($ids)));
        }

        return $order;
    }

    public function confirmOrder(int|string $orderId): bool
    {
        return $this->orders->confirmOrder($orderId);
    }

    public function cancelOrder(int|string $orderId): bool
    {
        return $this->orders->cancelOrder($orderId);
    }

    public function deleteOrder(int|string $orderId): bool
    {
        try {
            $this->cancelOrder($orderId);
        } catch (\Throwable) {
            // Continue — cancelled or already terminal states may still unlink
        }

        return (bool) app(\App\Services\Odoo\OdooService::class)->unlink('sale.order', [$orderId]);
    }

    public function deleteDispatch(int|string $pickingId): bool
    {
        $odoo = app(\App\Services\Odoo\OdooService::class);

        try {
            $odoo->executeKw('stock.picking', 'action_cancel', [[$pickingId]]);
        } catch (\Throwable) {
            // picking may already be done/cancelled
        }

        return (bool) $odoo->unlink('stock.picking', [$pickingId]);
    }

    public function deleteProduct(int|string $productId): bool
    {
        return (bool) app(\App\Services\Odoo\OdooService::class)->unlink('product.template', [$productId]);
    }

    public function deleteCustomer(int|string $customerId): bool
    {
        return (bool) app(\App\Services\Odoo\OdooService::class)->unlink('res.partner', [$customerId]);
    }

    // ── Customers ────────────────────────────────────────────────────────

    public function getCustomersModifiedSince(string $writeDate): array
    {
        return $this->customers->getModifiedSince($writeDate);
    }

    public function getCustomer(int|string $erpId): ?array
    {
        return $this->customers->getById($erpId);
    }

    public function findCustomerByEmail(string $email): ?array
    {
        return $this->customers->findByEmail($email);
    }

    /**
     * Strip non-Odoo keys from a field-config-built payload before write/create.
     */
    private function extractPreMappedOdooFields(array $data): array
    {
        $skip = ['id', '_source', '_ecom_id', '_variants_raw', '_shopify_product_type', '_primary_vendor'];
        $vals = [];

        foreach ($data as $key => $value) {
            if (in_array($key, $skip, true) || str_starts_with((string) $key, '_')) {
                continue;
            }
            $vals[$key] = $value;
        }

        return $vals;
    }

    /**
     * Normalize payload using Odoo fields_get — handles all many2one fields generically.
     */
    private function sanitizeProductTemplatePayload(array $vals, ?int $templateId = null): array
    {
        $normalizer = app(\App\Services\Odoo\OdooFieldNormalizer::class);

        return $normalizer->normalizeWritePayload(
            'product.template',
            $vals,
            $templateId,
            [
                'seller_ids' => fn (mixed $value, ?int $recordId) => is_numeric($value)
                    ? $this->buildSellerIdsCommands((int) $value, $recordId)
                    : $value,
            ]
        );
    }

    /**
     * Resolve Shopify vendor name → Odoo res.partner ID (find or create supplier).
     */
    public function resolveOrCreateSupplierPartner(string $name): ?int
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $odoo = app(\App\Services\Odoo\OdooService::class);

        $existing = $odoo->searchRead(
            'res.partner',
            [['name', '=', $name]],
            ['id', 'supplier_rank'],
            ['limit' => 1]
        );

        if (!empty($existing)) {
            $id = (int) $existing[0]['id'];
            if (empty($existing[0]['supplier_rank'])) {
                $odoo->write('res.partner', [$id], ['supplier_rank' => 1]);
            }
            return $id;
        }

        return (int) $odoo->create('res.partner', $this->filterPartnerCreateValues([
            'name'          => $name,
            'supplier_rank' => 1,
        ]));
    }

    /**
     * Only send fields that exist on res.partner in this Odoo version (Odoo 19 removed company_type).
     */
    private function filterPartnerCreateValues(array $vals): array
    {
        return app(\App\Services\Odoo\OdooFieldNormalizer::class)
            ->filterWritePayload('res.partner', $vals);
    }

    /**
     * Build Odoo one2many commands for product.template seller_ids (vendors tab).
     */
    private function buildSellerIdsCommands(int $partnerId, ?int $templateId = null): array
    {
        if ($templateId) {
            $existing = app(\App\Services\Odoo\OdooService::class)->searchRead(
                'product.supplierinfo',
                [
                    ['product_tmpl_id', '=', $templateId],
                    ['partner_id', '=', $partnerId],
                ],
                ['id'],
                ['limit' => 1]
            );

            if (!empty($existing)) {
                return [[1, (int) $existing[0]['id'], ['partner_id' => $partnerId]]];
            }
        }

        return [[0, 0, [
            'partner_id' => $partnerId,
            'min_qty'    => 1,
            'price'      => 0,
        ]]];
    }

    /**
     * Create product.template in Odoo from a field-config-built payload only.
     */
    public function createProduct(array $data): int
    {
        $odoo    = app(\App\Services\Odoo\OdooService::class);
        $payload = $this->sanitizeProductTemplatePayload(
            $this->extractPreMappedOdooFields($data)
        );

        if (empty($payload)) {
            throw new \InvalidArgumentException(
                'OdooErpAdapter::createProduct: empty payload — configure ecom→erp field mappings in Product Field Config.'
            );
        }

        $productId = $odoo->create('product.template', $payload);

        \Illuminate\Support\Facades\Log::info("OdooErpAdapter::createProduct: created product.template #{$productId} from ecom", [
            'fields' => array_keys($payload),
        ]);

        return (int) $productId;
    }

    public function createCustomer(array $data, array $sourceCustomer = []): int|string
    {
        $payload = $this->sanitizePartnerWritePayload($data);

        if (empty($payload['name'])) {
            $name = trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''));
            if ($name !== '') {
                $payload['name'] = $name;
            } elseif (!empty($payload['email'])) {
                $payload['name'] = (string) $payload['email'];
            }
        }

        if (!isset($payload['customer_rank'])) {
            $payload['customer_rank'] = 1;
        }

        if (!empty($payload['email'])) {
            $existing = $this->customers->findByEmail((string) $payload['email']);
            if ($existing) {
                $this->customers->update((int) $existing['id'], $payload);

                return (int) $existing['id'];
            }
        }

        $id = (int) $this->customers->create($payload);

        if ($id <= 0) {
            throw new \RuntimeException(
                'Odoo res.partner create returned no ID. Payload keys: ' . implode(', ', array_keys($payload))
            );
        }

        return $id;
    }

    public function updateCustomer(int|string $customerId, array $data, array $sourceCustomer = []): bool
    {
        $payload = $this->sanitizePartnerWritePayload($data);

        if (empty($payload)) {
            return true;
        }

        return $this->customers->update($customerId, $payload);
    }

    /**
     * Strip invalid keys before res.partner create/write (mirrors createOrder sanitization).
     */
    private function sanitizePartnerWritePayload(array $data): array
    {
        $readonly = ['write_date', 'create_date', 'display_name', 'id'];
        $payload  = array_diff_key($data, array_flip($readonly));

        foreach (array_keys($payload) as $key) {
            if (preg_match('/^[a-z][a-z0-9_]*$/', (string) $key)) {
                continue;
            }
            unset($payload[$key]);
        }

        if (!empty($payload['country_code']) && empty($payload['country_id'])) {
            $countryId = $this->resolveCountry(strtoupper(trim((string) $payload['country_code'])));
            if ($countryId !== null) {
                $payload['country_id'] = $countryId;
            }
        }

        if (!empty($payload['state_code']) && empty($payload['state_id'])) {
            $countryRef = $payload['country_id'] ?? $payload['country_code'] ?? null;
            $stateId    = $this->resolveStateReference($payload['state_code'], $countryRef);
            if ($stateId !== null) {
                $payload['state_id'] = $stateId;
            }
        }

        $payload = array_filter(
            $payload,
            fn ($v) => $v !== null && $v !== '' && $v !== false
        );

        app(\App\Services\Odoo\OdooFieldNormalizer::class)->assertKnownWriteFields('res.partner', $payload);

        return $payload;
    }

    public function resolveCountry(string $iso2): ?int
    {
        return $this->customers->resolveCountry($iso2);
    }

    public function resolveState(int $countryId, string $code): ?int
    {
        return $this->customers->resolveState($countryId, $code);
    }

    public function prepareProductWriteValue(string $field, mixed $value): mixed
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        $normalizer = app(\App\Services\Odoo\OdooFieldNormalizer::class);

        if ($this->looksLikeMany2OneFieldName($field)) {
            $id = $normalizer->extractMany2OneId($value);

            if ($id === null && $field === 'uom_id' && is_string($value)) {
                $id = $this->resolveUomIdByName(trim($value));
            }

            return $id;
        }

        return $value;
    }

    /** Resolve uom.uom display name (e.g. mm, Units) → Odoo record id for product writes. */
    private function resolveUomIdByName(string $name): ?int
    {
        if ($name === '') {
            return null;
        }

        $odoo = app(\App\Services\Odoo\OdooService::class);

        foreach ([['name', '=', $name], ['name', 'ilike', $name]] as $domain) {
            $found = $odoo->searchRead('uom.uom', [$domain], ['id'], ['limit' => 1]);
            if (!empty($found[0]['id'])) {
                return (int) $found[0]['id'];
            }
        }

        return null;
    }

    public function resolveCountryReference(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        $str = trim((string) $value);
        if ($str === '') {
            return null;
        }

        if (strlen($str) === 2) {
            $id = $this->resolveCountry(strtoupper($str));
            if ($id !== null) {
                return $id;
            }
        }

        return $this->customers->resolveCountryIdByName($str);
    }

    public function resolveStateReference(mixed $stateValue, mixed $countryReference = null): ?int
    {
        if ($stateValue === null || $stateValue === '' || $stateValue === false) {
            return null;
        }

        if (is_numeric($stateValue)) {
            return (int) $stateValue;
        }

        $stateStr = trim((string) $stateValue);
        if ($stateStr === '') {
            return null;
        }

        $countryId = null;
        if ($countryReference !== null && $countryReference !== '') {
            $countryId = $this->resolveCountryReference($countryReference);
        }

        if ($countryId !== null) {
            $byCode = $this->resolveState($countryId, $stateStr);
            if ($byCode !== null) {
                return $byCode;
            }
        }

        return $this->customers->resolveStateIdByName($stateStr, $countryId);
    }

    public function resolveCountryCode(mixed $countryReference): ?string
    {
        if ($countryReference === null || $countryReference === '' || $countryReference === false) {
            return null;
        }

        if (is_string($countryReference) && strlen(trim($countryReference)) === 2) {
            return strtoupper(trim($countryReference));
        }

        $id = app(\App\Services\Odoo\OdooFieldNormalizer::class)->extractMany2OneId($countryReference);
        if ($id === null && is_numeric($countryReference)) {
            $id = (int) $countryReference;
        }

        if ($id !== null) {
            return $this->customers->readCountryCodeById($id);
        }

        if (is_string($countryReference)) {
            $resolvedId = $this->customers->resolveCountryIdByName(trim($countryReference));

            return $resolvedId ? $this->customers->readCountryCodeById($resolvedId) : null;
        }

        return null;
    }

    public function resolveCountryLabel(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        if (is_string($value) && strlen(trim($value)) !== 2) {
            return trim($value);
        }

        return $this->resolveCountryCode($value);
    }

    public function resolveStateCode(mixed $stateReference, mixed $countryReference = null): ?string
    {
        if ($stateReference === null || $stateReference === '' || $stateReference === false) {
            return null;
        }

        if (is_string($stateReference) && strlen(trim($stateReference)) <= 3) {
            return strtoupper(trim($stateReference));
        }

        $countryId = $countryReference !== null && $countryReference !== ''
            ? $this->resolveCountryReference($countryReference)
            : null;

        $stateId = app(\App\Services\Odoo\OdooFieldNormalizer::class)->extractMany2OneId($stateReference);
        if ($stateId === null && is_numeric($stateReference)) {
            $stateId = (int) $stateReference;
        }

        if ($stateId !== null) {
            return $this->customers->readStateCodeById($stateId, $countryId);
        }

        if (is_string($stateReference)) {
            return $this->customers->readStateCodeByName(trim($stateReference), $countryId);
        }

        return null;
    }

    public function resolveDefaultCostCenter(?string $company = null): ?string
    {
        return null;
    }

    public function resolveCompanyForWarehouse(string $warehouseName): ?string
    {
        return null;
    }

    public function resolveCompanyForWarehouseOrDefault(string $warehouseName): string
    {
        unset($warehouseName);

        return (string) config('connectors.odoo.company', '');
    }

    public function extractRelationId(mixed $value): int|string|null
    {
        $id = app(\App\Services\Odoo\OdooFieldNormalizer::class)->extractMany2OneId($value);

        return $id ?? null;
    }

    public function resolvePartnerReference(string $role, mixed $value): int|string|null
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return match ($role) {
            'supplier' => $this->resolveOrCreateSupplierPartner(trim($value)),
            default    => null,
        };
    }

    private function looksLikeMany2OneFieldName(string $field): bool
    {
        return str_ends_with($field, '_id') && !str_ends_with($field, '_ids');
    }

    // ── Normalisation ────────────────────────────────────────────────────

    /**
     * Map Odoo product.template fields → canonical product structure.
     * This is the exact same field set that ShopifyProductService::buildPayload()
     * already expects — no changes needed there.
     */
    public function normalizeProduct(array $raw): array
    {
        return [
            'erp_id'        => $raw['id'],
            'name'          => $raw['name'] ?? '',
            'sku'           => $raw['default_code'] ?? '',
            'barcode'       => $raw['barcode'] ?? '',
            'price'         => $raw['list_price'] ?? 0,
            'cost'          => $raw['standard_price'] ?? 0,
            'weight'        => $raw['weight'] ?? 0,
            'category_id'   => is_array($raw['categ_id'] ?? null) ? $raw['categ_id'][0] : ($raw['categ_id'] ?? null),
            'category_name' => is_array($raw['categ_id'] ?? null) ? ($raw['categ_id'][1] ?? '') : '',
            'description'   => $raw['description_sale'] ?? '',
            'is_published'  => (bool) ($raw['website_published'] ?? false),
            'meta_keywords' => $raw['website_meta_keywords'] ?? '',
            'image_base64'  => $raw['image_1920'] ?? '',
            'write_date'    => $raw['write_date'] ?? '',
            'active'        => (bool) ($raw['active'] ?? true),
            'sale_ok'       => (bool) ($raw['sale_ok'] ?? true),
            // Pass through any extra fields the Shopify service may read
            '_raw'          => $raw,
        ];
    }

    /**
     * Map Odoo product.product fields → canonical variant structure.
     */
    public function normalizeVariant(array $raw): array
    {
        return [
            'erp_id'           => $raw['id'],
            'name'             => $raw['name'] ?? '',
            'sku'              => $raw['default_code'] ?? '',
            'barcode'          => $raw['barcode'] ?? '',
            'price'            => $raw['lst_price'] ?? 0,
            'cost'             => $raw['standard_price'] ?? 0,
            'weight'           => $raw['weight'] ?? 0,
            'template_erp_id'  => is_array($raw['product_tmpl_id'] ?? null)
                                    ? $raw['product_tmpl_id'][0]
                                    : ($raw['product_tmpl_id'] ?? null),
            'active'           => (bool) ($raw['active'] ?? true),
            'write_date'       => $raw['write_date'] ?? '',
            'product_template_attribute_value_ids' => $raw['product_template_attribute_value_ids'] ?? [],
            '_raw'             => $raw,
        ];
    }

    /**
     * Map Odoo res.partner fields → canonical customer structure.
     */
    public function normalizeCustomer(array $raw): array
    {
        return [
            'erp_id'      => $raw['id'],
            'name'        => $raw['name'] ?? '',
            'email'       => $raw['email'] ?? '',
            'phone'       => $raw['phone'] ?? '',
            'street'      => $raw['street'] ?? '',
            'street2'     => $raw['street2'] ?? '',
            'city'        => $raw['city'] ?? '',
            'zip'         => $raw['zip'] ?? '',
            'country_id'  => is_array($raw['country_id'] ?? null) ? $raw['country_id'][0] : null,
            'country_code'=> is_array($raw['country_id'] ?? null) ? ($raw['country_id'][1] ?? '') : '',
            'state_id'    => is_array($raw['state_id'] ?? null) ? $raw['state_id'][0] : null,
            'state_code'  => is_array($raw['state_id'] ?? null) ? ($raw['state_id'][1] ?? '') : '',
            'is_company'  => (bool) ($raw['is_company'] ?? false),
            'write_date'  => $raw['write_date'] ?? '',
            '_raw'        => $raw,
        ];
    }

    /**
     * Map Odoo sale.order fields → canonical order structure.
     */
    public function normalizeOrder(array $raw): array
    {
        return [
            'erp_id'      => $raw['id'],
            'name'        => $raw['name'] ?? '',
            'state'       => $raw['state'] ?? '',
            'origin'      => $raw['origin'] ?? '',
            'partner_id'  => is_array($raw['partner_id'] ?? null) ? $raw['partner_id'][0] : ($raw['partner_id'] ?? null),
            'order_lines' => $raw['order_line'] ?? [],
            'picking_ids' => $raw['picking_ids'] ?? [],
            'date_order'  => $raw['date_order'] ?? '',
            'write_date'  => $raw['write_date'] ?? '',
            '_raw'        => $raw,
        ];
    }

    // ── Meta ─────────────────────────────────────────────────────────────

    /**
     * Return captured Odoo XML-RPC calls for sync detail pages.
     *
     * @return array<int, array<string, mixed>>
     */
    public function takeWireLog(): array
    {
        return app(\App\Services\Odoo\OdooService::class)->takeWireLog();
    }

    public function driverName(): string
    {
        return 'odoo';
    }

    public function normalizeFetchFieldList(string $entityType, array $fields, string $direction = 'erp_to_ecom'): array
    {
        if (!in_array('id', $fields, true)) {
            array_unshift($fields, 'id');
        }

        return array_values(array_unique($fields));
    }

    public function salesOrderCustomerFieldKey(string $direction = 'ecom_to_erp'): string
    {
        return 'partner_id';
    }

    public function dispatchFulfillmentRequiresMappedPayload(): bool
    {
        return true;
    }

    public function enrichOrderForSync(array $order): array
    {
        return $order;
    }

    public function normalizePicking(array $picking): array
    {
        if (!isset($picking['id']) && isset($picking['name'])) {
            $picking['id'] = $picking['name'];
        }

        return $picking;
    }

    public function apiBaseUrl(): ?string
    {
        $url = rtrim((string) config('odoo.url', config('erp.url', '')), '/');

        return $url !== '' ? $url : null;
    }

    // ── Field discovery ───────────────────────────────────────────────────

    /**
     * Enumerate Odoo model fields via the native fields_get RPC, for both the
     * header model and (where applicable) the child line model. Moved here from
     * the dashboard controllers so the field-config menus stay driver-neutral.
     */
    public function getAvailableFields(string $entityType): array
    {
        $fields = [];

        try {
            $odoo        = app(\App\Services\Odoo\OdooService::class);
            $headerModel = $this->odooModelForEntity($entityType);
            $lineModel   = $this->odooLineModelForEntity($entityType);

            // Header-scope fields
            $rawHeader = $odoo->executeKw($headerModel, 'fields_get', [], ['attributes' => ['string', 'type']]);
            $headerScope = ($entityType === 'product') ? 'template' : 'header';
            foreach ($rawHeader as $key => $info) {
                $fields[] = [
                    'key'   => $key,
                    'label' => $info['string'] ?? $key,
                    'type'  => $info['type']   ?? 'char',
                    'scope' => $headerScope,
                ];
            }

            // Line-scope fields (entities with a child line model)
            if ($lineModel) {
                $rawLine = $odoo->executeKw($lineModel, 'fields_get', [], ['attributes' => ['string', 'type']]);
                $lineScope = ($entityType === 'product') ? 'variant' : 'line';
                foreach ($rawLine as $key => $info) {
                    $fields[] = [
                        'key'   => $key,
                        'label' => $info['string'] ?? $key,
                        'type'  => $info['type']   ?? 'char',
                        'scope' => $lineScope,
                    ];
                }
            }
			
			if ($entityType === 'product') {
				$fields[] = [
					'key'   => '_primary_vendor',
					'label' => 'Primary Vendor (computed)',
					'type'  => 'char',
					'scope' => 'template',
				];
				foreach ([0, 1, 2] as $i) {
					$n = $i + 1;
					$fields[] = [
						'key'   => "_attribute_values.{$i}.name",
						'label' => "Attribute value #{$n} name (cached, e.g. Medium)",
						'type'  => 'char',
						'scope' => 'variant',
					];
					$fields[] = [
						'key'   => "_attribute_values.{$i}.attribute_id",
						'label' => "Attribute value #{$n} attribute [id,name] (e.g. size)",
						'type'  => 'many2one',
						'scope' => 'variant',
					];
				}
			}

            if ($entityType === 'dispatch') {
                $fields[] = [
                    'key'   => '_ecom_order_id',
                    'label' => 'Linked e-commerce order ID (injected at fetch/post — not an Odoo column)',
                    'type'  => 'char',
                    'scope' => 'header',
                ];
                $fields[] = [
                    'key'   => 'erp_order_id',
                    'label' => 'Linked Odoo sale order ID (enriched on fetch)',
                    'type'  => 'integer',
                    'scope' => 'header',
                ];
            }

            usort($fields, fn($a, $b) => strcmp($a['label'], $b['label']));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning(
                "OdooErpAdapter::getAvailableFields({$entityType}) failed: " . $e->getMessage()
            );
            return [];
        }

        return $fields;
    }

    public function getMappingOptions(string $type, ?string $search = null): array
    {
        $model = $this->odooModelForMappingType($type);
        if ($model === null) {
            return [];
        }

        try {
            $odoo   = app(\App\Services\Odoo\OdooService::class);
            $domain = match ($type) {
                \App\Models\ChannelMapping::TYPE_WAREHOUSE => [['usage', '=', 'internal']],
                \App\Models\ChannelMapping::TYPE_PAYMENT   => [['type', 'in', ['bank', 'cash', 'general']]],
                default                                    => [],
            };
            $records = $odoo->searchRead($model, $domain, ['id', 'name', 'display_name'], ['limit' => 200]);

            return array_map(fn ($r) => [
                'id'    => (string) $r['id'],
                'label' => (string) ($r['display_name'] ?? $r['name'] ?? "#{$r['id']}"),
            ], $records);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning(
                "OdooErpAdapter::getMappingOptions({$type}) failed: " . $e->getMessage()
            );

            return [];
        }
    }

    private function odooModelForMappingType(string $type): ?string
    {
        return match ($type) {
            \App\Models\ChannelMapping::TYPE_WAREHOUSE        => 'stock.location',
            \App\Models\ChannelMapping::TYPE_SHIPPING         => 'delivery.carrier',
            \App\Models\ChannelMapping::TYPE_CATEGORY         => 'product.category',
            \App\Models\ChannelMapping::TYPE_PRICELIST        => 'product.pricelist',
            \App\Models\ChannelMapping::TYPE_PAYMENT          => 'account.journal',
            \App\Models\ChannelMapping::TYPE_CHANNEL          => 'crm.team',
            \App\Models\ChannelMapping::TYPE_SALES_ORDER_TYPE => 'sale.order.type',
            \App\Models\ChannelMapping::TYPE_SALES_REP        => 'res.users',
            \App\Models\ChannelMapping::TYPE_PRODUCT_SIZE     => 'product.attribute.value',
            \App\Models\ChannelMapping::TYPE_TAX              => 'account.tax',
            default                                           => null,
        };
    }

    // Map entity type → Odoo header model name.
    private function odooModelForEntity(string $entityType): string
    {
        return match ($entityType) {
            'product'                   => 'product.template',
            'customer'                  => 'res.partner',
            'sales_order'               => 'sale.order',
            'dispatch'                  => 'stock.picking',
            'sales_credit'              => 'account.move',
            'sales_credit_confirmation' => 'account.move',
            'blind_return'              => 'stock.picking',
            'purchase_order'            => 'purchase.order',
            'receipt_order'             => 'stock.picking',
            'inventory'                 => 'stock.quant',
            'inventory_adjustment'      => 'stock.inventory',
            default                     => 'product.template',
        };
    }

    // Map entity type → Odoo child line model (null when the entity has no lines).
    private function odooLineModelForEntity(string $entityType): ?string
    {
        return match ($entityType) {
            'product'        => 'product.product',
            'sales_order'    => 'sale.order.line',
            'purchase_order' => 'purchase.order.line',
            'dispatch'       => 'stock.move',
            'blind_return'   => 'stock.move',
            'receipt_order'  => 'stock.move',
            default          => null,
        };
    }

    /**
     * Find existing Odoo partner by email, or create a new one.
     * This is the only Odoo-specific logic in order creation.
     * Other ERP adapters implement their own partner resolution.
     */

    /**
     * Get fulfilled orders from Odoo — stock.picking records in 'done' state.
     * Field list and line enrichment come from dispatch field configs only.
     */
    public function getFulfilledOrders(?string $sinceDate = null): array
    {
        $odoo = app(\App\Services\Odoo\OdooService::class);
        $sync = app(\App\Services\Sync\UniversalSyncService::class);

        $sync->requireLineContainer('dispatch', 'erp_to_ecom');

        $domain = $this->buildFulfilledDispatchSearchDomain($sinceDate);

        $pickings = $odoo->searchRead(
            'stock.picking',
            $domain,
            $this->dispatchPickingFetchFields(),
            ['limit' => 200, 'order' => 'date_done desc']
        );

        foreach ($pickings as &$picking) {
            $picking['erp_order_id'] = $this->extractSaleOrderIdFromPicking($picking);
        }
        unset($picking);

        return $this->enrichDispatchPickingsWithLines($pickings);
    }

    public function applyFulfillmentToSaleOrder(int|string $saleOrderId, array $mappedPayload, array $sourceFulfillment): array
    {
        return app(\App\Services\Odoo\OdooDispatchService::class)
            ->applyFulfillmentToSaleOrder($saleOrderId, $mappedPayload, $sourceFulfillment);
    }

    /** @return list<string> */
    private function dispatchPickingFetchFields(): array
    {
        $sync       = app(\App\Services\Sync\UniversalSyncService::class);
        $normalizer = app(\App\Services\Odoo\OdooFieldNormalizer::class);

        return $normalizer->filterSearchReadFields(
            'stock.picking',
            $sync->buildDispatchPickingReadFields('erp_to_ecom')
        );
    }

    /**
     * @return list<array{0: string, 1: string, 2: mixed}>
     */
    private function buildFulfilledDispatchSearchDomain(?string $sinceDate): array
    {
        $normalizer = app(\App\Services\Odoo\OdooFieldNormalizer::class);
        $defs       = $normalizer->getFieldDefinitions('stock.picking');
        $domain     = [];

        if (isset($defs['state'])) {
            $domain[] = ['state', '=', 'done'];
        }

        if (isset($defs['picking_type_code'])) {
            $domain[] = ['picking_type_code', '=', 'outgoing'];
        }

        foreach ($defs as $name => $def) {
            if (($def['type'] ?? '') === 'many2one' && ($def['relation'] ?? '') === 'sale.order') {
                $domain[] = [$name, '!=', false];
                break;
            }
        }

        if ($sinceDate !== null && $sinceDate !== '' && isset($defs['date_done'])) {
            $domain[] = ['date_done', '>', $sinceDate];
        }

        return $domain;
    }

    /** @param  array<string, mixed>  $picking */
    private function extractSaleOrderIdFromPicking(array $picking): ?int
    {
        $normalizer = app(\App\Services\Odoo\OdooFieldNormalizer::class);
        $defs       = $normalizer->getFieldDefinitions('stock.picking');

        foreach ($defs as $name => $def) {
            if (($def['type'] ?? '') !== 'many2one' || ($def['relation'] ?? '') !== 'sale.order') {
                continue;
            }

            $id = $normalizer->extractMany2OneId($picking[$name] ?? null);

            return $id !== null ? (int) $id : null;
        }

        return null;
    }

    /**
     * Attach full line records for each picking at the configured line_container erp field.
     *
     * @param  array<int, array<string, mixed>>  $pickings
     * @return array<int, array<string, mixed>>
     */
    private function enrichDispatchPickingsWithLines(array $pickings): array
    {
        $sync = app(\App\Services\Sync\UniversalSyncService::class);

        foreach ($pickings as &$picking) {
            $picking = $sync->enrichEntityLines('dispatch', $picking);
        }
        unset($picking);

        return $pickings;
    }

    /** @return list<string> */
    private function dispatchMoveFetchFields(): array
    {
        $sync       = app(\App\Services\Sync\UniversalSyncService::class);
        $normalizer = app(\App\Services\Odoo\OdooFieldNormalizer::class);

        return $normalizer->filterSearchReadFields(
            'stock.move',
            $sync->buildDispatchMoveReadFields('erp_to_ecom')
        );
    }

    private function resolveOrCreatePartner(string $email, array $orderData = []): ?int
    {
        $odoo = app(\App\Services\Odoo\OdooService::class);

        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $existing = $odoo->searchRead(
                'res.partner',
                [['email', '=', $email]],
                ['id'],
                ['limit' => 1]
            );
            if (!empty($existing)) {
                return (int) $existing[0]['id'];
            }
        }

        // Build name from order data
        $name = '';
        if (!empty($orderData['billing_address'])) {
            $b    = $orderData['billing_address'];
            $name = trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? ''));
        }
        if (empty($name) && !empty($orderData['customer'])) {
            $c    = $orderData['customer'];
            $name = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
        }
        if (empty($name)) {
            $name = $email ?: 'Customer';
        }

        $partnerData = ['name' => $name, 'customer_rank' => 1];

        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $partnerData['email'] = $email;
        }

        if (!empty($orderData['billing_address']['phone'])) {
            $partnerData['phone'] = $orderData['billing_address']['phone'];
        }

        return (int) $odoo->create('res.partner', $partnerData);
    }
	
	/**
     * Resolve a product SKU / barcode / name string → Odoo product.product integer ID.
     * Tries default_code (SKU) first, then barcode, then name.
     * Returns false if nothing is found — Odoo will treat the line as
     * a description-only line rather than crashing.
     */
    private function resolveProductId(string $sku): int|false
    {
        $odoo = app(\App\Services\Odoo\OdooService::class);

        // 1. Match by internal reference (SKU / default_code)
        $found = $odoo->searchRead(
            'product.product',
            [['default_code', '=', $sku]],
            ['id'],
            ['limit' => 1]
        );
        if (!empty($found)) {
            return (int) $found[0]['id'];
        }

        // 2. Match by barcode
        $found = $odoo->searchRead(
            'product.product',
            [['barcode', '=', $sku]],
            ['id'],
            ['limit' => 1]
        );
        if (!empty($found)) {
            return (int) $found[0]['id'];
        }

        // 3. Match by product name (fallback)
        $found = $odoo->searchRead(
            'product.product',
            [['name', '=', $sku]],
            ['id'],
            ['limit' => 1]
        );
        if (!empty($found)) {
            return (int) $found[0]['id'];
        }

        // Not found — return false so Odoo keeps the line as description-only
        // instead of throwing. Log it so you can debug missing SKUs.
        \Illuminate\Support\Facades\Log::warning(
            "OdooErpAdapter: could not resolve product_id for SKU/name [{$sku}] — line will have no product linked"
        );

        return false;
    }

}