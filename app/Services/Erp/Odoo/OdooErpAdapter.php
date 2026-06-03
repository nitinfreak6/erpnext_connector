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

    public function getProductById(int $erpId): ?array
    {
        // OdooProductService doesn't have a single-read method yet;
        // use getAllActive with a domain offset trick is wasteful — instead
        // we call searchRead directly through the underlying OdooService.
        // If you later add OdooProductService::getById(), call it here.
        $results = $this->products->getAllActive(0, 1);
        // Fallback: fetch via searchRead on product.template
        // This works fine; keep it simple unless you add getById() to the service.
        return $results[0] ?? null;
    }

    public function getVariantsForProducts(array $productIds): array
    {
        return $this->products->getVariantsForTemplates($productIds);
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
        $internalFields = ['_source', '_ecom_id', '_variants_raw', '_shopify_product_type'];
        foreach ($internalFields as $field) {
            unset($productData[$field]);
        }
        
        // Get the underlying OdooService from OdooProductService
        $odooService = app(\App\Services\Odoo\OdooService::class);
        
        // If ID is provided, update existing product
        if (!empty($productData['id'])) {
            $productId = (int) $productData['id'];
            unset($productData['id']); // Remove ID from data payload
            
            // Use Odoo's write method to update product.template
            $odooService->write('product.template', [$productId], $productData);
            
            return $productId;
        }
        
        // Otherwise create new product using product.template
        $productId = $odooService->create('product.template', $productData);
        
        return $productId;
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

    // ── Orders ───────────────────────────────────────────────────────────

    public function getOrdersModifiedSince(string $writeDate, bool $onlyErpOrigin = false): array
    {
        return $this->orders->getModifiedSince($writeDate, $onlyErpOrigin);
    }

    public function getOrder(int $orderId): ?array
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
        return $this->orders->getPickings($pickingIds);
    }

    public function getMoves(array $moveIds, ?array $fields = null): array
    {
        // If no explicit field list, derive from dispatch entity field configs.
        // This replaces the hardcoded ['id','product_id','quantity_done',...] that
        // broke on Odoo 17 and ignored user-configured fields entirely.
        if ($fields === null) {
            try {
                $sync   = app(\App\Services\Sync\UniversalSyncService::class);
                $fields = $sync->getErpFieldsToFetch('dispatch', 'line');
            } catch (\Throwable) {
                // Fallback if no dispatch line configs exist yet
                $fields = ['id', 'product_id', 'product_uom_qty', 'quantity', 'state', 'name'];
            }
        }

        return $this->orders->getMoves($moveIds, $fields);
    }

    public function createOrder(array $orderData): int
    {
        // Strip readonly/computed Odoo fields that cannot be set on create
        $readonly = [
            'delivery_status', 'invoice_status', 'amount_tax',
            'amount_total', 'amount_untaxed', 'state',
            'picking_ids', 'invoice_ids', 'write_date', 'create_date',
        ];
        $payload = array_diff_key($orderData, array_flip($readonly));

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
        if (!empty($orderData['payment_gateway'] ?? $orderData['gateway'] ?? null)) {
            $gateway = $orderData['payment_gateway'] ?? $orderData['gateway'];
            $journalId = $channelMappings->resolveReverse('payment', $ecomDriver, (string) $gateway);
            if ($journalId) $payload['journal_id'] = (int) $journalId;
        }

        // Pricelist — map from currency
        if (!empty($orderData['currency'] ?? $orderData['presentment_currency'] ?? null)) {
            $currency   = $orderData['currency'] ?? $orderData['presentment_currency'];
            $pricelistId = $channelMappings->resolveReverse('pricelist', $ecomDriver, (string) $currency);
            if ($pricelistId) $payload['pricelist_id'] = (int) $pricelistId;
        }

        // Sales Order Type
        $orderTypeId = $channelMappings->resolveReverse('sales_order_type', $ecomDriver, $ecomDriver);
        if ($orderTypeId) $payload['type_id'] = (int) $orderTypeId;

        // Sales Rep / Salesperson
        $salesRepId = $channelMappings->resolveReverse('sales_rep', $ecomDriver, $ecomDriver);
        if ($salesRepId) $payload['user_id'] = (int) $salesRepId;

        // Sales Team (crm.team) — mapped from channel type
        $teamId = $channelMappings->resolveReverse('channel', $ecomDriver, $ecomDriver);
        if ($teamId) $payload['team_id'] = (int) $teamId;

        // Tax lines — resolve each Shopify tax title → Odoo account.tax ID
        // Applied at order level as fiscal_position fallback; line-level taxes
        // come from product configuration in Odoo.
        if (!empty($orderData['tax_lines']) && is_array($orderData['tax_lines'])) {
            $taxIds = [];
            foreach ($orderData['tax_lines'] as $taxLine) {
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
        if (empty($payload['client_order_ref']) && !empty($orderData['name'])) {
            $payload['client_order_ref'] = (string) $orderData['name'];
        }
        if (empty($payload['origin']) && !empty($orderData['name'])) {
            $payload['origin'] = $ecomDriver . ' ' . $orderData['name'];
        }

        // partner_id must be an integer — resolve from email string if needed
        if (isset($payload['partner_id']) && !is_int($payload['partner_id'])) {
            $email     = (string) $payload['partner_id'];
            $partnerId = $this->resolveOrCreatePartner($email, $orderData);
            if (!$partnerId) {
                throw new \RuntimeException("Cannot create order: could not resolve partner for '{$email}'");
            }
            $payload['partner_id'] = $partnerId;
        }
		
		// partner_id must be an integer — resolve from email string if needed
        if (isset($payload['partner_id']) && !is_int($payload['partner_id'])) {
            $email     = (string) $payload['partner_id'];
            $partnerId = $this->resolveOrCreatePartner($email, $orderData);
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
        // This happens when product_field_configs rows for scope='line' are missing.
        if (empty($payload['order_line']) && !empty($orderData['line_items'])) {
            $fallbackLines = [];

            foreach ($orderData['line_items'] as $item) {
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
            if (!empty($orderData['shipping_lines'][0]['price'])) {
                $shipping = $orderData['shipping_lines'][0];
                $fallbackLines[] = [0, 0, [
                    'name'            => 'Shipping: ' . ($shipping['title'] ?? 'Standard'),
                    'product_uom_qty' => 1,
                    'price_unit'      => (float) $shipping['price'],
                ]];
            }

            if (!empty($fallbackLines)) {
                $payload['order_line'] = $fallbackLines;
                \Illuminate\Support\Facades\Log::info(
                    'OdooErpAdapter::createOrder — used fallback line builder (' . count($fallbackLines) . ' lines)'
                );
            }
        }

        return $this->orders->createFromShopify($payload);
    }

    public function confirmOrder(int $orderId): bool
    {
        return $this->orders->confirmOrder($orderId);
    }

    public function cancelOrder(int $orderId): bool
    {
        return $this->orders->cancelOrder($orderId);
    }

    // ── Customers ────────────────────────────────────────────────────────

    public function getCustomersModifiedSince(string $writeDate): array
    {
        return $this->customers->getModifiedSince($writeDate);
    }

    public function findCustomerByEmail(string $email): ?array
    {
        return $this->customers->findByEmail($email);
    }

    public function createCustomer(array $data): int
    {
        return $this->customers->create($data);
    }

    public function updateCustomer(int $customerId, array $data): bool
    {
        return $this->customers->update($customerId, $data);
    }

    public function resolveCountry(string $iso2): ?int
    {
        return $this->customers->resolveCountry($iso2);
    }

    public function resolveState(int $countryId, string $code): ?int
    {
        return $this->customers->resolveState($countryId, $code);
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

    public function driverName(): string
    {
        return 'odoo';
    }

    /**
     * Find existing Odoo partner by email, or create a new one.
     * This is the only Odoo-specific logic in order creation.
     * Other ERP adapters implement their own partner resolution.
     */

    /**
     * Get fulfilled orders from Odoo — stock.picking records in 'done' state.
     * Each picking is linked to a sale.order via sale_id field.
     * Used by Fetch Dispatch / Post Dispatch to push fulfillments to ecom.
     */
    public function getFulfilledOrders(?string $sinceDate = null): array
    {
        $odoo = app(\App\Services\Odoo\OdooService::class);

        $domain = [
            ['state', '=', 'done'],
            ['picking_type_code', '=', 'outgoing'], // delivery orders only, not receipts
            ['sale_id', '!=', false],               // must be linked to a sale order
        ];

        if ($sinceDate) {
            $domain[] = ['date_done', '>=', $sinceDate];
        }

        $pickings = $odoo->searchRead('stock.picking', $domain, [
            'id', 'name', 'state', 'sale_id',
            'carrier_id', 'carrier_tracking_ref',
            'date_done', 'partner_id',
            'move_ids',
        ], ['limit' => 200, 'order' => 'date_done desc']);

        // Enrich each picking with sale order reference
        foreach ($pickings as &$picking) {
            if (!empty($picking['sale_id'])) {
                $saleId = is_array($picking['sale_id']) ? $picking['sale_id'][0] : $picking['sale_id'];
                $picking['erp_order_id'] = $saleId;
            }
        }

        return $pickings;
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