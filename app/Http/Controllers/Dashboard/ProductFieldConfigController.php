<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
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

    // FIX #18/#19: file paths derived from active driver, not hardcoded constants
    private function ecomFieldsFile(): string
    {
        return 'fields/' . $this->settings->ecomDriver() . '_product_fields.json';
    }

    private function erpFieldsFile(): string
    {
        return 'fields/' . $this->settings->erpDriver() . '_product_fields.json';
    }

    // ── Index ────────────────────────────────────────────────────────────

    public function index()
    {
        $configs = ProductFieldConfig::orderBy('sort_order')->orderBy('id')->paginate(50);

        // FIX #18: variable names are driver-agnostic
        $ecomFields     = $this->loadEcomFields();
        $erpFields      = $this->loadErpFields();
        $ecomFetchedAt  = $this->fieldsFetchedAt($this->ecomFieldsFile());
        $erpFetchedAt   = $this->fieldsFetchedAt($this->erpFieldsFile());
        $ecomDriver     = $this->settings->ecomDriver();
        $erpDriver      = $this->settings->erpDriver();

        return view('dashboard.product-field-config.index', compact(
            'configs', 'ecomFields', 'erpFields', 'ecomFetchedAt', 'erpFetchedAt',
            'ecomDriver', 'erpDriver'
        ));
    }

    // ── Store ────────────────────────────────────────────────────────────

    public function store(Request $request)
    {
        $data = $request->validate([
            'entity_type'         => 'nullable|string|max:50',
            'ecom_driver'         => 'nullable|string|max:50',
            'ecom_field'          => 'required|string|max:100',
            'ecom_field_label'    => 'nullable|string|max:255',
            'erp_driver'          => 'nullable|string|max:50',
            'erp_field'           => 'nullable|string|max:100',
            'erp_field_label'     => 'nullable|string|max:255',
            'erp_field_2'         => 'nullable|string|max:100',
            'erp_field_2_label'   => 'nullable|string|max:255',
            'field_type'          => 'required|in:default,custom,combine',
            'combine_separator'   => 'nullable|string|max:20',
            'scope'               => 'required|in:template,variant',
            'default_value'       => 'nullable|string|max:500',
            'transform'           => 'nullable|string|max:50',
            'min_length'          => 'nullable|integer|min:0',
            'max_length'          => 'nullable|integer|min:0',
            'is_active'           => 'boolean',
            'sort_order'          => 'nullable|integer',
        ]);

        $data['entity_type'] = $data['entity_type'] ?? 'product';
        // FIX #18: default to ACTIVE driver — not hardcoded 'shopify'/'odoo'
        $data['ecom_driver'] = $data['ecom_driver'] ?? $this->settings->ecomDriver();
        $data['erp_driver']  = $data['erp_driver']  ?? $this->settings->erpDriver();

        if ($data['field_type'] === 'custom') {
            $data['erp_field'] = $data['erp_field_label'] = $data['erp_field_2'] = $data['erp_field_2_label'] = null;
        }
        if ($data['field_type'] === 'default') {
            $data['erp_field_2'] = $data['erp_field_2_label'] = $data['combine_separator'] = null;
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
            'entity_type'         => 'nullable|string|max:50',
            'ecom_driver'         => 'nullable|string|max:50',
            'ecom_field'          => 'required|string|max:100',
            'ecom_field_label'    => 'nullable|string|max:255',
            'erp_driver'          => 'nullable|string|max:50',
            'erp_field'           => 'nullable|string|max:100',
            'erp_field_label'     => 'nullable|string|max:255',
            'erp_field_2'         => 'nullable|string|max:100',
            'erp_field_2_label'   => 'nullable|string|max:255',
            'field_type'          => 'required|in:default,custom,combine',
            'combine_separator'   => 'nullable|string|max:20',
            'scope'               => 'required|in:template,variant',
            'default_value'       => 'nullable|string|max:500',
            'transform'           => 'nullable|string|max:50',
            'min_length'          => 'nullable|integer|min:0',
            'max_length'          => 'nullable|integer|min:0',
            'is_active'           => 'boolean',
            'sort_order'          => 'nullable|integer',
        ]);

        // FIX #18: default to ACTIVE driver
        $data['entity_type'] = $data['entity_type'] ?? 'product';
        $data['ecom_driver'] = $data['ecom_driver'] ?? $this->settings->ecomDriver();
        $data['erp_driver']  = $data['erp_driver']  ?? $this->settings->erpDriver();

        if ($data['field_type'] === 'custom') {
            $data['erp_field'] = $data['erp_field_label'] = $data['erp_field_2'] = $data['erp_field_2_label'] = null;
        }
        if ($data['field_type'] === 'default') {
            $data['erp_field_2'] = $data['erp_field_2_label'] = $data['combine_separator'] = null;
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

    // ── FIX #19: fetchEcomFields() replaces fetchShopifyFields() ─────────
    // Uses EcomInterface to verify connection, saves to driver-named file.

    public function fetchEcomFields()
    {
        $ecomDriver = $this->settings->ecomDriver();

        try {
            // Verify ecom connection is working
            $ecom = app(EcomInterface::class);
            // A lightweight test — list webhooks or get products with limit=1
            $ecom->getProducts(['limit' => 1]);
        } catch (\Throwable $e) {
            Log::error("fetchEcomFields [{$ecomDriver}]: connection failed. " . $e->getMessage());
            return back()->with('error',
                "Could not connect to {$this->settings->ecomDisplayName()}: " . $e->getMessage()
            );
        }

        // For Shopify specifically, we keep the canonical GraphQL field list
        // Other drivers can extend this with their own default fields
        $data = [
            'fetched_at'      => now()->toISOString(),
            'template_fields' => $this->defaultEcomTemplateFields($ecomDriver),
            'variant_fields'  => $this->defaultEcomVariantFields($ecomDriver),
        ];

        Storage::disk(self::FIELDS_DISK)->put($this->ecomFieldsFile(), json_encode($data, JSON_PRETTY_PRINT));

        return back()->with('success',
            "{$this->settings->ecomDisplayName()} fields updated: "
            . count($data['template_fields']) . ' template + '
            . count($data['variant_fields'])  . ' variant fields.'
        );
    }

    // ── FIX #19: fetchErpFields() replaces fetchOdooFields() ─────────────
    // Uses ErpInterface — works with any ERP driver that supports field introspection.

    public function fetchErpFields()
    {
        $erpDriver = $this->settings->erpDriver();

        try {
            $erp = app(ErpInterface::class);
            // Test connection by fetching a single product
            $erp->getAllActiveProducts(0, 1);
        } catch (\Throwable $e) {
            Log::error("fetchErpFields [{$erpDriver}]: connection failed. " . $e->getMessage());
            return back()->with('error',
                "Could not connect to {$this->settings->erpDisplayName()}: " . $e->getMessage()
            );
        }

        // For Odoo, we use the XML-RPC fields_get call directly since ErpInterface
        // doesn't expose field introspection (it's ERP-specific). The Odoo adapter
        // is resolved here via the container, so if the driver is SAP this won't break —
        // it will just use the default field list below.
        $templateFields = [];
        $variantFields  = [];

        if ($erpDriver === 'odoo') {
            try {
                $odoo = app(\App\Services\Odoo\OdooService::class);

                $templateRaw = $odoo->executeKw('product.template', 'fields_get', [], ['attributes' => ['string', 'type']]);
                $variantRaw  = $odoo->executeKw('product.product',  'fields_get', [], ['attributes' => ['string', 'type']]);

                foreach ($templateRaw as $key => $info) {
                    $templateFields[] = ['key' => $key, 'label' => $info['string'] ?? $key, 'type' => $info['type'] ?? 'char', 'scope' => 'template'];
                }
                foreach ($variantRaw as $key => $info) {
                    $variantFields[] = ['key' => $key, 'label' => $info['string'] ?? $key, 'type' => $info['type'] ?? 'char', 'scope' => 'variant'];
                }

                usort($templateFields, fn($a, $b) => strcmp($a['label'], $b['label']));
                usort($variantFields,  fn($a, $b) => strcmp($a['label'], $b['label']));
            } catch (\Throwable $e) {
                Log::warning("fetchErpFields: could not introspect Odoo fields: " . $e->getMessage());
            }
        }

        $data = [
            'fetched_at'      => now()->toISOString(),
            'template_fields' => $templateFields,
            'variant_fields'  => $variantFields,
        ];

        Storage::disk(self::FIELDS_DISK)->put($this->erpFieldsFile(), json_encode($data, JSON_PRETTY_PRINT));

        return back()->with('success',
            "{$this->settings->erpDisplayName()} fields fetched: "
            . count($templateFields) . ' template + '
            . count($variantFields)  . ' variant fields.'
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    public function loadEcomFields(): array
    {
        $file = $this->ecomFieldsFile();
        if (!Storage::disk(self::FIELDS_DISK)->exists($file)) {
            return [
                'template_fields' => $this->defaultEcomTemplateFields($this->settings->ecomDriver()),
                'variant_fields'  => $this->defaultEcomVariantFields($this->settings->ecomDriver()),
            ];
        }
        return json_decode(Storage::disk(self::FIELDS_DISK)->get($file), true);
    }

    public function loadErpFields(): array
    {
        $file = $this->erpFieldsFile();
        if (!Storage::disk(self::FIELDS_DISK)->exists($file)) {
            return ['template_fields' => [], 'variant_fields' => []];
        }
        return json_decode(Storage::disk(self::FIELDS_DISK)->get($file), true);
    }

    private function fieldsFetchedAt(string $path): ?string
    {
        if (!Storage::disk(self::FIELDS_DISK)->exists($path)) return null;
        $data = json_decode(Storage::disk(self::FIELDS_DISK)->get($path), true);
        return $data['fetched_at'] ?? null;
    }

    // Default field lists per ecom driver
    private function defaultEcomTemplateFields(string $driver): array
    {
        return match ($driver) {
            'shopify' => $this->shopifyTemplateFields(),
            default   => [],
        };
    }

    private function defaultEcomVariantFields(string $driver): array
    {
        return match ($driver) {
            'shopify' => $this->shopifyVariantFields(),
            default   => [],
        };
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
            'templateSuffix'  => 'Template Suffix',
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
