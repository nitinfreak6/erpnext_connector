<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\EntityDefinition;
use App\Models\ProductFieldConfig;
use App\Services\Ecom\EcomInterface;
use App\Services\Erp\ErpInterface;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProductFieldConfigController extends Controller
{
    private const FIELDS_DISK = 'local';

    public function __construct(private readonly SettingsService $settings) {}

    // ── File path helpers — per driver + entity type ─────────────────────

    private function ecomFieldsFile(string $entityType = 'product'): string
    {
        return 'fields/' . $this->settings->ecomDriver() . '_' . $entityType . '_fields.json';
    }

    private function erpFieldsFile(string $entityType = 'product'): string
    {
        return 'fields/' . $this->settings->erpDriver() . '_' . $entityType . '_fields.json';
    }

    // ── Index ─────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        // Active entity type — defaults to 'product' so existing bookmarks work
        $entityType = $request->query('entity', 'product');

        // All active entities for the tab bar — driven from entity_definitions
        $entities = EntityDefinition::where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        // Current entity definition (for scopes etc.)
        $entity = $entities->firstWhere('entity_type', $entityType)
            ?? $entities->first();

        $ecomDriver = $this->settings->ecomDriver();
        $erpDriver  = $this->settings->erpDriver();

        // Only show configs for the selected entity type + active driver pair
        $configs = ProductFieldConfig::where('entity_type', $entityType)
            ->where('ecom_driver', $ecomDriver)
            ->where('erp_driver', $erpDriver)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString();

        $ecomFields    = $this->loadEcomFields($entityType);
        $erpFields     = $this->loadErpFields($entityType);
        $ecomFetchedAt = $this->fieldsFetchedAt($this->ecomFieldsFile($entityType));
        $erpFetchedAt  = $this->fieldsFetchedAt($this->erpFieldsFile($entityType));

        return view('dashboard.product-field-config.index', compact(
            'entity', 'entities', 'entityType',
            'configs', 'ecomFields', 'erpFields',
            'ecomFetchedAt', 'erpFetchedAt',
            'ecomDriver', 'erpDriver'
        ));
    }

    // ── Store ─────────────────────────────────────────────────────────────

    public function store(Request $request)
    {
        $data = $request->validate([
            'entity_type'         => 'nullable|string|max:50',
            'ecom_field'          => 'required|string|max:100',
            'ecom_field_label'    => 'nullable|string|max:255',
            'erp_field'           => 'nullable|string|max:100',
            'erp_field_label'     => 'nullable|string|max:255',
            'erp_field_2'         => 'nullable|string|max:100',
            'erp_field_2_label'   => 'nullable|string|max:255',
            'field_type'          => 'required|in:default,custom,combine',
            'combine_separator'   => 'nullable|string|max:20',
            'scope'               => 'required|string|max:50',
            'default_value'       => 'nullable|string|max:500',
            'transform'           => 'nullable|string|max:50',
            'min_length'          => 'nullable|integer|min:0',
            'max_length'          => 'nullable|integer|min:0',
            'is_active'           => 'boolean',
			'is_readonly'         => 'boolean',
            'sort_order'          => 'nullable|integer',
        ]);

        $data['entity_type'] = $data['entity_type'] ?? 'product';
        $data['ecom_driver'] = $this->settings->ecomDriver();
        $data['erp_driver']  = $this->settings->erpDriver();
        $data['is_active']   = $request->boolean('is_active', true);
        $data['is_readonly'] = $request->boolean('is_readonly', false);
		
        $data['sort_order']  = $data['sort_order'] ?? 0;

        ProductFieldConfig::create($data);

        $entityType = $data['entity_type'];
        return redirect()
            ->route('dashboard.product-field-config.index', ['entity' => $entityType])
            ->with('success', 'Field mapping added.');
    }

    // ── Update ────────────────────────────────────────────────────────────

    public function update(Request $request, ProductFieldConfig $config)
    {
        $data = $request->validate([
            'entity_type'         => 'nullable|string|max:50',
            'ecom_field'          => 'required|string|max:100',
            'ecom_field_label'    => 'nullable|string|max:255',
            'erp_field'           => 'nullable|string|max:100',
            'erp_field_label'     => 'nullable|string|max:255',
            'erp_field_2'         => 'nullable|string|max:100',
            'erp_field_2_label'   => 'nullable|string|max:255',
            'field_type'          => 'required|in:default,custom,combine',
            'combine_separator'   => 'nullable|string|max:20',
            'scope'               => 'required|string|max:50',
            'default_value'       => 'nullable|string|max:500',
            'transform'           => 'nullable|string|max:50',
            'min_length'          => 'nullable|integer|min:0',
            'max_length'          => 'nullable|integer|min:0',
            'is_active'           => 'boolean',
			'is_readonly'         => 'boolean',
            'sort_order'          => 'nullable|integer',
        ]);

        $data['is_active'] = $request->boolean('is_active', true);
        $data['is_readonly'] = $request->boolean('is_readonly', true);

        $config->update($data);

        $entityType = $config->entity_type ?? 'product';
        return redirect()
            ->route('dashboard.product-field-config.index', ['entity' => $entityType])
            ->with('success', 'Field mapping updated.');
    }

    // ── Destroy ───────────────────────────────────────────────────────────

    public function destroy(ProductFieldConfig $config)
    {
        $entityType = $config->entity_type ?? 'product';
        $config->delete();
        return redirect()
            ->route('dashboard.product-field-config.index', ['entity' => $entityType])
            ->with('success', 'Field mapping deleted.');
    }

    // ── Toggle ────────────────────────────────────────────────────────────

    public function toggle(ProductFieldConfig $config)
    {
        $config->update(['is_active' => !$config->is_active]);
        $entityType = $config->entity_type ?? 'product';
        return redirect()
            ->route('dashboard.product-field-config.index', ['entity' => $entityType])
            ->with('success', $config->is_active ? 'Enabled.' : 'Disabled.');
    }

    // ── Fetch ecom fields ─────────────────────────────────────────────────

    public function fetchEcomFields(Request $request)
    {
        $entityType = $request->query('entity', 'product');
        $ecomDriver = $this->settings->ecomDriver();

        try {
            app(EcomInterface::class)->getProducts(['limit' => 1]);
        } catch (\Throwable $e) {
            Log::error("fetchEcomFields [{$ecomDriver}][{$entityType}]: " . $e->getMessage());
            return redirect()
                ->route('dashboard.product-field-config.index', ['entity' => $entityType])
                ->with('error', "Could not connect to {$this->settings->ecomDisplayName()}: " . $e->getMessage());
        }

        $entityFields = match ($entityType) {
            'product'      => [
                'template_fields' => $this->shopifyTemplateFields(),
                'variant_fields'  => $this->shopifyVariantFields(),
                'fields'          => [],
            ],
            'sales_order'  => ['fields' => $this->shopifyOrderFields(),       'template_fields' => [], 'variant_fields' => []],
            'customer'     => ['fields' => $this->shopifyCustomerFields(),     'template_fields' => [], 'variant_fields' => []],
            'dispatch'     => ['fields' => $this->shopifyFulfillmentFields(),  'template_fields' => [], 'variant_fields' => []],
            default        => ['fields' => [],                                 'template_fields' => [], 'variant_fields' => []],
        };

        $data = array_merge(['fetched_at' => now()->toISOString()], $entityFields);

        Storage::disk(self::FIELDS_DISK)->put(
            $this->ecomFieldsFile($entityType),
            json_encode($data, JSON_PRETTY_PRINT)
        );

        return redirect()
            ->route('dashboard.product-field-config.index', ['entity' => $entityType])
            ->with('success', "{$this->settings->ecomDisplayName()} fields updated for {$entityType}.");
    }

    // ── Fetch ERP fields ──────────────────────────────────────────────────

    public function fetchErpFields(Request $request)
    {
        $entityType = $request->query('entity', 'product');
        $erpDriver  = $this->settings->erpDriver();
        $fields     = [];

        try {
            app(ErpInterface::class)->getAllActiveProducts(0, 1);
        } catch (\Throwable $e) {
            Log::error("fetchErpFields [{$erpDriver}][{$entityType}]: " . $e->getMessage());
            return redirect()
                ->route('dashboard.product-field-config.index', ['entity' => $entityType])
                ->with('error', "Could not connect to {$this->settings->erpDisplayName()}: " . $e->getMessage());
        }

        if ($erpDriver === 'odoo') {
            try {
                $odoo       = app(\App\Services\Odoo\OdooService::class);
                $headerModel = $this->odooModelForEntity($entityType);
                $lineModel   = $this->odooLineModelForEntity($entityType);

                // ── Header fields ────────────────────────────────────────────
                $rawHeader = $odoo->executeKw($headerModel, 'fields_get', [], ['attributes' => ['string', 'type']]);
                foreach ($rawHeader as $key => $info) {
                    $fields[] = [
                        'key'   => $key,
                        'label' => ($info['string'] ?? $key) . ' [header]',
                        'type'  => $info['type'] ?? 'char',
                        'scope' => 'header',
                    ];
                }

                // ── Line fields (if entity has a child line model) ───────────
                if ($lineModel) {
                    $rawLine = $odoo->executeKw($lineModel, 'fields_get', [], ['attributes' => ['string', 'type']]);
                    foreach ($rawLine as $key => $info) {
                        $fields[] = [
                            'key'   => $key,
                            'label' => ($info['string'] ?? $key) . ' [line]',
                            'type'  => $info['type'] ?? 'char',
                            'scope' => 'line',
                        ];
                    }
                }

                usort($fields, fn($a, $b) => strcmp($a['label'], $b['label']));

            } catch (\Throwable $e) {
                Log::warning("fetchErpFields: field introspection failed for {$entityType}: " . $e->getMessage());
            }
        }

        $data = ['fetched_at' => now()->toISOString(), 'fields' => $fields];

        Storage::disk(self::FIELDS_DISK)->put(
            $this->erpFieldsFile($entityType),
            json_encode($data, JSON_PRETTY_PRINT)
        );

        return redirect()
            ->route('dashboard.product-field-config.index', ['entity' => $entityType])
            ->with('success', "{$this->settings->erpDisplayName()} fields fetched: " . count($fields) . " fields.");
    }

    // ── Private helpers ───────────────────────────────────────────────────

    private function loadEcomFields(string $entityType): array
    {
        $file = $this->ecomFieldsFile($entityType);
        if (!Storage::disk(self::FIELDS_DISK)->exists($file)) {
            // Backwards compat: try old product-only filename for existing installs
            if ($entityType === 'product') {
                $oldFile = 'fields/' . $this->settings->ecomDriver() . '_product_fields.json';
                if (Storage::disk(self::FIELDS_DISK)->exists($oldFile)) {
                    return json_decode(Storage::disk(self::FIELDS_DISK)->get($oldFile), true) ?? [];
                }
            }
            return ['template_fields' => [], 'variant_fields' => [], 'fields' => []];
        }
        return json_decode(Storage::disk(self::FIELDS_DISK)->get($file), true) ?? [];
    }

    private function loadErpFields(string $entityType): array
    {
        $file = $this->erpFieldsFile($entityType);
        if (!Storage::disk(self::FIELDS_DISK)->exists($file)) {
            if ($entityType === 'product') {
                $oldFile = 'fields/' . $this->settings->erpDriver() . '_product_fields.json';
                if (Storage::disk(self::FIELDS_DISK)->exists($oldFile)) {
                    return json_decode(Storage::disk(self::FIELDS_DISK)->get($oldFile), true) ?? [];
                }
            }
            return ['fields' => [], 'template_fields' => [], 'variant_fields' => []];
        }
        return json_decode(Storage::disk(self::FIELDS_DISK)->get($file), true) ?? [];
    }

    private function fieldsFetchedAt(string $path): ?string
    {
        if (!Storage::disk(self::FIELDS_DISK)->exists($path)) return null;
        $data = json_decode(Storage::disk(self::FIELDS_DISK)->get($path), true);
        return $data['fetched_at'] ?? null;
    }

    // Map entity type → Odoo model for field introspection
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

    // Map entity type → Odoo child line model for line-scope field introspection
    // Returns null for entities that have no line items.
    private function odooLineModelForEntity(string $entityType): ?string
    {
        return match ($entityType) {
            'sales_order'    => 'sale.order.line',
            'purchase_order' => 'purchase.order.line',
            'dispatch'       => 'stock.move',
            'blind_return'   => 'stock.move',
            'receipt_order'  => 'stock.move',
            default          => null,
        };
    }

    // ── Shopify Order fields (sales_order entity) ────────────────────────
    private function shopifyOrderFields(): array
    {
        $fields = [
            // Header
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
            // Customer sub-object
            'customer.id'                               => 'Customer ID',
            'customer.email'                            => 'Customer Email (nested)',
            'customer.first_name'                       => 'Customer First Name',
            'customer.last_name'                        => 'Customer Last Name',
            'customer.phone'                            => 'Customer Phone (nested)',
            'customer.tags'                             => 'Customer Tags',
            'customer.note'                             => 'Customer Note',
            'customer.orders_count'                     => 'Customer Orders Count',
            'customer.total_spent'                      => 'Customer Total Spent',
            // Billing address
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
            // Shipping address
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
            // Line items (line-scope fields)
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
            // Tax lines
            'tax_lines'                                 => 'Tax Lines',
            'tax_lines.title'                           => 'Tax Title',
            'tax_lines.rate'                            => 'Tax Rate',
            'tax_lines.price'                           => 'Tax Price',
            // Shipping lines
            'shipping_lines'                            => 'Shipping Lines',
            'shipping_lines.title'                      => 'Shipping Title',
            'shipping_lines.price'                      => 'Shipping Price',
            'shipping_lines.code'                       => 'Shipping Code',
            // Discount codes
            'discount_codes'                            => 'Discount Codes',
            'discount_codes.code'                       => 'Discount Code',
            'discount_codes.amount'                     => 'Discount Amount',
            'discount_codes.type'                       => 'Discount Type',
        ];

        return array_map(
            fn($key, $label) => ['key' => $key, 'label' => $label, 'scope' => str_starts_with($key, 'line_items.') ? 'line' : 'header'],
            array_keys($fields), array_values($fields)
        );
    }

    // ── Shopify Customer fields ───────────────────────────────────────────
    private function shopifyCustomerFields(): array
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

        return array_map(
            fn($key, $label) => ['key' => $key, 'label' => $label, 'scope' => 'header'],
            array_keys($fields), array_values($fields)
        );
    }

    // ── Shopify Fulfillment fields (dispatch entity) ──────────────────────
    private function shopifyFulfillmentFields(): array
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

        return array_map(
            fn($key, $label) => ['key' => $key, 'label' => $label, 'scope' => str_starts_with($key, 'line_items.') ? 'line' : 'header'],
            array_keys($fields), array_values($fields)
        );
    }

        private function shopifyTemplateFields(): array
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
        return array_map(
            fn($key, $label) => ['key' => $key, 'label' => $label, 'scope' => 'template'],
            array_keys($fields), array_values($fields)
        );
    }

    private function shopifyVariantFields(): array
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
        return array_map(
            fn($key, $label) => ['key' => $key, 'label' => $label, 'scope' => 'variant'],
            array_keys($fields), array_values($fields)
        );
    }
}