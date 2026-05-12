<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\ProductFieldConfig;
use App\Services\Odoo\OdooService;
use App\Services\Shopify\ShopifyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProductFieldConfigController extends Controller
{
    private const FIELDS_DISK    = 'local';
    private const SHOPIFY_FILE   = 'fields/shopify_product_fields.json';
    private const ODOO_FILE      = 'fields/odoo_product_fields.json';

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
            'field_type'          => 'required|in:default,custom',
            'odoo_field'          => 'nullable|string|max:100',
            'odoo_field_label'    => 'nullable|string|max:255',
            'scope'               => 'required|in:template,variant',
            'default_value'       => 'nullable|string|max:500',
            'transform'           => 'nullable|string|max:50',
            'min_length'          => 'nullable|integer|min:0',
            'max_length'          => 'nullable|integer|min:0',
            'is_active'           => 'boolean',
            'sort_order'          => 'nullable|integer',
        ]);

        // custom field type needs no odoo_field
        if ($data['field_type'] === 'custom') {
            $data['odoo_field']       = null;
            $data['odoo_field_label'] = null;
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
            'field_type'          => 'required|in:default,custom',
            'odoo_field'          => 'nullable|string|max:100',
            'odoo_field_label'    => 'nullable|string|max:255',
            'scope'               => 'required|in:template,variant',
            'default_value'       => 'nullable|string|max:500',
            'transform'           => 'nullable|string|max:50',
            'min_length'          => 'nullable|integer|min:0',
            'max_length'          => 'nullable|integer|min:0',
            'is_active'           => 'boolean',
            'sort_order'          => 'nullable|integer',
        ]);

        if ($data['field_type'] === 'custom') {
            $data['odoo_field']       = null;
            $data['odoo_field_label'] = null;
        }

        $config->update(array_merge($data, [
            'is_active'  => $request->boolean('is_active', true),
        ]));


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

    // ── Fetch Shopify fields → JSON ──────────────────────────────────────

    public function fetchShopifyFields()
    {
        try {
            $shopify = app(ShopifyService::class);

            // Get one product to extract real field keys
            $response = $shopify->get('products.json', ['limit' => 1]);
            $product  = $response['products'][0] ?? null;

            // Template-level fields (top-level product keys)
            $templateFields = [];
            if ($product) {
                foreach (array_keys($product) as $key) {
                    if (in_array($key, ['variants', 'options', 'images', 'image'])) continue;
                    $templateFields[] = [
                        'key'   => $key,
                        'label' => ucwords(str_replace('_', ' ', $key)),
                        'scope' => 'template',
                    ];
                }
                // Add images manually since we skip it above (special handling)
                $templateFields[] = ['key' => 'images', 'label' => 'Images', 'scope' => 'template'];
            } else {
                // Fallback hardcoded list if no products exist yet
                $templateFields = $this->defaultShopifyTemplateFields();
            }

            // Variant-level fields
            $variantFields = [];
            $variant = $product['variants'][0] ?? null;
            if ($variant) {
                foreach (array_keys($variant) as $key) {
                    $variantFields[] = [
                        'key'   => $key,
                        'label' => ucwords(str_replace('_', ' ', $key)),
                        'scope' => 'variant',
                    ];
                }
            } else {
                $variantFields = $this->defaultShopifyVariantFields();
            }

            $data = [
                'fetched_at'      => now()->toISOString(),
                'template_fields' => $templateFields,
                'variant_fields'  => $variantFields,
            ];

            Storage::disk(self::FIELDS_DISK)->put(self::SHOPIFY_FILE, json_encode($data, JSON_PRETTY_PRINT));

            return back()->with('success', 'Shopify fields fetched: ' . count($templateFields) . ' template + ' . count($variantFields) . ' variant fields.');
        } catch (\Throwable $e) {
            Log::error('fetchShopifyFields failed: ' . $e->getMessage());
            return back()->with('error', 'Failed to fetch Shopify fields: ' . $e->getMessage());
        }
    }

    // ── Fetch Odoo fields → JSON ─────────────────────────────────────────

    public function fetchOdooFields()
    {
        try {
            $odoo = app(OdooService::class);

            // Get all fields for product.template
            $templateRaw = $odoo->executeKw('product.template', 'fields_get', [], [
                'attributes' => ['string', 'type', 'help'],
            ]);

            // Get all fields for product.product (variants)
            $variantRaw = $odoo->executeKw('product.product', 'fields_get', [], [
                'attributes' => ['string', 'type', 'help'],
            ]);

            $templateFields = [];
            foreach ($templateRaw as $key => $info) {
                $templateFields[] = [
                    'key'   => $key,
                    'label' => $info['string'] ?? $key,
                    'type'  => $info['type']   ?? 'char',
                    'scope' => 'template',
                ];
            }

            $variantFields = [];
            foreach ($variantRaw as $key => $info) {
                $variantFields[] = [
                    'key'   => $key,
                    'label' => $info['string'] ?? $key,
                    'type'  => $info['type']   ?? 'char',
                    'scope' => 'variant',
                ];
            }

            // Sort alphabetically by label
            usort($templateFields, fn($a, $b) => strcmp($a['label'], $b['label']));
            usort($variantFields,  fn($a, $b) => strcmp($a['label'], $b['label']));

            $data = [
                'fetched_at'      => now()->toISOString(),
                'template_fields' => $templateFields,
                'variant_fields'  => $variantFields,
            ];

            Storage::disk(self::FIELDS_DISK)->put(self::ODOO_FILE, json_encode($data, JSON_PRETTY_PRINT));

            return back()->with('success', 'Odoo fields fetched: ' . count($templateFields) . ' template + ' . count($variantFields) . ' variant fields.');
        } catch (\Throwable $e) {
            Log::error('fetchOdooFields failed: ' . $e->getMessage());
            return back()->with('error', 'Failed to fetch Odoo fields: ' . $e->getMessage());
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    public function loadShopifyFields(): array
    {
        if (!Storage::disk(self::FIELDS_DISK)->exists(self::SHOPIFY_FILE)) {
            return ['template_fields' => $this->defaultShopifyTemplateFields(), 'variant_fields' => $this->defaultShopifyVariantFields()];
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


    private function defaultShopifyTemplateFields(): array
    {
        return array_map(fn($k) => ['key' => $k, 'label' => ucwords(str_replace('_', ' ', $k)), 'scope' => 'template'], [
            'title', 'body_html', 'vendor', 'product_type', 'tags', 'status',
            'published_at', 'template_suffix', 'images', 'handle',
        ]);
    }

    private function defaultShopifyVariantFields(): array
    {
        return array_map(fn($k) => ['key' => $k, 'label' => ucwords(str_replace('_', ' ', $k)), 'scope' => 'variant'], [
            'sku', 'price', 'compare_at_price', 'weight', 'weight_unit', 'barcode',
            'inventory_quantity', 'inventory_management', 'inventory_policy',
            'fulfillment_service', 'requires_shipping', 'taxable',
            'option1', 'option2', 'option3', 'image_id',
        ]);
    }
}