<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\ProductFieldConfig;
use App\Services\Odoo\OdooService;
use App\Services\Shopify\ShopifyGraphQLService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProductFieldConfigController extends Controller
{
    private const FIELDS_DISK  = 'local';
    private const SHOPIFY_FILE = 'fields/shopify_product_fields.json';
    private const ODOO_FILE    = 'fields/odoo_product_fields.json';

    // ── Index ────────────────────────────────────────────────────────────

    public function index()
    {
        $configs = ProductFieldConfig::orderBy('sort_order')->orderBy('id')->paginate(50);

        $shopifyFields = $this->loadShopifyFields();
        $odooFields    = $this->loadOdooFields();

        $shopifyFetchedAt = $this->fieldsFetchedAt(self::SHOPIFY_FILE);
        $odooFetchedAt    = $this->fieldsFetchedAt(self::ODOO_FILE);

        return view('dashboard.product-field-config.index', compact(
            'configs', 'shopifyFields', 'odooFields', 'shopifyFetchedAt', 'odooFetchedAt'
        ));
    }

    // ── Store ────────────────────────────────────────────────────────────

    public function store(Request $request)
    {
        $data = $request->validate([
            'channel'             => 'required|in:shopify,amazon',
            'shopify_field'       => 'required|string|max:100',
            'shopify_field_label' => 'nullable|string|max:255',
            'field_type'          => 'required|in:default,custom,combine',
            'odoo_field'          => 'nullable|string|max:100',
            'odoo_field_label'    => 'nullable|string|max:255',
            'odoo_field_2'        => 'nullable|string|max:100',
            'odoo_field_2_label'  => 'nullable|string|max:255',
            'combine_separator'   => 'nullable|string|max:20',
            'scope'               => 'required|in:template,variant',
            'default_value'       => 'nullable|string|max:500',
            'transform'           => 'nullable|string|max:50',
            'min_length'          => 'nullable|integer|min:0',
            'max_length'          => 'nullable|integer|min:0',
            'is_active'           => 'boolean',
            'sort_order'          => 'nullable|integer',
        ]);

        if ($data['field_type'] === 'custom') {
            $data['odoo_field'] = $data['odoo_field_label'] = $data['odoo_field_2'] = $data['odoo_field_2_label'] = null;
        }
        if ($data['field_type'] === 'default') {
            $data['odoo_field_2'] = $data['odoo_field_2_label'] = $data['combine_separator'] = null;
        }

        ProductFieldConfig::create(array_merge($data, [
            'is_active'  => $request->boolean('is_active', true),
            'sort_order' => $data['sort_order'] ?? 0,
        ]));

        return back()->with('success', 'Field mapping added.');
    }

    // ── Update ───────────────────────────────────────────────────────────

    public function update(Request $request, ProductFieldConfig $config)
    {
        $data = $request->validate([
            'channel'             => 'required|in:shopify,amazon',
            'shopify_field'       => 'required|string|max:100',
            'shopify_field_label' => 'nullable|string|max:255',
            'field_type'          => 'required|in:default,custom,combine',
            'odoo_field'          => 'nullable|string|max:100',
            'odoo_field_label'    => 'nullable|string|max:255',
            'odoo_field_2'        => 'nullable|string|max:100',
            'odoo_field_2_label'  => 'nullable|string|max:255',
            'combine_separator'   => 'nullable|string|max:20',
            'scope'               => 'required|in:template,variant',
            'default_value'       => 'nullable|string|max:500',
            'transform'           => 'nullable|string|max:50',
            'min_length'          => 'nullable|integer|min:0',
            'max_length'          => 'nullable|integer|min:0',
            'is_active'           => 'boolean',
            'sort_order'          => 'nullable|integer',
        ]);

        if ($data['field_type'] === 'custom') {
            $data['odoo_field'] = $data['odoo_field_label'] = $data['odoo_field_2'] = $data['odoo_field_2_label'] = null;
        }
        if ($data['field_type'] === 'default') {
            $data['odoo_field_2'] = $data['odoo_field_2_label'] = $data['combine_separator'] = null;
        }

        $config->update(array_merge($data, ['is_active' => $request->boolean('is_active', true)]));

        return back()->with('success', 'Field mapping updated.');
    }

    // ── Destroy ──────────────────────────────────────────────────────────

    public function destroy(ProductFieldConfig $config)
    {
        $config->delete();
        return back()->with('success', 'Field mapping deleted.');
    }

    // ── Toggle active ────────────────────────────────────────────────────

    public function toggle(ProductFieldConfig $config)
    {
        $config->update(['is_active' => !$config->is_active]);
        return back()->with('success', $config->is_active ? 'Enabled.' : 'Disabled.');
    }

    // ── Fetch Shopify fields via GraphQL → JSON ──────────────────────────
    //
    // Queries Shopify via GraphQL to confirm the connection works, then
    // writes the canonical GraphQL field list to the JSON file.
    //
    // The JSON file is the source of truth for the dashboard dropdown.
    // shopify_field DB column values must match keys in this JSON exactly.
    //
    // If the Shopify connection fails → returns an error, nothing is saved.

    public function fetchShopifyFields()
    {
        try {
            $graphql = app(ShopifyGraphQLService::class);

            // Verify the connection is working with a minimal query
            $graphql->query(<<<'GQL'
                query { shop { name } }
            GQL);

        } catch (\Throwable $e) {
            Log::error('fetchShopifyFields: Shopify connection failed. ' . $e->getMessage());
            return back()->with('error',
                'Could not connect to Shopify: ' . $e->getMessage()
                . ' — Check SHOPIFY_SHOP, SHOPIFY_ACCESS_TOKEN and SHOPIFY_API_VERSION in your .env'
            );
        }

        // Connection confirmed — write the canonical GraphQL field list.
        // These keys are stable across all Shopify stores and map directly
        // to ProductInput / ProductVariantInput in the GraphQL API.
        $data = [
            'fetched_at'      => now()->toISOString(),
            'template_fields' => $this->defaultShopifyTemplateFields(),
            'variant_fields'  => $this->defaultShopifyVariantFields(),
        ];

        Storage::disk(self::FIELDS_DISK)->put(self::SHOPIFY_FILE, json_encode($data, JSON_PRETTY_PRINT));

        return back()->with('success',
            'Shopify fields updated: '
            . count($data['template_fields']) . ' template + '
            . count($data['variant_fields'])  . ' variant fields.'
        );
    }

    // ── Fetch Odoo fields → JSON ─────────────────────────────────────────

    public function fetchOdooFields()
    {
        try {
            $odoo = app(OdooService::class);

            $templateRaw = $odoo->executeKw('product.template', 'fields_get', [], [
                'attributes' => ['string', 'type', 'help'],
            ]);
            $variantRaw = $odoo->executeKw('product.product', 'fields_get', [], [
                'attributes' => ['string', 'type', 'help'],
            ]);

            $templateFields = [];
            foreach ($templateRaw as $key => $info) {
                $templateFields[] = ['key' => $key, 'label' => $info['string'] ?? $key, 'type' => $info['type'] ?? 'char', 'scope' => 'template'];
            }

            $variantFields = [];
            foreach ($variantRaw as $key => $info) {
                $variantFields[] = ['key' => $key, 'label' => $info['string'] ?? $key, 'type' => $info['type'] ?? 'char', 'scope' => 'variant'];
            }

            usort($templateFields, fn($a, $b) => strcmp($a['label'], $b['label']));
            usort($variantFields,  fn($a, $b) => strcmp($a['label'], $b['label']));

            $data = ['fetched_at' => now()->toISOString(), 'template_fields' => $templateFields, 'variant_fields' => $variantFields];

            Storage::disk(self::FIELDS_DISK)->put(self::ODOO_FILE, json_encode($data, JSON_PRETTY_PRINT));

            return back()->with('success',
                'Odoo fields fetched: ' . count($templateFields) . ' template + ' . count($variantFields) . ' variant fields.'
            );
        } catch (\Throwable $e) {
            Log::error('fetchOdooFields failed: ' . $e->getMessage());
            return back()->with('error', 'Failed to fetch Odoo fields: ' . $e->getMessage());
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    public function loadShopifyFields(): array
    {
        if (!Storage::disk(self::FIELDS_DISK)->exists(self::SHOPIFY_FILE)) {
            return [
                'template_fields' => $this->defaultShopifyTemplateFields(),
                'variant_fields'  => $this->defaultShopifyVariantFields(),
            ];
        }
        return json_decode(Storage::disk(self::FIELDS_DISK)->get(self::SHOPIFY_FILE), true);
    }

    public function loadOdooFields(): array
    {
        if (!Storage::disk(self::FIELDS_DISK)->exists(self::ODOO_FILE)) {
            return ['template_fields' => [], 'variant_fields' => []];
        }
        return json_decode(Storage::disk(self::FIELDS_DISK)->get(self::ODOO_FILE), true);
    }

    private function fieldsFetchedAt(string $path): ?string
    {
        if (!Storage::disk(self::FIELDS_DISK)->exists($path)) return null;
        $data = json_decode(Storage::disk(self::FIELDS_DISK)->get($path), true);
        return $data['fetched_at'] ?? null;
    }

    // ── Canonical Shopify GraphQL field lists ─────────────────────────────
    //
    // These are the exact keys used in ProductInput / ProductVariantInput.
    // They are what get stored in shopify_field in the DB and what
    // ShopifyProductService reads to build the GraphQL mutation payload.
    //
    // Dot-notation variant keys (e.g. inventoryItem.barcode) tell
    // ShopifyProductService to nest that value under inventoryItem in the
    // mutation variables. No extra config needed.

    private function defaultShopifyTemplateFields(): array
    {
        $fields = [
            'title'           => 'Title',
            'descriptionHtml' => 'Description (HTML)',
            'vendor'          => 'Vendor',
            'productType'     => 'Product Type',
            'tags'            => 'Tags',
            'status'          => 'Status',
            'handle'          => 'Handle',
            'templateSuffix'  => 'Template Suffix',
            'images'          => 'Images',
        ];

        return array_map(
            fn($key, $label) => ['key' => $key, 'label' => $label, 'scope' => 'template'],
            array_keys($fields), array_values($fields)
        );
    }

    private function defaultShopifyVariantFields(): array
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