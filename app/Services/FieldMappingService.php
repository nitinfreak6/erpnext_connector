<?php

namespace App\Services;

use App\Models\ProductFieldConfig;
use App\Models\ChannelMapping;
use App\Services\ChannelMappingService;
use App\Services\Config\NestedFieldResolver;
use App\Services\Config\ValueConditionMapper;
use App\Services\Erp\ErpInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Field Mapping Service - Driver-Agnostic
 * 
 * Handles field mappings between any ERP ↔ Ecom driver pair.
 * 
 * Example usage:
 * 
 * // Get mappings for Shopify ↔ Odoo
 * $mappings = $service->getProductMappings('shopify', 'odoo');
 * 
 * // Get mappings for WooCommerce ↔ NetSuite
 * $mappings = $service->getProductMappings('woocommerce', 'netsuite');
 * 
 * // Map values with conditions in product_field_configs (e.g. 1:ACTIVE, 0:DRAFT)
 */
class FieldMappingService
{
    /** System-only transform markers (seeded rows — not shown in UI). */
    private const SYSTEM_TRANSFORMS = [
        'line_container',
        'skip',
        'synced_customer',
        'synced_product',
        'image_url_to_base64',
        'resolve_product_by_sku',
        'resolve_fulfillment_line_item_id',
        'resolve_fulfillment_order_id',
        'resolve_country_id',
        'resolve_country_code',
        'resolve_country_label',
        'array_second',
        'company_default_cost_center',
    ];

    /** Canonical erp_field keys for inventory ecom→erp mapped payload slots. */
    public const INVENTORY_QTY_ERP_FIELDS = ['quantity', 'qty', 'available_quantity', 'actual_qty', 'reserved_qty'];
    public const INVENTORY_PUSH_QTY_ERP_FIELDS = ['quantity', 'qty', 'available_quantity'];
    public const INVENTORY_PRODUCT_ERP_FIELDS = ['product_id', 'id', 'item_code'];
    public const INVENTORY_LOCATION_ERP_FIELDS = ['location_id', 'warehouse'];

    public static function isInventoryQuantityErpField(string $field): bool
    {
        return in_array(trim($field), self::INVENTORY_QTY_ERP_FIELDS, true);
    }

    public static function isInventoryPushQuantityErpField(string $field): bool
    {
        return in_array(trim($field), self::INVENTORY_PUSH_QTY_ERP_FIELDS, true);
    }

    public static function isInventoryProductErpField(string $field): bool
    {
        return in_array(trim($field), self::INVENTORY_PRODUCT_ERP_FIELDS, true);
    }

    public static function isInventoryLocationErpField(string $field): bool
    {
        return in_array(trim($field), self::INVENTORY_LOCATION_ERP_FIELDS, true);
    }

    public function __construct(
        private readonly NestedFieldResolver $fields,
        private readonly ValueConditionMapper $conditions,
    ) {}
    /**
     * Get field mappings for a specific driver pair
     * 
     * @param string $entityType 'product', 'order', 'customer', 'inventory'
     * @param string $ecomDriver 'shopify', 'woocommerce', 'magento'
     * @param string $erpDriver 'odoo', 'netsuite', 'sap'
     * @param string|null $scope 'template', 'variant', or null for all
     * @return \Illuminate\Support\Collection
     */
    public function getMappings(
        string $entityType,
        string $ecomDriver,
        string $erpDriver,
        ?string $scope = null,
        ?string $direction = null
    ): \Illuminate\Support\Collection {
        $cacheKey = ProductFieldConfig::cacheKey($entityType, $ecomDriver, $erpDriver);
        
        if ($scope) {
            $cacheKey .= "_{$scope}";
        }
        if ($direction) {
            $cacheKey .= "_{$direction}";
        }
        
        return Cache::rememberForever($cacheKey, function () use ($entityType, $ecomDriver, $erpDriver, $scope, $direction) {
            $query = ProductFieldConfig::active()
                ->forEntity($entityType)
                ->forDriverPair($ecomDriver, $erpDriver)
                ->ordered();
            
            if ($scope) {
                $query->where('scope', $scope);
            }

            if ($direction === 'erp_to_ecom') {
                $query->where('direction', 'erp_to_ecom');
            } elseif ($direction === 'ecom_to_erp') {
                $query->where(function ($q) use ($entityType) {
                    $q->where('direction', 'ecom_to_erp');

                    // Legacy NULL-direction rows apply only to dispatch/customer (matches Field Config UI).
                    if (in_array($entityType, ['dispatch', 'customer'], true)) {
                        $q->orWhereNull('direction');
                    }
                });
            } elseif ($direction) {
                $query->where('direction', $direction);
            }
            
            return $query->get();
        });
    }

    /**
     * Get template-level mappings (for products, customers)
     */
    public function getTemplateMappings(string $entityType, string $ecomDriver, string $erpDriver): \Illuminate\Support\Collection
    {
        return $this->getMappings($entityType, $ecomDriver, $erpDriver, 'template');
    }

    /**
     * Get variant-level mappings (for product variants)
     */
    public function getVariantMappings(string $ecomDriver, string $erpDriver): \Illuminate\Support\Collection
    {
        return $this->getMappings('product', $ecomDriver, $erpDriver, 'variant');
    }

    /**
     * Build Shopify/ecom product payload from ERP template + variants — 100% field config.
     *
     * ecom_field dot paths nest into GraphQL input (metafields.0.key, inventoryItem.sku, …).
     * No hardcoded metafield: prefixes or field-name routing tables.
     *
     * @param  array<string, mixed>  $erpTemplate
     * @param  array<int, array<string, mixed>>  $variants
     * @param  array<int|string, mixed>  $attributeValues
     * @param  array<string, mixed>  $related  Extra Odoo reads kept outside template (e.g. vendors from product.supplierinfo)
     * @return array<string, mixed>
     */
    public function buildErpToEcomProductPayload(
        array $erpTemplate,
        array $variants,
        array $attributeValues,
        ?string $ecomDriver = null,
        ?string $erpDriver = null,
        array $related = []
    ): array {
        $settings   = app(SettingsService::class);
        $ecomDriver = $ecomDriver ?? $settings->ecomDriver();
        $erpDriver  = $erpDriver ?? $settings->erpDriver();

        $configs = $this->getMappings('product', $ecomDriver, $erpDriver, null, 'erp_to_ecom');
        if ($configs->isEmpty()) {
            return [];
        }

        $mappingRoot = $this->erpProductMappingRoot($erpTemplate, $related);

        $templateConfigs = $configs->filter(fn ($c) => ($c->scope ?? '') === 'template');
        $variantConfigs  = $configs->filter(fn ($c) => ($c->scope ?? '') === 'variant');

        $payload = [];
        foreach ($templateConfigs as $config) {
            $this->applyErpToEcomConfig($payload, $erpTemplate, $mappingRoot, $config, $ecomDriver);
        }

        $shopifyVariants = [];
        $variantRows     = $variants !== [] ? $variants : [$erpTemplate];

        foreach ($variantRows as $variant) {
            if (!is_array($variant)) {
                continue;
            }

            $variantContext = array_merge($variant, ['_attribute_values' => $attributeValues]);
            $variantPayload = [];

            foreach ($variantConfigs as $config) {
                if ($this->isVariantInventoryLocationFieldConfig($config)) {
                    continue;
                }

                $this->applyErpToEcomConfig($variantPayload, $variantContext, $mappingRoot, $config, $ecomDriver);
            }

            if ($variantPayload !== []) {
                $shopifyVariants[] = $variantPayload;
            }
        }

        if ($shopifyVariants !== []) {
            $payload['variants'] = $shopifyVariants;

            $options = $this->aggregateProductOptionsFromFieldConfig($shopifyVariants, $variantConfigs);
            if ($options !== []) {
                $payload['options'] = $options;
            }
        }

        $this->assertErpToEcomProductPayload($payload, $templateConfigs, $variantConfigs, $shopifyVariants);

        return $payload;
    }

    /**
     * Dashboard product_cache columns — resolved from active erp→ecom field configs only.
     *
     * @param  array<string, mixed>  $template
     * @param  list<array<string, mixed>>  $variants
     * @return array{name: string, default_code: ?string, barcode: ?string, product_type: ?string, is_active: bool, price: mixed, cost: mixed, weight: mixed, category: ?string}
     */
    public function extractProductCacheDisplay(array $template, array $variants = []): array
    {
        $settings = app(SettingsService::class);
        $configs  = $this->getMappings(
            'product',
            $settings->ecomDriver(),
            $settings->erpDriver(),
            null,
            'erp_to_ecom',
        )->filter(fn (ProductFieldConfig $c) => $c->is_active)->sortBy('sort_order');

        $variant = $variants[0] ?? $template;
        $display = [
            'name'         => '',
            'default_code' => null,
            'barcode'      => null,
            'product_type' => null,
            'is_active'    => true,
            'price'        => null,
            'cost'         => null,
            'weight'       => null,
            'category'     => null,
        ];

        $filled = [];

        foreach ($configs as $config) {
            $column = $this->productCacheColumnKey($config);
            if ($column === null || isset($filled[$column])) {
                continue;
            }

            $source = ($config->scope ?? '') === 'variant' ? $variant : $template;
            $value  = $this->readErpDisplayValue($source, $template, $config, $settings->ecomDriver());

            if ($column === 'is_active') {
                $display['is_active'] = $this->displayIsActiveFromConfig($value, $config);
                $filled[$column]        = true;
                continue;
            }

            if (!$this->shouldIncludeMappedValue($value)) {
                continue;
            }

            $display[$column] = $column === 'name' ? (string) $value : $value;
            if ($column === 'category') {
                $display['product_type'] = is_scalar($value) ? (string) $value : null;
                $filled['product_type']  = true;
            }

            $filled[$column] = true;
        }

        foreach (['default_code', 'barcode', 'product_type', 'category'] as $key) {
            if ($display[$key] !== null && $display[$key] !== '') {
                $display[$key] = (string) $display[$key];
            } else {
                $display[$key] = null;
            }
        }

        return $display;
    }

    /**
     * Map a field config row to a product_cache dashboard column using its configured ecom_field path.
     */
    public function productCacheColumnKey(ProductFieldConfig $config): ?string
    {
        if (trim($config->erp_field ?? $config->odoo_field ?? '') === '') {
            return null;
        }

        $scope = $config->scope ?? '';
        $ecom  = strtolower(preg_replace('/[^a-z0-9.]/', '', trim($config->ecom_field ?? $config->shopify_field ?? '')));

        if ($ecom === '') {
            return null;
        }

        if ($scope === 'template') {
            if ($ecom === 'title' || str_ends_with($ecom, 'title') || $ecom === 'name') {
                return 'name';
            }

            if (str_contains($ecom, 'category') || $ecom === 'producttype') {
                return 'category';
            }

            if ($ecom === 'status' || str_ends_with($ecom, 'status')) {
                return 'is_active';
            }
        }

        if ($scope === 'variant') {
            if (str_contains($ecom, 'sku')) {
                return 'default_code';
            }

            if (str_contains($ecom, 'barcode')) {
                return 'barcode';
            }

            if ($ecom === 'price' || str_ends_with($ecom, 'price')) {
                return 'price';
            }

            if (str_contains($ecom, 'compareatprice')) {
                return 'cost';
            }

            if (str_contains($ecom, 'weight') && !str_contains($ecom, 'weightunit')) {
                return 'weight';
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $root
     */
    private function readErpDisplayValue(
        array $source,
        array $root,
        ProductFieldConfig $config,
        string $ecomDriver,
    ): mixed {
        if (($config->field_type ?? '') === 'custom') {
            return $this->resolveCustomDefaultValue($config);
        }

        $erpField = trim($config->erp_field ?? $config->odoo_field ?? '');
        if ($erpField === '') {
            return null;
        }

        $raw = $this->readSourceField($source, $root, $erpField);
        $raw = $this->conditions->apply($raw, $config->conditions, $config->default_value);

        $value = $this->applySystemTransform(
            $raw,
            $config->transform,
            array_merge($root, $source),
            $ecomDriver,
            'erp_to_ecom',
        );

        $value = $this->shapeEcomOutput($value, $config);

        if (!$this->shouldIncludeMappedValue($value) && ($config->default_value ?? '') !== '') {
            $value = $config->default_value;
        }

        if (is_array($value) && array_key_exists(1, $value)) {
            return $value[1];
        }

        return $value;
    }

    private function displayIsActiveFromConfig(mixed $raw, ProductFieldConfig $config): bool
    {
        if ($raw === null || $raw === '') {
            return true;
        }

        if (!empty($config->conditions)) {
            $mapped = $this->conditions->apply($raw, $config->conditions, $config->default_value);
            if (is_string($mapped)) {
                return strtoupper(trim($mapped)) === 'ACTIVE';
            }

            if (is_bool($mapped)) {
                return $mapped;
            }
        }

        $shaped = $this->shapeEcomOutput($raw, $config);
        if (is_string($shaped)) {
            return strtoupper(trim($shaped)) === 'ACTIVE';
        }

        if (is_bool($shaped)) {
            return $shaped;
        }

        return true;
    }

    /**
     * Location for variant stock comes from Channel Mapping → Warehouse, not product field configs.
     */
    private function isVariantInventoryLocationFieldConfig(ProductFieldConfig $config): bool
    {
        $field = strtolower(trim($config->ecom_field ?? $config->shopify_field ?? ''));

        return in_array($field, [
            'inventoryquantities.locationid',
            'inventory_quantities.location_id',
        ], true);
    }

    /**
     * Lookup context for erp_field dot paths — related data merged only at map time.
     *
     * @param  array<string, mixed>  $related  e.g. ['vendors' => [...]] from product.supplierinfo
     * @return array<string, mixed>
     */
    private function erpProductMappingRoot(array $erpTemplate, array $related): array
    {
        $root = $erpTemplate;

        if (!empty($related['vendors']) && is_array($related['vendors'])) {
            $root['vendors'] = $related['vendors'];
        }

        return $root;
    }

    /**
     * Variant option slots parsed from field-config ecom_field paths ({prefix}.{index}.{leaf}).
     * At each slot, lower sort_order = label leaf, next = value leaf (your two config rows).
     *
     * @return array<int, array{prefix: string, index: int, labelLeaf: string, valueLeaf: string}>
     */
    public function getVariantOptionSlotSpecs(?string $ecomDriver = null, ?string $erpDriver = null): array
    {
        $settings   = app(SettingsService::class);
        $ecomDriver = $ecomDriver ?? $settings->ecomDriver();
        $erpDriver  = $erpDriver ?? $settings->erpDriver();
        $cacheKey   = "{$ecomDriver}:{$erpDriver}";

        if (isset($this->variantOptionSlotSpecsCache[$cacheKey])) {
            return $this->variantOptionSlotSpecsCache[$cacheKey];
        }

        $variantConfigs = $this->getMappings('product', $ecomDriver, $erpDriver, 'variant', 'erp_to_ecom');
        $this->variantOptionSlotSpecsCache[$cacheKey] = $this->parseVariantOptionSlotSpecs($variantConfigs);

        return $this->variantOptionSlotSpecsCache[$cacheKey];
    }

    /** @var array<string, array<int, array{prefix: string, index: int, labelLeaf: string, valueLeaf: string}>> */
    private array $variantOptionSlotSpecsCache = [];

    /**
     * Build productOptions for productOptionsCreate from variant payloads + field config paths.
     *
     * @param  array<int, array<string, mixed>>  $variants
     * @return array<int, array{name: string, values: array<int, string>}>
     */
    public function aggregateProductOptionsFromFieldConfig(
        array $variants,
        \Illuminate\Support\Collection $variantConfigs
    ): array {
        $specs   = $this->parseVariantOptionSlotSpecs($variantConfigs);
        $options = [];

        foreach ($specs as $spec) {
            $path = "{$spec['prefix']}.{$spec['index']}";

            foreach ($variants as $variant) {
                if (!is_array($variant)) {
                    continue;
                }

                $block = $this->fields->get($variant, $path);
                if (!is_array($block)) {
                    continue;
                }

                $optionName = trim((string) ($block[$spec['labelLeaf']] ?? ''));
                $valueName  = trim((string) ($block[$spec['valueLeaf']] ?? ''));

                if ($optionName === '' || $valueName === '') {
                    continue;
                }

                $options[$spec['index']]['name'] ??= $optionName;
                $options[$spec['index']]['values'] ??= [];
                if (!in_array($valueName, $options[$spec['index']]['values'], true)) {
                    $options[$spec['index']]['values'][] = $valueName;
                }
            }
        }

        ksort($options);

        return array_values(array_filter(
            $options,
            fn ($o) => !empty($o['name']) && !empty($o['values'])
        ));
    }

    /**
     * @return array<int, array{prefix: string, index: int, labelLeaf: string, valueLeaf: string}>
     */
    private function parseVariantOptionSlotSpecs(\Illuminate\Support\Collection $variantConfigs): array
    {
        /** @var array<string, list<array{leaf: string, sort_order: int, id: int}>> $slots */
        $slots = [];

        foreach ($variantConfigs as $config) {
            if (!$config->is_active) {
                continue;
            }

            $field = trim($config->ecom_field ?? $config->shopify_field ?? '');
            if (!preg_match('/^(.+)\.(\d+)\.([^.]+)$/', $field, $m)) {
                continue;
            }

            $slotKey = $m[1] . '.' . $m[2];
            $slots[$slotKey][] = [
                'leaf'       => $m[3],
                'sort_order' => (int) ($config->sort_order ?? 0),
                'id'         => (int) $config->id,
            ];
        }

        $specs = [];

        foreach ($slots as $slotKey => $entries) {
            if (count($entries) < 2) {
                continue;
            }

            usort($entries, function (array $a, array $b): int {
                return $a['sort_order'] <=> $b['sort_order'] ?: $a['id'] <=> $b['id'];
            });

            if (!preg_match('/^(.+)\.(\d+)$/', $slotKey, $sm)) {
                continue;
            }

            $specs[] = [
                'prefix'    => $sm[1],
                'index'     => (int) $sm[2],
                'labelLeaf' => $entries[0]['leaf'],
                'valueLeaf' => $entries[1]['leaf'],
            ];
        }

        usort($specs, fn ($a, $b) => $a['index'] <=> $b['index']);

        return $specs;
    }

    /**
     * @return list<string> Unique option container prefixes from field config (e.g. optionValues).
     */
    public function getVariantOptionPrefixes(?string $ecomDriver = null, ?string $erpDriver = null): array
    {
        $prefixes = [];
        foreach ($this->getVariantOptionSlotSpecs($ecomDriver, $erpDriver) as $spec) {
            $prefixes[$spec['prefix']] = true;
        }

        return array_keys($prefixes);
    }

    /**
     * @param  array<string, mixed>  $payload  Flat dot-path keys (ecom_field from config)
     * @param  array<string, mixed>  $source   Row being mapped (template or variant)
     * @param  array<string, mixed>  $root     Full ERP product (fallback context for transforms)
     */
    private function applyErpToEcomConfig(
        array &$payload,
        array $source,
        array $root,
        ProductFieldConfig $config,
        string $ecomDriver
    ): void {
        if (!$config->is_active) {
            return;
        }

        $writePath = $this->resolveEcomWritePath($config);
        if ($writePath === '') {
            return;
        }

        if ($this->isVariantInventoryLocationFieldConfig($config)) {
            return;
        }

        $value = $this->resolveErpToEcomConfigValue($source, $root, $config, $ecomDriver);

        if ($config->field_type === 'custom'
            && $this->customFieldBlankSendsExplicitNull($config)
            && !$this->shouldIncludeMappedValue($value)) {
            $this->fields->set($payload, $writePath, null);

            return;
        }

        if (!$this->shouldIncludeMappedValue($value)) {
            if ($this->erpToEcomMappingIsOptional($config)) {
                return;
            }

            if ($this->erpToEcomStrictMappingEnabled()) {
                $this->throwErpToEcomMappingFailed($config);
            }

            return;
        }

        $this->fields->set($payload, $writePath, $value);
    }

    /**
     * Blank custom (no transform) → explicit null in payload — required for Shopify 2026-04
     * changeFromQuantity and any other wire field where omit ≠ null.
     */
    private function customFieldBlankSendsExplicitNull(ProductFieldConfig $config): bool
    {
        if ($config->field_type !== 'custom' || trim($config->transform ?? '') !== '') {
            return false;
        }

        $default = trim((string) ($config->default_value ?? ''));

        return $default === ''
            || in_array(strtolower($default), ['empty', 'null', 'none'], true);
    }

    /**
     * Payload path from field config — exactly what you enter in the ecom_field form.
     * Product template scope: bare names become product.{name}. Inventory/default: path used as-is.
     */
    public function resolveConfigWritePath(ProductFieldConfig $config): string
    {
        return $this->resolveEcomWritePath($config);
    }

    /** Shopify variant SKU path from active erp→ecom product field config. */
    public function productVariantSkuWritePath(?string $ecomDriver = null, ?string $erpDriver = null): ?string
    {
        return $this->variantEcomWritePathByLeaf('sku', $ecomDriver, $erpDriver);
    }

    /**
     * Top-level GraphQL keys allowed on ProductVariantsBulkInput — from variant field configs only.
     *
     * @return list<string>
     */
    public function variantBulkInputRoots(?string $ecomDriver = null, ?string $erpDriver = null): array
    {
        $settings   = app(SettingsService::class);
        $ecomDriver = $ecomDriver ?? $settings->ecomDriver();
        $erpDriver  = $erpDriver ?? $settings->erpDriver();

        $roots = [];

        foreach ($this->getMappings('product', $ecomDriver, $erpDriver, 'variant', 'erp_to_ecom') as $config) {
            if (!$config->is_active || $this->isVariantInventoryLocationFieldConfig($config)) {
                continue;
            }

            $path = $this->resolveEcomWritePath($config);
            if ($path === '') {
                continue;
            }

            $root = explode('.', $path)[0];
            if ($root !== '') {
                $roots[$root] = true;
            }
        }

        return array_keys($roots);
    }

    /** Find variant write path whose final segment matches $leaf (e.g. sku, unit, value). */
    public function variantEcomWritePathByLeaf(string $leaf, ?string $ecomDriver = null, ?string $erpDriver = null): ?string
    {
        $leaf = strtolower(trim($leaf));
        if ($leaf === '') {
            return null;
        }

        $settings   = app(SettingsService::class);
        $ecomDriver = $ecomDriver ?? $settings->ecomDriver();
        $erpDriver  = $erpDriver ?? $settings->erpDriver();

        foreach ($this->getMappings('product', $ecomDriver, $erpDriver, 'variant', 'erp_to_ecom') as $config) {
            if (!$config->is_active || $this->isVariantInventoryLocationFieldConfig($config)) {
                continue;
            }

            $path = $this->resolveEcomWritePath($config);
            if ($path === '') {
                continue;
            }

            $segments = explode('.', $path);
            if (strtolower((string) end($segments)) === $leaf) {
                return $path;
            }
        }

        return null;
    }

    /** Variant write path containing $needle with optional final segment $leaf. */
    public function variantConfigWritePathContaining(string $needle, ?string $leaf = null): ?string
    {
        $needle = trim($needle);
        if ($needle === '') {
            return null;
        }

        $settings = app(SettingsService::class);

        foreach ($this->getMappings('product', $settings->ecomDriver(), $settings->erpDriver(), 'variant', 'erp_to_ecom') as $config) {
            if (!$config->is_active || $this->isVariantInventoryLocationFieldConfig($config)) {
                continue;
            }

            $path = $this->resolveEcomWritePath($config);
            if ($path === '' || !str_contains($path, $needle)) {
                continue;
            }

            if ($leaf !== null) {
                $segments = explode('.', $path);
                if (strtolower((string) end($segments)) !== strtolower(trim($leaf))) {
                    continue;
                }
            }

            return $path;
        }

        return null;
    }

    public function defaultValueForVariantWritePath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        $settings = app(SettingsService::class);

        foreach ($this->getMappings('product', $settings->ecomDriver(), $settings->erpDriver(), 'variant', 'erp_to_ecom') as $config) {
            if (!$config->is_active) {
                continue;
            }

            if ($this->resolveEcomWritePath($config) !== $path) {
                continue;
            }

            $default = trim((string) ($config->default_value ?? ''));

            return $default !== '' ? $default : null;
        }

        return null;
    }

    private function resolveEcomWritePath(ProductFieldConfig $config): string
    {
        $path = trim($config->ecom_field ?? $config->shopify_field ?? '');
        if ($path === '') {
            return '';
        }

        if (str_contains($path, '.')) {
            return $path;
        }

        // Shopify productCreate: images/variants/options are payload-root keys;
        // images become the separate `media` mutation variable, not product.* fields.
        if (in_array($path, ['images', 'variants', 'options'], true)) {
            return $path;
        }

        if (($config->scope ?? '') === 'template') {
            return 'product.' . $path;
        }

        return $path;
    }

    /** @return 'ecom_to_erp'|'erp_to_ecom' */
    public function configDirection(ProductFieldConfig $config, ?string $override = null): string
    {
        if ($override === 'ecom_to_erp' || $override === 'erp_to_ecom') {
            return $override;
        }

        return ($config->direction ?? '') === 'ecom_to_erp' ? 'ecom_to_erp' : 'erp_to_ecom';
    }

    /**
     * Source field keys for combine rows, direction-aware.
     *
     * @return array{0: string, 1: string}
     */
    public function combineSourceFieldKeys(ProductFieldConfig $config, ?string $direction = null): array
    {
        $direction = $this->configDirection($config, $direction);

        if ($direction === 'ecom_to_erp') {
            return [
                trim($config->ecom_field ?: $config->shopify_field ?: ''),
                trim($config->ecom_field_2 ?: $config->erp_field_2 ?: $config->odoo_field_2 ?: ''),
            ];
        }

        return [
            trim($config->erp_field ?: $config->odoo_field ?: ''),
            trim($config->erp_field_2 ?: $config->odoo_field_2 ?: ''),
        ];
    }

    public function mergeCombinedParts(mixed $val1, mixed $val2, string $separator, ?string $default = null): string
    {
        $s1 = ($val1 === false || $val1 === null) ? '' : trim((string) $val1);
        $s2 = ($val2 === false || $val2 === null) ? '' : trim((string) $val2);
        $sep = $separator !== '' ? $separator : ' ';
        $combined = trim($s1 . ($s1 !== '' && $s2 !== '' ? $sep : '') . $s2);

        if ($combined === '' && $default !== null && $default !== '') {
            return (string) $default;
        }

        return $combined;
    }

    /**
     * Resolve a combine field config value for either sync direction.
     */
    public function resolveCombineValue(
        ProductFieldConfig $config,
        array $source,
        array $root,
        ?string $direction = null
    ): string {
        $direction = $this->configDirection($config, $direction);
        [$field1, $field2] = $this->combineSourceFieldKeys($config, $direction);
        $sep = (string) ($config->combine_separator ?? ' ');

        if ($direction === 'ecom_to_erp') {
            $val1 = $this->readEcomField($source, $root, $field1);
            $val2 = $this->readEcomField($source, $root, $field2);
        } else {
            $val1 = $this->readSourceField($source, $root, $field1);
            $val2 = $this->readSourceField($source, $root, $field2);
        }

        return $this->mergeCombinedParts($val1, $val2, $sep, $config->default_value);
    }

    /** Target ERP field for combine/custom rows (ecom→erp). */
    public function combineErpTargetField(ProductFieldConfig $config): string
    {
        return trim($config->erp_field ?: $config->odoo_field ?: '');
    }

    private function resolveErpToEcomConfigValue(
        array $source,
        array $root,
        ProductFieldConfig $config,
        string $ecomDriver
    ): mixed {
        if ($config->field_type === 'custom') {
            $transform = trim($config->transform ?? '');
            if ($transform !== '' && $transform !== 'skip') {
                $value = $this->applySystemTransform(
                    null,
                    $config->transform,
                    array_merge($root, $source),
                    $ecomDriver,
                    'erp_to_ecom'
                );

                if ($config->default_value === '__NULL__') {
                    return null;
                }

                if ($this->shouldIncludeMappedValue($value)) {
                    return $this->applyLengthConstraints($value, $config);
                }

                // Transform configured but returned nothing — never fall back to placeholder text.
                return null;
            }

            return $this->resolveCustomDefaultValue($config);
        }

        if ($config->field_type === 'combine') {
            $value = $this->resolveCombineValue($config, $source, $root, 'erp_to_ecom');
            $value = $this->conditions->apply($value, $config->conditions, $config->default_value);
            $value = $this->applySystemTransform(
                $value,
                $config->transform,
                array_merge($root, $source),
                $ecomDriver,
                'erp_to_ecom'
            );
            $value = $this->shapeEcomOutput($value, $config);
            if ($value === null || $value === '') {
                $value = $config->default_value;
            }

            return $this->applyLengthConstraints($value, $config);
        }

        $erpField = $config->erp_field ?? $config->odoo_field ?? '';
        $raw      = $this->readSourceField($source, $root, $erpField);

        $raw = $this->conditions->apply($raw, $config->conditions, $config->default_value);

        $value = $this->applySystemTransform(
            $raw,
            $config->transform,
            array_merge($root, $source),
            $ecomDriver,
            'erp_to_ecom'
        );

        $value = $this->shapeEcomOutput($value, $config);

        if ($value === null || $value === '') {
            $value = $config->default_value;
        }

        return $this->applyLengthConstraints($value, $config);
    }

    /** Read erp_field dot path from scope row, then root product. */
    private function readSourceField(array $source, array $root, string $key): mixed
    {
        if ($key === '') {
            return null;
        }

        $value = $this->fields->get($source, $key);
        if ($value !== null || $source === $root) {
            return $value;
        }

        return $this->fields->get($root, $key);
    }

    /**
     * Build ecommerce payload from ERP data
     * 
     * @param array $erpData Normalized ERP data
     * @param string $ecomDriver Target ecommerce platform
     * @param string $erpDriver Source ERP system
     * @param string $scope 'template' or 'variant'
     * @return array Ecommerce-ready payload
     */
    public function buildEcomPayload(
        array $erpData,
        string $ecomDriver,
        string $erpDriver,
        string $scope = 'template',
        string $entityType = 'product',
        ?array $rootOverride = null
    ): array {
        $mappings = $this->getMappings($entityType, $ecomDriver, $erpDriver, $scope, 'erp_to_ecom');
        $payload  = [];
        $root     = $rootOverride ?? $erpData;

        foreach ($mappings as $mapping) {
            $this->applyErpToEcomConfig($payload, $erpData, $root, $mapping, $ecomDriver);
        }

        return $payload;
    }

    /**
     * Extract ERP data from ecommerce payload
     * 
     * @param array $ecomData Ecommerce platform data
     * @param string $ecomDriver Source ecommerce platform
     * @param string $erpDriver Target ERP system
     * @param string $scope 'template' or 'variant'
     * @return array ERP-ready data
     */
    public function extractErpData(
        array $ecomData,
        string $ecomDriver,
        string $erpDriver,
        string $scope = 'template'
    ): array {
        $mappings = $this->getMappings('product', $ecomDriver, $erpDriver, $scope);
        $erpData = [];
        
        foreach ($mappings as $mapping) {
            $ecomField = $mapping->ecom_field;
            $erpField = $mapping->erp_field;
            
            // Skip if no ERP field (ecom-only field)
            if (empty($erpField)) {
                continue;
            }
            
            // Get value from ecom data
            $value = $ecomData[$ecomField] ?? null;

            if ($value !== null && !empty($mapping->conditions)) {
                $value = $this->conditions->apply($value, $mapping->conditions);
            }

            if ($value !== null && self::effectiveSystemTransform($mapping->transform, $mapping->reverse_transform)) {
                $value = $this->applySystemTransform(
                    $value,
                    self::effectiveSystemTransform($mapping->transform, $mapping->reverse_transform),
                    $ecomData,
                    $ecomDriver,
                    'ecom_to_erp'
                );
            }

            $value = $this->shapeErpOutput($value, $mapping);
            
            $erpData[$erpField] = $value;
        }
        
        return $erpData;
    }

    /**
     * Build a complete ERP payload from a raw ecom entity, driven entirely by
     * product_field_configs rows where direction = 'ecom_to_erp'.
     *
     * Mirror of ShopifyProductService::buildPayload() + resolveValue():
     * reads ecom_field (dot paths, arrays), applies conditions, writes erp_field.
     */
    public function buildErpProductPayload(
        array $ecomProduct,
        string $ecomDriver,
        string $erpDriver,
        string $entityType = 'product'
    ): array {
        $configs = $this->getMappings($entityType, $ecomDriver, $erpDriver, null, 'ecom_to_erp');

        if ($configs->isEmpty()) {
            return [];
        }

        $erp           = $this->resolveErpAdapter($erpDriver);
        $firstVariant  = $this->firstScopedLine($ecomProduct);
        $templateConfigs = $configs->filter(fn ($c) => $c->scope === 'template');
        $variantConfigs  = $configs->filter(fn ($c) => $c->scope === 'variant');
        $otherConfigs    = $configs->filter(fn ($c) => !in_array($c->scope, ['template', 'variant'], true));

        $payload = [];

        foreach ($templateConfigs as $config) {
            $this->applyEcomToErpConfig($payload, $ecomProduct, $ecomProduct, $config, $erp, $ecomDriver);
        }

        foreach ($variantConfigs as $config) {
            $this->applyEcomToErpConfig($payload, $firstVariant, $ecomProduct, $config, $erp, $ecomDriver);
        }

        foreach ($otherConfigs as $config) {
            $source = ($config->scope === 'variant') ? $firstVariant : $ecomProduct;
            $this->applyEcomToErpConfig($payload, $source, $ecomProduct, $config, $erp, $ecomDriver);
        }

        $this->assertEcomToErpProductPayload($payload, $configs, $ecomProduct);

        return $payload;
    }

    /**
     * Dashboard cache columns from ecom→erp field configs (fetched Shopify JSON).
     *
     * @return array{name: ?string, default_code: ?string}
     */
    public function extractEcomToErpProductCacheDisplay(array $ecomProduct): array
    {
        $settings = app(SettingsService::class);
        $configs  = $this->getMappings(
            'product',
            $settings->ecomDriver(),
            $settings->erpDriver(),
            null,
            'ecom_to_erp',
        )->filter(fn (ProductFieldConfig $c) => $c->is_active);

        $variant = $this->firstScopedLine($ecomProduct);
        $display = ['name' => null, 'default_code' => null];

        foreach ($configs as $config) {
            $erpField = strtolower(trim($config->erp_field ?? $config->odoo_field ?? ''));
            $source   = ($config->scope ?? '') === 'variant' ? $variant : $ecomProduct;
            $value    = $this->resolveEcomConfigValue($source, $ecomProduct, $config, $this->resolveErpAdapter($settings->erpDriver()), $settings->ecomDriver());

            if (!$this->shouldIncludeMappedValue($value)) {
                continue;
            }

            if ($display['name'] === null && in_array($erpField, ['item_name', 'name'], true)) {
                $display['name'] = (string) $value;
            }

            if ($display['default_code'] === null && in_array($erpField, ['item_code', 'default_code', 'sku'], true)) {
                $display['default_code'] = (string) $value;
            }
        }

        if ($display['name'] === null) {
            $display['name'] = $ecomProduct['title'] ?? $ecomProduct['name'] ?? null;
        }

        if ($display['default_code'] === null && $variant !== []) {
            $display['default_code'] = $variant['sku'] ?? null;
        }

        return $display;
    }

    /**
     * Ecom → ERP customer payload from product_field_configs (scope=default).
     */
    public function buildErpCustomerPayload(
        array $ecomCustomer,
        string $ecomDriver,
        string $erpDriver
    ): array {
        $configs = $this->getMappings('customer', $ecomDriver, $erpDriver, 'default', 'ecom_to_erp');

        if ($configs->isEmpty()) {
            $configs = $this->getMappings('customer', $ecomDriver, $erpDriver, 'header', 'ecom_to_erp');
        }

        if ($configs->isEmpty()) {
            return [];
        }

        $erp     = $this->resolveErpAdapter($erpDriver);
        $payload = [];

        foreach ($configs as $config) {
            $this->applyEcomToErpConfig($payload, $ecomCustomer, $ecomCustomer, $config, $erp, $ecomDriver);
        }

        return $this->enrichCustomerErpPayload($payload, $ecomCustomer, $configs);
    }

    /**
     * Build Ecom→ERP payload from an arbitrary config collection (orders, dispatch, etc.).
     *
     * @param  \Illuminate\Support\Collection<int, ProductFieldConfig>  $configs
     * @return array<string, mixed>
     */
    public function buildGenericEcomToErpPayload(
        \Illuminate\Support\Collection $configs,
        array $source,
        array $rootEntity,
        string $ecomDriver,
        string $erpDriver
    ): array {
        if ($configs->isEmpty()) {
            return [];
        }

        $erp     = $this->resolveErpAdapter($erpDriver);
        $payload = [];

        foreach ($configs as $config) {
            $this->applyEcomToErpConfig($payload, $source, $rootEntity, $config, $erp, $ecomDriver);
        }

        return $payload;
    }

    /**
     * Custom field-config enrichment for ERP writes (company, cost center, …).
     * Uses active custom rows with system transforms and ecom_field slots like _company.
     *
     * @return array<string, mixed>
     */
    public function buildErpEnrichmentPayload(
        string $entityType,
        ?string $scope,
        array $context,
        ?string $ecomDriver = null,
        ?string $erpDriver = null,
        string $direction = 'ecom_to_erp',
    ): array {
        $settings   = app(SettingsService::class);
        $ecomDriver = $ecomDriver ?? $settings->ecomDriver();
        $erpDriver  = $erpDriver ?? $settings->erpDriver();
        $erp        = $this->resolveErpAdapter($erpDriver);

        $payload = [];

        foreach ($this->getMappings($entityType, $ecomDriver, $erpDriver, $scope, $direction) as $config) {
            if (!$config->is_active || ($config->field_type ?? '') !== 'custom') {
                continue;
            }

            $erpField = trim((string) ($config->erp_field ?: $config->odoo_field ?: ''));
            if ($erpField === '') {
                continue;
            }

            $transform = self::effectiveSystemTransform($config->transform, $config->reverse_transform);

            if ($transform === null) {
                $value = $config->default_value;
            } else {
                $value = $this->applySystemTransform(
                    $config->default_value,
                    $transform,
                    $context,
                    $ecomDriver,
                    $direction,
                    $erp,
                );
            }

            if (!$this->shouldIncludeMappedValue($value)) {
                continue;
            }

            $writeKey = preg_match('/^(.+)\.(\d+)$/', $erpField, $m) ? $m[1] : $erpField;
            $value    = $erp->prepareProductWriteValue($writeKey, $value);
            if ($value === null || $value === '') {
                continue;
            }

            $this->assignErpPayloadField($payload, $erpField, $value);
        }

        return $payload;
    }

    /**
     * Company from active custom header field config → company Default (no transform).
     */
    public function configuredCompany(
        string $entityType,
        ?string $ecomDriver = null,
        ?string $erpDriver = null,
        string $direction = 'ecom_to_erp',
    ): string {
        $value = $this->findConfiguredCompanyDefault($entityType, $ecomDriver, $erpDriver, $direction);
        if ($value !== null) {
            return $value;
        }

        throw new \RuntimeException(
            "Set {$entityType} Field Config → header → custom → ERP field company → Default "
            . 'to your ERPNext company name (e.g. imminent (Demo)). Leave Transform empty, then Save again.'
        );
    }

    private function findConfiguredCompanyDefault(
        string $entityType,
        ?string $ecomDriver = null,
        ?string $erpDriver = null,
        string $direction = 'ecom_to_erp',
    ): ?string {
        $settings   = app(SettingsService::class);
        $ecomDriver = $ecomDriver ?? $settings->ecomDriver();
        $erpDriver  = $erpDriver ?? $settings->erpDriver();

        $hasCompanyRow = false;
        $hasTransform  = false;

        foreach ($this->getMappings($entityType, $ecomDriver, $erpDriver, 'header', $direction) as $config) {
            if (!$config->is_active || ($config->field_type ?? '') !== 'custom') {
                continue;
            }

            $erpField = strtolower(trim(explode('.', (string) ($config->erp_field ?: $config->odoo_field ?: ''))[0]));
            if ($erpField !== 'company') {
                continue;
            }

            $hasCompanyRow = true;
            $transform     = self::effectiveSystemTransform($config->transform, $config->reverse_transform);
            if ($transform !== null) {
                $hasTransform = true;
            }

            $default = trim((string) ($config->default_value ?? ''));
            if ($default !== '' && $transform === null) {
                return $default;
            }
        }

        if ($hasCompanyRow && $hasTransform) {
            throw new \RuntimeException(
                "Clear Transform on {$entityType} Field Config → header → company row and set Default "
                . 'to your ERPNext company name (e.g. imminent (Demo)), then Save.'
            );
        }

        return null;
    }

    /**
     * Literal default_value on an active custom field config row.
     */
    public function configuredCustomDefault(
        string $entityType,
        ?string $scope,
        string $erpFieldRoot,
        ?string $ecomField = null,
        ?string $ecomDriver = null,
        ?string $erpDriver = null,
        string $direction = 'ecom_to_erp',
    ): ?string {
        $settings   = app(SettingsService::class);
        $ecomDriver = $ecomDriver ?? $settings->ecomDriver();
        $erpDriver  = $erpDriver ?? $settings->erpDriver();
        $erpRoot    = trim($erpFieldRoot);
        $ecomSlot   = $ecomField !== null ? trim($ecomField) : null;

        foreach ($this->getMappings($entityType, $ecomDriver, $erpDriver, $scope, $direction) as $config) {
            if (!$config->is_active || ($config->field_type ?? '') !== 'custom') {
                continue;
            }

            $field = trim((string) ($config->erp_field ?: $config->odoo_field ?: ''));
            if ($field === '' || explode('.', $field)[0] !== $erpRoot) {
                continue;
            }

            if ($ecomSlot !== null && trim((string) ($config->ecom_field ?? '')) !== $ecomSlot) {
                continue;
            }

            if (self::effectiveSystemTransform($config->transform, $config->reverse_transform) !== null) {
                continue;
            }

            $default = trim((string) ($config->default_value ?? ''));

            return $default !== '' ? $default : null;
        }

        return null;
    }

    /**
     * erp_field root for an active channel_map transform (e.g. warehouse on sales_order line).
     *
     * @param  list<string>  $entityTypes
     */
    public function erpFieldForChannelMap(
        string $mapType,
        array $entityTypes,
        ?string $scope = null,
        ?string $ecomDriver = null,
        ?string $erpDriver = null,
        string $direction = 'ecom_to_erp',
    ): ?string {
        $settings   = app(SettingsService::class);
        $ecomDriver = $ecomDriver ?? $settings->ecomDriver();
        $erpDriver  = $erpDriver ?? $settings->erpDriver();
        $transform  = 'channel_map:' . strtolower(trim($mapType));
        $scopes     = $scope !== null ? [$scope] : ['line', 'header', 'default'];

        foreach ($entityTypes as $entityType) {
            foreach ($scopes as $sc) {
                foreach ($this->getMappings($entityType, $ecomDriver, $erpDriver, $sc, $direction) as $config) {
                    if (!$config->is_active) {
                        continue;
                    }

                    if (self::effectiveSystemTransform($config->transform, $config->reverse_transform) !== $transform) {
                        continue;
                    }

                    $field = trim((string) ($config->erp_field ?: $config->odoo_field ?: ''));
                    if ($field !== '') {
                        return explode('.', $field)[0];
                    }
                }
            }
        }

        return null;
    }

    /**
     * ERP → Ecom inventory payload — same rules as product: ecom_field path, erp_field source.
     *
     * @param  array<string, mixed>  $quant  Normalized Odoo stock.quant row
     * @return array<string, mixed>
     */
    public function buildErpToEcomInventoryPayload(
        array $quant,
        ?string $ecomDriver = null,
        ?string $erpDriver = null,
        array $pushContext = []
    ): array {
        $settings   = app(SettingsService::class);
        $ecomDriver = $ecomDriver ?? $settings->ecomDriver();
        $erpDriver  = $erpDriver ?? $settings->erpDriver();

        $root = array_merge($quant, $pushContext);

        return $this->buildEcomPayload($quant, $ecomDriver, $erpDriver, 'default', 'inventory', $root);
    }

    /**
     * Active inventory erp→ecom field configs for GraphQL wire building.
     *
     * @return \Illuminate\Support\Collection<int, ProductFieldConfig>
     */
    public function getInventoryErpToEcomConfigs(?string $ecomDriver = null, ?string $erpDriver = null): \Illuminate\Support\Collection
    {
        $settings   = app(SettingsService::class);
        $ecomDriver = $ecomDriver ?? $settings->ecomDriver();
        $erpDriver  = $erpDriver ?? $settings->erpDriver();

        return $this->getMappings('inventory', $ecomDriver, $erpDriver, 'default', 'erp_to_ecom');
    }

    /**
     * Build a synthetic Odoo quant row for non-inventory callers (e.g. product variant sync).
     * Values are assigned only via active inventory field configs (erp_field + transform).
     *
     * @return array<string, mixed>
     */
    public function buildSyntheticInventoryQuant(int $quantity, string $locationNumericId): array
    {
        $quant = [];

        foreach ($this->getInventoryErpToEcomConfigs() as $config) {
            $erpField = trim($config->erp_field ?? '');
            if ($erpField === '' || $config->field_type === 'custom') {
                continue;
            }

            $transform = self::effectiveSystemTransform($config->transform, null);
            if ($transform === 'channel_map:warehouse') {
                $quant[$erpField] = $locationNumericId;
                continue;
            }

            if (!array_key_exists($erpField, $quant)) {
                $quant[$erpField] = $quantity;
            }
        }

        return $quant;
    }

    /**
     * Ecom → ERP inventory payload — flat erp_field keys (product_id, quantity, location_id, …).
     * Warehouse mapping via field config transform (e.g. channel_map:warehouse).
     *
     * @param  array<string, mixed>  $level  Normalized Shopify inventory level row
     * @return array<string, mixed>
     */
    public function buildEcomToErpInventoryPayload(
        array $level,
        ?string $ecomDriver = null,
        ?string $erpDriver = null
    ): array {
        $settings   = app(SettingsService::class);
        $ecomDriver = $ecomDriver ?? $settings->ecomDriver();
        $erpDriver  = $erpDriver ?? $settings->erpDriver();
        $erp        = app(ErpInterface::class);

        $configs = $this->getMappings('inventory', $ecomDriver, $erpDriver, 'default', 'ecom_to_erp');

        if ($configs->isEmpty()) {
            return [];
        }

        $level = $this->enrichEcomPayloadForMapping($level);

        $payload = [];

        foreach ($configs as $config) {
            if (empty($config->erp_field)) {
                continue;
            }

            $this->applyEcomToErpConfig($payload, $level, $level, $config, $erp, $ecomDriver);
        }

        return $payload;
    }

    /**
     * Active inventory ecom→erp field configs for Odoo wire building.
     *
     * @return \Illuminate\Support\Collection<int, ProductFieldConfig>
     */
    public function getInventoryEcomToErpConfigs(?string $ecomDriver = null, ?string $erpDriver = null): \Illuminate\Support\Collection
    {
        $settings   = app(SettingsService::class);
        $ecomDriver = $ecomDriver ?? $settings->ecomDriver();
        $erpDriver  = $erpDriver ?? $settings->erpDriver();

        return $this->getMappings('inventory', $ecomDriver, $erpDriver, 'default', 'ecom_to_erp');
    }

    /**
     * ERP → Ecom customer payload — keys match Shopify GraphQL CustomerInput paths.
     */
    public function buildErpToEcomCustomerPayload(
        array $erpPartner,
        ?string $ecomDriver = null,
        ?string $erpDriver = null
    ): array {
        $settings   = app(SettingsService::class);
        $ecomDriver = $ecomDriver ?? $settings->ecomDriver();
        $erpDriver  = $erpDriver ?? $settings->erpDriver();

        $payload = [];

        foreach (['default', 'address', 'contact'] as $scope) {
            $configs = $this->getMappings('customer', $ecomDriver, $erpDriver, $scope, 'erp_to_ecom');

            if ($configs->isEmpty()) {
                continue;
            }

            $source = match ($scope) {
                'address' => is_array($erpPartner['_address'] ?? null) ? $erpPartner['_address'] : [],
                'contact' => is_array($erpPartner['_contact'] ?? null) ? $erpPartner['_contact'] : [],
                default   => $erpPartner,
            };

            if ($scope !== 'default' && $source === []) {
                continue;
            }

            foreach ($configs as $config) {
                $this->applyErpToEcomConfig($payload, $source, $erpPartner, $config, $ecomDriver);
            }
        }

        if ($payload === []) {
            return [];
        }

        return $this->fillErpToEcomCustomerGaps($payload, $erpPartner);
    }

    /**
     * Fill top-level Shopify customer fields from linked Address/Contact when Customer doc is sparse.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $erpPartner
     * @return array<string, mixed>
     */
    private function fillErpToEcomCustomerGaps(array $payload, array $erpPartner): array
    {
        $address = is_array($erpPartner['_address'] ?? null) ? $erpPartner['_address'] : [];
        $contact = is_array($erpPartner['_contact'] ?? null) ? $erpPartner['_contact'] : [];

        if (empty($payload['email'])) {
            foreach ([$erpPartner['email_id'] ?? null, $address['email_id'] ?? null, $contact['email_id'] ?? null] as $email) {
                if ($email !== null && $email !== '') {
                    $payload['email'] = (string) $email;
                    break;
                }
            }
        }

        if (empty($payload['phone'])) {
            foreach ([$contact['mobile_no'] ?? null, $contact['phone'] ?? null, $address['phone'] ?? null] as $phone) {
                if ($phone !== null && $phone !== '') {
                    $payload['phone'] = (string) $phone;
                    break;
                }
            }
        }

        if (empty($payload['lastName']) && !empty($contact['last_name'])) {
            $payload['lastName'] = (string) $contact['last_name'];
        }

        return $payload;
    }

    /**
     * Fill gaps only for ERP fields that active configs map — no hardcoded defaults.
     *
     * @param  \Illuminate\Support\Collection<int, ProductFieldConfig>  $configs
     */
    public function enrichCustomerErpPayload(
        array $payload,
        array $ecomData,
        \Illuminate\Support\Collection $configs
    ): array {
        if ($this->erpToEcomStrictMappingEnabled()) {
            return $payload;
        }

        $mappedErpFields = $configs
            ->map(fn ($config) => $config->erp_field ?: $config->odoo_field ?: '')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (in_array('name', $mappedErpFields, true) && empty($payload['name'])) {
            $first = $this->readEcomField($ecomData, $ecomData, 'firstName')
                ?? $this->readEcomField($ecomData, $ecomData, 'first_name');
            $last  = $this->readEcomField($ecomData, $ecomData, 'lastName')
                ?? $this->readEcomField($ecomData, $ecomData, 'last_name');
            $name  = trim(trim((string) ($first ?? '')) . ' ' . trim((string) ($last ?? '')));

            if ($name !== '') {
                $payload['name'] = $name;
            } elseif (!empty($ecomData['email'])) {
                $payload['name'] = (string) $ecomData['email'];
            }
        }

        if (in_array('email', $mappedErpFields, true) && empty($payload['email'])) {
            $email = $this->readEcomField($ecomData, $ecomData, 'email');
            if ($email !== null && $email !== '') {
                $payload['email'] = (string) $email;
            }
        }

        return $payload;
    }

    /**
     * Ecom → ERP customer payload for a specific scope (default, address, contact).
     */
    public function buildErpCustomerScopedPayload(
        array $ecomCustomer,
        string $scope,
        ?string $ecomDriver = null,
        ?string $erpDriver = null
    ): array {
        $settings   = app(SettingsService::class);
        $ecomDriver = $ecomDriver ?? $settings->ecomDriver();
        $erpDriver  = $erpDriver ?? $settings->erpDriver();

        $configs = $this->getMappings('customer', $ecomDriver, $erpDriver, $scope, 'ecom_to_erp');

        if ($configs->isEmpty()) {
            return [];
        }

        $erp     = $this->resolveErpAdapter($erpDriver);
        $payload = [];

        foreach ($configs as $config) {
            $this->applyEcomToErpConfig($payload, $ecomCustomer, $ecomCustomer, $config, $erp, $ecomDriver);
        }

        if ($scope === 'default') {
            return $this->enrichCustomerErpPayload($payload, $ecomCustomer, $configs);
        }

        return $payload;
    }

    private function resolveErpAdapter(string $erpDriver): ErpInterface
    {
        $active = app(SettingsService::class)->erpDriver();
        if ($erpDriver === $active) {
            return app(ErpInterface::class);
        }

        $class = app(ConnectorRegistry::class)->adapterClass($erpDriver);
        if (!$class || !is_subclass_of($class, ErpInterface::class)) {
            throw new \RuntimeException("No ERP adapter registered for driver [{$erpDriver}].");
        }

        return app($class);
    }

    /** First line/variant row — common container keys across ecom drivers. */
    private function firstScopedLine(array $entity): array
    {
        foreach (['variants', 'items', 'lines', 'skus'] as $key) {
            $line = $entity[$key][0] ?? null;
            if (is_array($line)) {
                return $line;
            }
        }

        return [];
    }

    private function applyEcomToErpConfig(
        array &$payload,
        array $source,
        array $rootEntity,
        ProductFieldConfig $config,
        ErpInterface $erp,
        string $ecomDriver
    ): void {
        if (!$config->is_active) {
            return;
        }

        $rawErpField = $config->erp_field ?: $config->odoo_field;
        if (empty($rawErpField)) {
            return;
        }

        $this->assertEcomFieldConfigPath($source, $rootEntity, $config);

        $value = $this->resolveEcomConfigValue($source, $rootEntity, $config, $erp, $ecomDriver);

        if ($config->field_type === 'custom' && !$this->shouldIncludeMappedValue($value)) {
            if ($this->customFieldBlankSendsExplicitNull($config)) {
                $this->assignErpPayloadField($payload, $rawErpField, null);
            }

            return;
        }

        if (!$this->shouldIncludeMappedValue($value)) {
            if ($this->ecomToErpStrictMappingEnabled() && !$this->ecomToErpMappingIsOptional($config)) {
                $this->throwEcomToErpMappingFailed($config, 'mapped value empty after read/transform');
            }

            return;
        }

        $writeKey = preg_match('/^(.+)\.(\d+)$/', $rawErpField, $m) ? $m[1] : $rawErpField;

        $value = $erp->prepareProductWriteValue($writeKey, $value);
        if ($value === null) {
            if ($this->ecomToErpStrictMappingEnabled() && !$this->ecomToErpMappingIsOptional($config)) {
                $this->throwEcomToErpMappingFailed($config, 'ERP rejected prepared value');
            }

            return;
        }

        $this->assignErpPayloadField($payload, $rawErpField, $value);
    }

    private function resolveEcomConfigValue(
        array $source,
        array $rootEntity,
        ProductFieldConfig $config,
        ErpInterface $erp,
        string $ecomDriver
    ): mixed {
        if ($config->field_type === 'custom') {
            return $config->default_value;
        }

        if ($config->field_type === 'combine') {
            $value = $this->resolveCombineValue($config, $source, $rootEntity, 'ecom_to_erp');
            $value = $this->conditions->apply($value, $config->conditions, $config->default_value);
            $value = $this->applySystemTransform(
                $value,
                self::effectiveSystemTransform($config->transform, $config->reverse_transform),
                $rootEntity,
                $ecomDriver,
                'ecom_to_erp',
                $erp
            );
            $value = $this->shapeErpOutput($value, $config);
            $value = $this->resolveMany2OneFromEcomLabel(
                $config->erp_field ?: $config->odoo_field ?: '',
                $value,
                $ecomDriver
            );
            if ($value === null || $value === '' || $value === false) {
                $value = $config->default_value;
            }

            return $this->applyLengthConstraints($this->normalizeScalarEcomValue($value, $config), $config);
        }

        $ecomField = $config->ecom_field ?: $config->shopify_field ?: '';
        $raw       = $this->readEcomField($source, $rootEntity, $ecomField);
        if ($raw === false) {
            $raw = null;
        }

        $raw = $this->conditions->apply($raw, $config->conditions, $config->default_value);

        $value = $this->applySystemTransform(
            $raw,
            self::effectiveSystemTransform($config->transform, $config->reverse_transform),
            $rootEntity,
            $ecomDriver,
            'ecom_to_erp',
            $erp
        );

        $erpField = trim($config->erp_field ?: $config->odoo_field ?: '');
        if (self::isInventoryLocationErpField($erpField)
            && ($value === null || $value === '' || $this->looksLikeShopifyLocationId($value))) {
            $shopifyLoc = $this->warehouseChannelMapLookupValue($raw ?? $value);
            if ($shopifyLoc !== '') {
                $resolved = app(ChannelMappingService::class)->resolveWarehouseOdooIdForShopifyLocation($shopifyLoc);
                if ($resolved !== null && $resolved !== '') {
                    $value = is_numeric($resolved) ? (int) $resolved : $resolved;
                }
            }
        }

        $value = $this->shapeErpOutput($value, $config);

        $value = $this->resolveMany2OneFromEcomLabel(
            $config->erp_field ?: $config->odoo_field ?: '',
            $value,
            $ecomDriver
        );

        if ($value === null || $value === '' || $value === false) {
            $value = $config->default_value;
        }

        return $this->applyLengthConstraints($this->normalizeScalarEcomValue($value, $config), $config);
    }

    /**
     * Ecom list fields (tags, etc.) → string for scalar ERP fields.
     * Multi-relation fields (*_ids) keep arrays for the ERP adapter to validate.
     */
    private function normalizeScalarEcomValue(mixed $value, ProductFieldConfig $config): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $erpField = $config->erp_field ?: $config->odoo_field ?: '';
        if ($this->looksLikeMultiRelationField($erpField)) {
            return $value;
        }

        $parts = array_values(array_filter(array_map(
            fn ($v) => trim(is_scalar($v) ? (string) $v : ''),
            $value
        )));

        return implode(', ', $parts);
    }

    /** ERP convention for many-relation fields (Odoo *_ids, etc.). */
    private function looksLikeMultiRelationField(string $field): bool
    {
        return str_ends_with($field, '_ids');
    }

    /**
     * Read an ecom field from scope source.
     *
     * Fallback order:
     * 1. Current scope (template row → product root, variant row → variants[0])
     * 2. Opposite container (variant scope → product root for shared fields)
     * 3. First variant line when reading from product root (Shopify sku/price/barcode live on variants)
     */
    /**
     * Merge fetched GraphQL blob into payload so ecom_field dot paths resolve (all entity types).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function enrichEcomPayloadForMapping(array $payload): array
    {
        $raw = $payload['_ecom_graphql_raw'] ?? null;

        if (!is_array($raw)) {
            return $payload;
        }

        return array_replace_recursive($payload, $raw);
    }

    private function readEcomField(array $source, array $rootEntity, string $key): mixed
    {
        if ($key === '') {
            return null;
        }

        $source     = $this->enrichEcomPayloadForMapping($source);
        $rootEntity = $this->enrichEcomPayloadForMapping($rootEntity);

        if (preg_match('/^metafields\.([^.]+)\.([^.]+)$/', $key, $m)) {
            $byKey = $this->readMetafieldValue($rootEntity, $m[1], $m[2]);
            if ($byKey !== null) {
                return $byKey;
            }
        }

        foreach ($this->ecomFieldLookupKeys($key) as $lookupKey) {
            $raw = $this->readNestedField($source, $lookupKey);
            if ($raw !== null) {
                return $raw;
            }

            if ($source !== $rootEntity) {
                $raw = $this->readNestedField($rootEntity, $lookupKey);
                if ($raw !== null) {
                    return $raw;
                }
            }

            $line = $this->firstScopedLine($rootEntity);
            if ($line !== []) {
                $fromLine = $this->readNestedField($line, $lookupKey);
                if ($fromLine !== null) {
                    return $fromLine;
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed> $rootEntity */
    private function readMetafieldValue(array $rootEntity, string $namespace, string $metaKey): mixed
    {
        $items = $rootEntity['metafields'] ?? null;

        if (!is_array($items) || $items === []) {
            return null;
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (($item['namespace'] ?? '') === $namespace && ($item['key'] ?? '') === $metaKey) {
                $value = $item['value'] ?? null;

                return ($value === false) ? null : $value;
            }
        }

        return null;
    }

    /**
     * Paths from product_field_configs.ecom_field — exact path first, then generic
     * snake_case ↔ camelCase per segment only. No entity-specific alias tables.
     *
     * @return list<string>
     */
    private function ecomFieldLookupKeys(string $key): array
    {
        if ($key === '') {
            return [];
        }

        $variants = [$key];

        if (str_contains($key, '.')) {
            $variants[] = $this->ecomPathWithSegmentCasing($key, 'camel');
            $variants[] = $this->ecomPathWithSegmentCasing($key, 'snake');
        } else {
            $variants[] = $this->segmentToCamelCase($key);
            $variants[] = $this->segmentToSnakeCase($key);
        }

        return array_values(array_unique(array_filter($variants, fn ($v) => $v !== '')));
    }

    /**
     * Build a Shopify Admin GraphQL fragment from configured ecom_field dot paths.
     * No entity-specific field lists — paths come from product_field_configs only.
     */
    public function buildShopifyGraphqlFragment(
        string $entityType,
        string $ecomDriver,
        string $erpDriver,
        string $graphqlType = 'Order'
    ): string {
          // Fetch reads ecom JSON — use ecom→erp (+ fetch-only skip rows), not erp→ecom push aliases.
        $configs = $this->getMappings($entityType, $ecomDriver, $erpDriver, null, 'ecom_to_erp');

        $lineContainerRoot = null;
        foreach ($configs as $config) {
            if (self::effectiveSystemTransform($config->transform, $config->reverse_transform) !== 'line_container') {
                continue;
            }

            $root = trim(explode('.', (string) ($config->ecom_field ?? ''))[0]);
            if ($root !== '') {
                $lineContainerRoot = $this->graphqlPathSegment($root);
            }
        }

        $orderTree = [];
        $lineTree  = [];

        foreach ($configs as $config) {
            $transform = self::effectiveSystemTransform($config->transform, $config->reverse_transform);
            if ($transform === 'line_container') {
                continue;
            }

            $path = trim((string) ($config->ecom_field ?? ''));
            if ($path === '') {
                continue;
            }

            $scope    = (string) ($config->scope ?? '');
            $path     = $this->normalizeShopifyGraphqlFetchPath($path, $scope);
            $segments = array_map(
                fn (string $segment) => $this->graphqlPathSegment($segment),
                array_filter(explode('.', $path), fn (string $s) => $s !== '')
            );

            if ($segments === []) {
                continue;
            }

            $root = $segments[0];

            if ($scope === 'line') {
                $this->mergeGraphqlPathTree($lineTree, $segments);
                continue;
            }

            if ($lineContainerRoot !== null && $root === $lineContainerRoot) {
                if (count($segments) > 1) {
                    $this->mergeGraphqlPathTree($lineTree, array_slice($segments, 1));
                }
                continue;
            }

            $this->mergeGraphqlPathTree($orderTree, $segments);
        }

        $this->mergeGraphqlPathTree($orderTree, ['id']);
        $this->mergeGraphqlPathTree($orderTree, ['updatedAt']);

        // LineItem.sku + product.id — sku works when variant is null; product.id for sync mapping lookup.
        if ($lineTree !== []) {
            $this->mergeGraphqlPathTree($lineTree, ['sku']);
            $this->mergeGraphqlPathTree($lineTree, ['product', 'id']);
        }

        if ($lineTree !== [] && $lineContainerRoot !== null) {
            $orderTree[$lineContainerRoot] = $lineTree;
        }

        $selection = $this->renderGraphqlSelectionTree($orderTree);

        return "fragment {$graphqlType}Fields on {$graphqlType} {\n            {$selection}\n        }";
    }

    /**
     * Shopify Product fetch fragment — built only from active ecom→erp product field configs.
     */
    public function buildShopifyProductGraphqlFragment(string $ecomDriver, string $erpDriver): string
    {
        $configs = $this->getMappings('product', $ecomDriver, $erpDriver, null, 'ecom_to_erp');

        $productTree = [];
        $variantTree = [];

        foreach ($configs as $config) {
            if (!$config->is_active) {
                continue;
            }

            $transform = self::effectiveSystemTransform($config->transform, $config->reverse_transform);
            if (in_array($transform, ['line_container', 'skip'], true)) {
                continue;
            }

            if (($config->field_type ?? '') === 'custom') {
                continue;
            }

            foreach ($this->shopifyProductGraphqlPathsForConfig($config) as $pathInfo) {
                $segments = array_map(
                    fn (string $segment) => $this->graphqlPathSegment($segment),
                    array_filter(explode('.', $pathInfo['path']), fn (string $s) => $s !== '')
                );

                if ($segments === []) {
                    continue;
                }

                if ($pathInfo['scope'] === 'variant') {
                    $this->mergeGraphqlPathTree($variantTree, $segments);
                } else {
                    $this->mergeGraphqlPathTree($productTree, $segments);
                }
            }
        }

        $this->mergeGraphqlPathTree($productTree, ['id']);
        $this->mergeGraphqlPathTree($productTree, ['handle']);
        $this->mergeGraphqlPathTree($productTree, ['updatedAt']);
        $this->mergeGraphqlPathTree($variantTree, ['id']);

        $productSelection = $this->renderGraphqlSelectionTree($productTree);
        $variantSelection = $this->renderGraphqlSelectionTree($variantTree);
        $variantsBlock    = $variantSelection !== ''
            ? "variants(first: 100) { edges { node { {$variantSelection} } } }"
            : 'variants(first: 1) { edges { node { id } } }';

        return "fragment ProductFields on Product {\n            {$productSelection}\n            {$variantsBlock}\n        }";
    }

    /**
     * Shopify InventoryItem fetch selection — built only from active inventory ecom→erp field configs.
     */
    public function buildShopifyInventoryGraphqlSelection(string $ecomDriver, string $erpDriver): string
    {
        $configs = $this->getInventoryEcomToErpConfigs($ecomDriver, $erpDriver);

        $itemTree  = [];
        $levelTree = [];

        foreach ($configs as $config) {
            if (!$config->is_active) {
                continue;
            }

            if ($config->field_type === 'custom' && empty(trim($config->erp_field ?? ''))) {
                continue;
            }

            $path = trim((string) ($config->ecom_field ?? $config->shopify_field ?? ''));
            if ($path === '' || str_starts_with($path, '_')) {
                continue;
            }

            $segments = array_values(array_filter(
                explode('.', $path),
                fn (string $segment) => $segment !== '' && !ctype_digit($segment)
            ));

            if ($segments === []) {
                continue;
            }

            $root = array_shift($segments);

            if ($root === 'inventoryItem') {
                $this->mergeGraphqlPathTree($itemTree, $segments);
            } elseif ($root === 'inventoryLevel') {
                $this->mergeGraphqlPathTree($levelTree, $segments);
            }
        }

        $this->mergeGraphqlPathTree($itemTree, ['id']);

        $itemSelection = $this->renderGraphqlSelectionTree($itemTree);

        if ($levelTree === []) {
            return $itemSelection;
        }

        $levelSelection = $this->renderGraphqlSelectionTree($levelTree);

        return trim($itemSelection . "\n            inventoryLevels(first: 20) {\n              edges {\n                node {\n                  {$levelSelection}\n                }\n              }\n            }");
    }

    /**
     * @return list<array{path: string, scope: string}>
     */
    private function shopifyProductGraphqlPathsForConfig(ProductFieldConfig $config): array
    {
        $scope = (string) ($config->scope ?? 'template');
        $paths = [];

        $primary = trim((string) ($config->ecom_field ?? $config->shopify_field ?? ''));
        if ($primary !== '' && !str_starts_with($primary, '_')) {
            $paths[] = ['path' => $primary, 'scope' => $scope === 'variant' ? 'variant' : 'template'];
        }

        if (($config->field_type ?? '') === 'combine') {
            $second = trim((string) ($config->ecom_field_2 ?? ''));
            if ($second !== '' && !str_starts_with($second, '_')) {
                $paths[] = ['path' => $second, 'scope' => $scope === 'variant' ? 'variant' : 'template'];
            }
        }

        return $paths;
    }

    /**
     * Map legacy REST-style config labels to Shopify Admin GraphQL read paths.
     */
    private function normalizeShopifyGraphqlFetchPath(string $path, string $scope): string
    {
        if ($scope !== 'line') {
            return $path;
        }

        $root = trim(explode('.', $path)[0] ?? '');

        return match ($root) {
            'price', 'originalUnitPrice' => str_contains($path, '.')
                ? $path
                : 'originalUnitPriceSet.shopMoney.amount',
            // LineItem.sku — do not rewrite to variant.sku (variant is often null on old orders).
            'sku', 'variant' => $path,
            default => $path,
        };
    }

    /**
     * @param  array<string, mixed>  $tree
     * @param  list<string>  $segments
     */
    private function mergeGraphqlPathTree(array &$tree, array $segments): void
    {
        if ($segments === []) {
            return;
        }

        $head = array_shift($segments);

        if ($segments === []) {
            $tree[$head] = $tree[$head] ?? true;

            return;
        }

        if (!isset($tree[$head]) || $tree[$head] === true) {
            $tree[$head] = [];
        }

        if (!is_array($tree[$head])) {
            return;
        }

        $this->mergeGraphqlPathTree($tree[$head], $segments);
    }

    /**
     * @param  array<string, mixed>  $tree
     */
    private function renderGraphqlSelectionTree(array $tree): string
    {
        $parts = [];

        foreach ($tree as $field => $child) {
            if (!is_string($field) || $field === '') {
                continue;
            }

            if ($child === true) {
                $parts[] = $field;
                continue;
            }

            if (!is_array($child) || $child === []) {
                $parts[] = $field;
                continue;
            }

            $inner = $this->renderGraphqlSelectionTree($child);

            if ($this->isShopifyGraphqlConnectionField($field)) {
                $parts[] = "{$field}(first: 100) { edges { node { {$inner} } } }";
                continue;
            }

            $parts[] = "{$field} { {$inner} }";
        }

        return implode(' ', $parts);
    }

    /** Shopify list fields use the connection (edges/node) wire shape — not business mapping. */
    private function isShopifyGraphqlConnectionField(string $field): bool
    {
        return (bool) preg_match('/(?:Lines|Items)$/', $field);
    }

    private function graphqlPathSegment(string $segment): string
    {
        if ($segment === '' || ctype_digit($segment)) {
            return $segment;
        }

        return $this->segmentToCamelCase($segment);
    }

    /**
     * All distinct ecom_field paths configured for an entity (both directions/scopes).
     *
     * @return list<string>
     */
    public function collectConfiguredEcomFields(
        string $entityType,
        string $ecomDriver,
        string $erpDriver
    ): array {
        $fields = [];

        foreach ($this->getMappings($entityType, $ecomDriver, $erpDriver) as $config) {
            foreach (['ecom_field', 'ecom_field_2'] as $column) {
                $path = trim((string) ($config->{$column} ?? ''));
                if ($path !== '') {
                    $fields[] = $path;
                }
            }
        }

        return array_values(array_unique($fields));
    }

    /**
     * Resolve which key in a fetched ecom payload holds an array root from field config
     * (handles line_items vs lineItems and other snake/camel variants).
     */
    public function resolveEcomRootKey(array $data, string $configField): ?string
    {
        $root = trim(explode('.', $configField)[0] ?? '');
        if ($root === '') {
            return null;
        }

        foreach ($this->ecomFieldLookupKeys($root) as $key) {
            if (array_key_exists($key, $data) && is_array($data[$key])) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Read a value from fetched ecom JSON using configured ecom_field path rules.
     *
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $root
     */
    public function readEcomFieldValue(array $source, array $root, string $key): mixed
    {
        return $this->readEcomField($source, $root, $key);
    }

    /** @return list<string> */
    private function ecomPathWithSegmentCasing(string $path, string $mode): string
    {
        $segments = explode('.', $path);

        return implode('.', array_map(function (string $segment) use ($mode) {
            if ($segment === '' || ctype_digit($segment)) {
                return $segment;
            }

            return $mode === 'camel'
                ? $this->segmentToCamelCase($segment)
                : $this->segmentToSnakeCase($segment);
        }, $segments));
    }

    private function segmentToCamelCase(string $segment): string
    {
        if ($segment === '' || !str_contains($segment, '_')) {
            return $segment;
        }

        return lcfirst(str_replace('_', '', ucwords($segment, '_')));
    }

    private function segmentToSnakeCase(string $segment): string
    {
        if ($segment === '' || str_contains($segment, '_')) {
            return $segment;
        }

        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $segment) ?? $segment);
    }

    /**
     * Resolve ecom labels (category fullName, etc.) to ERP many2one IDs via channel_mappings
     * when no explicit channel_map transform is configured on the row.
     */
    private function resolveMany2OneFromEcomLabel(string $erpField, mixed $value, string $ecomDriver): mixed
    {
        $writeKey = preg_match('/^(.+)\.(\d+)$/', $erpField, $m) ? $m[1] : $erpField;
        if (!$writeKey || !str_ends_with($writeKey, '_id') || str_ends_with($writeKey, '_ids')) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '' || is_numeric(trim($value))) {
            return $value;
        }

        $mapType = match ($writeKey) {
            'categ_id', 'item_group' => 'category',
            default                => null,
        };

        if ($mapType === null) {
            return $value;
        }

        $mapped = $this->applyChannelMapToErp($mapType, $value, $ecomDriver);

        return ($mapped !== null && $mapped !== '') ? $mapped : $value;
    }

    /**
     * Read nested ecom values — dot paths only (metafields.0.value, seo.title, …).
     */
    private function readNestedField(array $data, string $key): mixed
    {
        return $this->fields->get($data, $key);
    }

    /**
     * ERP many2one read paths like categ_id.1 are for display; child-table paths use dot notation.
     */
    private function assignErpPayloadField(array &$payload, string $erpField, mixed $value): void
    {
        if ($erpField === '') {
            return;
        }

        if (preg_match('/^(.+_id)\.(\d+)$/', $erpField, $m)) {
            $payload[$m[1]] = $value;

            return;
        }

        if (str_contains($erpField, '.')) {
            $this->fields->set($payload, $erpField, $value);

            return;
        }

        $payload[$erpField] = $value;
    }

    private function shouldIncludeMappedValue(mixed $value): bool
    {
        if ($value === 0 || $value === 0.0 || $value === '0') {
            return true;
        }

        if ($value === null || $value === '' || $value === false) {
            return false;
        }

        if (is_string($value) && in_array(strtolower(trim($value)), ['empty', 'null', 'none'], true)) {
            return false;
        }

        return true;
    }

    /**
     * Strict erp→ecom validation (abort on empty required mappings).
     * Enable via connector_settings key erp_to_ecom_strict_mapping (optional per install).
     */
    private function erpToEcomStrictMappingEnabled(): bool
    {
        return filter_var(
            app(SettingsService::class)->get('erp_to_ecom_strict_mapping', false),
            FILTER_VALIDATE_BOOLEAN
        );
    }

    /**
     * Strict ecom→erp validation (abort on invalid paths / empty required mappings).
     * Enable via connector_settings key ecom_to_erp_strict_mapping.
     */
    private function ecomToErpStrictMappingEnabled(): bool
    {
        return filter_var(
            app(SettingsService::class)->get('ecom_to_erp_strict_mapping', true),
            FILTER_VALIDATE_BOOLEAN
        );
    }

    private function ecomToErpMappingIsOptional(ProductFieldConfig $config): bool
    {
        if (trim($config->transform ?? '') === 'skip') {
            return true;
        }

        if (($config->field_type ?? '') === 'custom') {
            return true;
        }

        $ecomField = strtolower(trim($config->ecom_field ?? $config->shopify_field ?? ''));
        if (str_contains($ecomField, 'compareatprice') || str_contains($ecomField, 'compare_at_price')) {
            return true;
        }

        if (str_contains($ecomField, 'barcode')) {
            return true;
        }

        if (trim((string) ($config->default_value ?? '')) !== '') {
            return true;
        }

        return false;
    }

    /**
     * Invalid ecom paths (typos like inventoryItem.barcode14) always abort — not optional.
     */
    private function assertEcomFieldConfigPath(array $source, array $rootEntity, ProductFieldConfig $config): void
    {
        if (($config->field_type ?? '') === 'custom') {
            return;
        }

        $rootEntity = $this->enrichEcomPayloadForMapping($rootEntity);
        $entityType = (string) ($config->entity_type ?? 'product');
        $syncLabel  = match ($entityType) {
            'inventory'   => 'Inventory sync',
            'customer'    => 'Customer sync',
            'sales_order' => 'Order sync',
            'dispatch'    => 'Dispatch sync',
            default       => 'Product sync',
        };

        foreach ($this->ecomFieldPathsForConfig($config) as $pathInfo) {
            $path   = $pathInfo['path'];
            $scope  = $pathInfo['scope'];
            $entity = $scope === 'variant' ? $this->firstScopedLine($rootEntity) : $rootEntity;

            if ($scope === 'variant' && $entity === []) {
                throw new \RuntimeException(
                    $syncLabel . ' aborted: variant scope mapping "'
                    . $path . '" requires at least one variant on the fetched ' . $entityType . '.'
                );
            }

            if (!$this->ecomFieldPathExists(is_array($entity) ? $entity : [], $path)) {
                throw new \RuntimeException(
                    $syncLabel . ' aborted: ecom field path "' . $path . '" (scope=' . $scope . ') '
                    . 'does not exist on the fetched ' . $entityType . '. Update Field Config to match fetched JSON.'
                    . $this->ecomFieldPathHint($path)
                );
            }
        }
    }

    /** @return list<array{path: string, scope: string}> */
    private function ecomFieldPathsForConfig(ProductFieldConfig $config): array
    {
        $scope = (string) ($config->scope ?? 'template');
        $paths = [];

        $primary = trim((string) ($config->ecom_field ?? $config->shopify_field ?? ''));
        if ($primary !== '' && !str_starts_with($primary, '_')) {
            $paths[] = ['path' => $primary, 'scope' => $scope === 'variant' ? 'variant' : 'template'];
        }

        if (($config->field_type ?? '') === 'combine') {
            $second = trim((string) ($config->ecom_field_2 ?? ''));
            if ($second !== '' && !str_starts_with($second, '_')) {
                $paths[] = ['path' => $second, 'scope' => $scope === 'variant' ? 'variant' : 'template'];
            }
        }

        return $paths;
    }

    /** True when every segment exists on the payload (null leaf values are allowed). */
    private function ecomFieldPathExists(array $data, string $path): bool
    {
        return $this->fields->pathExists($data, $path);
    }

    private function ecomFieldPathHint(string $path): string
    {
        if (preg_match('/\.quantities\.(?!\d+\.)[^.]+/', $path)) {
            return ' Arrays need an index — e.g. inventoryLevel.quantities.0.quantity (not inventoryLevel.quantities.quantity).';
        }

        return '';
    }

    /** @param  array<string|int, mixed>  $data */
    private function resolvePayloadSegmentKey(array $data, string $segment): ?string
    {
        if (array_key_exists($segment, $data)) {
            return $segment;
        }

        $camel = $this->segmentToCamelCase($segment);
        if ($camel !== $segment && array_key_exists($camel, $data)) {
            return $camel;
        }

        $snake = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $segment) ?? $segment);
        if ($snake !== $segment && array_key_exists($snake, $data)) {
            return $snake;
        }

        if (ctype_digit($segment) && array_key_exists((int) $segment, $data)) {
            return (string) (int) $segment;
        }

        return null;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ProductFieldConfig>  $configs
     */
    private function assertEcomToErpProductPayload(
        array $payload,
        \Illuminate\Support\Collection $configs,
        array $ecomProduct,
    ): void {
        if (!$this->ecomToErpStrictMappingEnabled()) {
            return;
        }

        $missing = [];

        foreach ($configs as $config) {
            if (!$config->is_active || $this->ecomToErpMappingIsOptional($config)) {
                continue;
            }

            $erpField = trim($config->erp_field ?? $config->odoo_field ?? '');
            if ($erpField === '') {
                continue;
            }

            $writeKey = preg_match('/^(.+)\.(\d+)$/', $erpField, $m) ? $m[1] : $erpField;
            $value    = str_contains($erpField, '.')
                ? $this->fields->get($payload, $erpField)
                : ($payload[$writeKey] ?? null);

            if ($this->shouldIncludeMappedValue($value)) {
                continue;
            }

            $ecomField = trim($config->ecom_field ?? $config->shopify_field ?? '');
            $missing[] = "{$ecomField} → {$erpField} (scope=" . ($config->scope ?? 'default') . ')';
        }

        if ($missing === []) {
            return;
        }

        throw new \RuntimeException(
            'Product sync aborted: ecom→erp field mappings produced no ERP payload value for '
            . implode(', ', $missing)
            . '. Check ecom paths match fetched JSON and Field Config.'
        );
    }

    private function throwEcomToErpMappingFailed(ProductFieldConfig $config, string $reason): void
    {
        $ecomField = trim($config->ecom_field ?? $config->shopify_field ?? '');
        $erpField  = trim($config->erp_field ?? $config->odoo_field ?? '');
        $scope     = trim((string) ($config->scope ?? 'default'));

        throw new \RuntimeException(
            'Product sync aborted: ecom→erp mapping failed for '
            . "{$ecomField} → {$erpField} (scope={$scope}) — {$reason}."
        );
    }

    /**
     * Optional erp→ecom mappings may be omitted when ERP has no value (e.g. compare-at price).
     */
    private function erpToEcomMappingIsOptional(ProductFieldConfig $config): bool
    {
        if (trim($config->transform ?? '') === 'skip') {
            return true;
        }

        if ($this->isVariantInventoryLocationFieldConfig($config)) {
            return true;
        }

        $ecomField = strtolower(trim($config->ecom_field ?? $config->shopify_field ?? ''));
        if (str_contains($ecomField, 'compareatprice') || str_contains($ecomField, 'compare_at_price')) {
            return true;
        }

        if ($this->erpToEcomStrictMappingEnabled()) {
            $transform = strtolower(trim($config->transform ?? ''));
            if ($transform === 'channel_map:category') {
                return true;
            }

            if (str_contains($ecomField, 'barcode')) {
                return true;
            }
        }

        if (($config->field_type ?? '') === 'custom') {
            if (trim($config->transform ?? '') !== '') {
                return false;
            }

            $default = trim((string) ($config->default_value ?? ''));

            return $default === ''
                || in_array(strtolower($default), ['empty', 'null', 'none'], true);
        }

        return false;
    }

    private function throwErpToEcomMappingFailed(ProductFieldConfig $config): void
    {
        $entity    = trim((string) ($config->entity_type ?? 'entity'));
        $ecomField = trim($config->ecom_field ?? $config->shopify_field ?? '');
        $erpField  = trim($config->erp_field ?? $config->odoo_field ?? '');
        $scope     = trim((string) ($config->scope ?? 'default'));
        $writePath = $this->resolveEcomWritePath($config);

        throw new \RuntimeException(
            ucfirst($entity) . ' push aborted: erp→ecom field config produced no value for '
            . "{$ecomField} ← {$erpField} (scope={$scope}, path={$writePath}). "
            . 'Check the ERP field path exists on the fetched document and Field Config mappings.'
        );
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ProductFieldConfig>  $templateConfigs
     * @param  \Illuminate\Support\Collection<int, ProductFieldConfig>  $variantConfigs
     * @param  list<array<string, mixed>>  $variantPayloads
     */
    private function assertErpToEcomProductPayload(
        array $payload,
        \Illuminate\Support\Collection $templateConfigs,
        \Illuminate\Support\Collection $variantConfigs,
        array $variantPayloads,
    ): void {
        if (!$this->erpToEcomStrictMappingEnabled()) {
            return;
        }

        $missing = [];

        foreach ($templateConfigs as $config) {
            if ($this->erpToEcomMappingIsOptional($config)) {
                continue;
            }

            $path = $this->resolveEcomWritePath($config);
            if ($path === '' || $this->mappedPayloadHasValue($payload, $path)) {
                continue;
            }

            $missing[] = $this->formatMissingErpToEcomMapping($config, $path);
        }

        foreach ($variantPayloads as $index => $variantPayload) {
            foreach ($variantConfigs as $config) {
                if ($this->isVariantInventoryLocationFieldConfig($config)
                    || $this->erpToEcomMappingIsOptional($config)) {
                    continue;
                }

                $path = $this->resolveEcomWritePath($config);
                if ($path === '' || $this->mappedPayloadHasValue($variantPayload, $path)) {
                    continue;
                }

                $missing[] = $this->formatMissingErpToEcomMapping($config, $path) . " [variant {$index}]";
            }
        }

        if ($missing === []) {
            return;
        }

        throw new \RuntimeException(
            'Product push aborted: erp→ecom field mappings incomplete — missing '
            . implode(', ', $missing)
            . '. Check ERP field paths on the fetched document and Field Config.'
        );
    }

    private function formatMissingErpToEcomMapping(ProductFieldConfig $config, string $writePath): string
    {
        $ecomField = trim($config->ecom_field ?? $config->shopify_field ?? '') ?: $writePath;
        $erpField  = trim($config->erp_field ?? $config->odoo_field ?? '');

        return "{$ecomField} ← {$erpField} ({$writePath})";
    }

    /** @param  array<string, mixed>  $payload */
    private function mappedPayloadHasValue(array $payload, string $path): bool
    {
        return $this->shouldIncludeMappedValue($this->fields->get($payload, $path));
    }

    private function resolveCustomDefaultValue(ProductFieldConfig $config): mixed
    {
        if ($config->default_value === '__NULL__') {
            return null;
        }

        $default = trim((string) ($config->default_value ?? ''));
        if ($default === '' || in_array(strtolower($default), ['empty', 'null', 'none'], true)) {
            return null;
        }

        return $this->applyLengthConstraints($default, $config);
    }

    private function applyLengthConstraints(mixed $value, ProductFieldConfig $config): mixed
    {
        if (!is_string($value)) {
            return $value;
        }
        if ($config->min_length && strlen($value) < $config->min_length) {
            return null;
        }
        if ($config->max_length && strlen($value) > $config->max_length) {
            $value = substr($value, 0, $config->max_length);
        }
        return $value;
    }

    /**
     * System-only transforms (channel maps, line_container, partner resolution, …).
     * Value mapping uses the conditions column instead.
     *
     * @param  array<string, mixed>  $context
     */
    public function applySystemTransform(
        mixed $value,
        ?string $transform,
        array $context = [],
        ?string $ecomDriver = null,
        string $direction = 'erp_to_ecom',
        ?ErpInterface $erp = null,
    ): mixed {
        $transform = trim($transform ?? '');
        if ($transform === '') {
            return $value;
        }

        if ($transform === 'skip') {
            return null;
        }

        if ($transform === 'line_container') {
            return $value;
        }

        $ecomDriver = $ecomDriver ?? app(SettingsService::class)->ecomDriver();
        $erp        = $erp ?? app(ErpInterface::class);

        if (str_starts_with($transform, 'channel_map:')) {
            $type = strtolower(substr($transform, 12));

            if ($direction === 'ecom_to_erp' && $type === 'warehouse') {
                $resolved = trim((string) ($context['_warehouse'] ?? ''));
                if ($resolved !== '') {
                    return $resolved;
                }
            }

            return $direction === 'erp_to_ecom'
                ? $this->applyChannelMapToEcom($type, $value, $ecomDriver)
                : $this->applyChannelMapToErp($type, $value, $ecomDriver);
        }

        if (str_starts_with($transform, 'resolve_partner:')) {
            return $this->resolvePartnerForErp(substr($transform, 16), $value, $erp, $ecomDriver);
        }

        if ($transform === 'image_url_to_base64') {
            return $this->transformImageUrlToBase64($value);
        }

        if ($transform === 'resolve_product_by_sku') {
            return $erp->resolveProductIdByReference((string) $value);
        }

        if ($transform === 'resolve_fulfillment_line_item_id') {
            $orderId = (string) ($context['_push']['ecom_order_id'] ?? $context['_ecom_order_id'] ?? '');
            if ($orderId === '') {
                return null;
            }

            $productRef = $value ?? ($context['product_id'] ?? null);

            return app(\App\Services\Shopify\ShopifyFulfillmentService::class)
                ->resolveFulfillmentOrderLineItemId($orderId, $productRef);
        }

        if ($transform === 'resolve_fulfillment_order_id') {
            $orderId = (string) ($value ?? $context['_push']['ecom_order_id'] ?? $context['_ecom_order_id'] ?? '');
            if ($orderId === '') {
                return null;
            }

            return app(\App\Services\Shopify\ShopifyFulfillmentService::class)
                ->resolveFulfillmentOrderId($orderId);
        }

        if ($transform === 'resolve_inventory_item_id') {
            return $this->resolveInventoryItemIdForPush($context, $ecomDriver);
        }

        if (str_starts_with($transform, 'sync_mapping:')) {
            $entityType = trim(substr($transform, strlen('sync_mapping:')));

            return $this->resolveSyncMappingTransform($entityType, $context, $direction, $ecomDriver, $value);
        }

        if ($transform === 'resolve_country_id') {
            return $erp->resolveCountryReference($value);
        }

        if (str_starts_with($transform, 'resolve_state_id')) {
            $countryPath = str_contains($transform, ':')
                ? trim(substr($transform, strlen('resolve_state_id:')))
                : '';

            $countryRef = $countryPath !== ''
                ? $this->readEcomField($context, $context, $countryPath)
                : null;

            return $erp->resolveStateReference($value, $countryRef);
        }

        if ($transform === 'resolve_country_code') {
            return $erp->resolveCountryCode($value);
        }

        if ($transform === 'resolve_country_label') {
            return $erp->resolveCountryLabel($value);
        }

        if (str_starts_with($transform, 'resolve_state_code')) {
            $countryPath = str_contains($transform, ':')
                ? trim(substr($transform, strlen('resolve_state_code:')))
                : '';

            $countryRef = $countryPath !== ''
                ? $this->readSourceField($context, $context, $countryPath)
                : null;

            return $erp->resolveStateCode($value, $countryRef);
        }

        if ($transform === 'array_second') {
            if (is_array($value) && array_key_exists(1, $value)) {
                return $value[1];
            }

            return $value;
        }

        if ($transform === 'company_default_cost_center') {
            $company = trim((string) ($context['_company'] ?? $context['company'] ?? ''));

            return $company !== '' ? $erp->resolveDefaultCostCenter($company) : null;
        }

        return $value;
    }

    public static function isSystemTransform(?string $transform): bool
    {
        $transform = trim($transform ?? '');
        if ($transform === '') {
            return false;
        }

        if (in_array($transform, self::SYSTEM_TRANSFORMS, true)) {
            return true;
        }

        return str_starts_with($transform, 'channel_map:')
            || str_starts_with($transform, 'resolve_partner:')
            || str_starts_with($transform, 'resolve_state_id')
            || str_starts_with($transform, 'resolve_state_code')
            || in_array($transform, [
                'resolve_country_id',
                'resolve_country_code',
                'resolve_country_label',
                'array_second',
                'resolve_fulfillment_line_item_id',
                'resolve_fulfillment_order_id',
                'company_default_cost_center',
            ], true);
    }

    public static function effectiveSystemTransform(?string $transform, ?string $reverseTransform = null): ?string
    {
        foreach ([trim($reverseTransform ?? ''), trim($transform ?? '')] as $candidate) {
            if ($candidate !== '' && self::isSystemTransform($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** Post-condition shaping for ERP → Ecom (tags array, base64 images, …). */
    public function shapeEcomOutput(mixed $value, ProductFieldConfig $config): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $field = strtolower($config->ecom_field ?? $config->shopify_field ?? '');

        if (str_contains($field, 'tags') && is_string($value)) {
            return array_values(array_filter(array_map('trim', explode(',', $value))));
        }

        if ((str_contains($field, 'image') || str_contains($field, 'media'))
            && is_string($value)
            && $value !== ''
            && !filter_var($value, FILTER_VALIDATE_URL)
        ) {
            return [['attachment' => $value, 'alt' => 'Product Image']];
        }

        if ($field === 'status') {
            return $this->normalizeShopifyProductStatus($value);
        }

        if (str_contains($field, 'tracked')) {
            if (is_bool($value)) {
                return $value;
            }

            $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            return $parsed ?? (bool) $value;
        }

        return $value;
    }

    /** Map ERP booleans / legacy strings to Shopify ProductStatus enum values. */
    private function normalizeShopifyProductStatus(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'ACTIVE' : 'DRAFT';
        }

        if (is_int($value) || (is_string($value) && ctype_digit(trim($value)))) {
            return ((int) $value) === 1 ? 'ACTIVE' : 'DRAFT';
        }

        $upper = strtoupper(trim((string) $value));
        if (in_array($upper, ['ACTIVE', 'ARCHIVED', 'DRAFT', 'UNLISTED'], true)) {
            return $upper;
        }

        return match (strtolower(trim((string) $value))) {
            'active', 'published', 'true', 'yes', '1' => 'ACTIVE',
            'archived' => 'ARCHIVED',
            'unlisted' => 'UNLISTED',
            default    => 'DRAFT',
        };
    }

    /** Post-condition shaping for Ecom → ERP (many2one id, image URL fetch, …). */
    public function shapeErpOutput(mixed $value, ProductFieldConfig $config): mixed
    {
        $erpField = $config->erp_field ?? $config->odoo_field ?? '';

        if (str_ends_with($erpField, '_id') && is_array($value) && array_key_exists(0, $value)) {
            $id = $value[0];

            return is_numeric($id) ? (int) $id : $id;
        }

        if (preg_match('/^image_\d+$/', $erpField)
            && is_string($value)
            && filter_var($value, FILTER_VALIDATE_URL)
        ) {
            return $this->transformImageUrlToBase64($value);
        }

        return $value;
    }

    /**
     * Channel map — ecom external value → ERP ID (from channel_mappings table).
     */
    private function resolveInventoryItemIdForPush(array $context, string $ecomDriver): ?string
    {
        $mappedId = $this->resolveSyncMappingTransform('inventory', $context, 'erp_to_ecom', $ecomDriver);
        if ($mappedId !== null && $mappedId !== '') {
            return (string) $mappedId;
        }

        $productEcomId = (string) (
            $context['_push']['product_ecom_id']
            ?? $context['product_ecom_id']
            ?? ''
        );

        if ($productEcomId === '') {
            $erpProductId = (string) ($context['product_id'][0] ?? $context['product_id'] ?? $context['_push']['erp_product_id'] ?? '');
            if ($erpProductId !== '') {
                $productMapping = app(MappingService::class)->findByErpId('product', $erpProductId)
                    ?? app(MappingService::class)->findByErpId('product_variant', $erpProductId);
                $productEcomId = (string) ($productMapping?->ecom_id ?? '');
            }
        }

        if ($productEcomId === '') {
            return null;
        }

        $ids = app(\App\Services\Shopify\ShopifyInventoryService::class)->resolveInventoryItemIdsForProduct($productEcomId);

        return $ids[0] ?? null;
    }

    /** @param  array<string, mixed>  $context */
    private function resolveSyncMappingTransform(
        string $entityType,
        array $context,
        string $direction,
        string $ecomDriver,
        mixed $sourceValue = null,
    ): mixed {
        if ($entityType === '') {
            return null;
        }

        if ($direction === 'erp_to_ecom') {
            $erpId = (string) (
                $context['_push']['erp_product_id']
                ?? $context['product_id'][0]
                ?? $context['product_id']
                ?? $context['erp_id']
                ?? ''
            );

            if ($erpId === '') {
                return null;
            }

            $mapping = app(MappingService::class)->findByErpId($entityType, $erpId);

            return $mapping?->ecom_id;
        }

        $ecomId = (string) ($sourceValue ?? $context['inventory_item_id'] ?? $context['ecom_id'] ?? $context['id'] ?? '');
        if ($ecomId === '') {
            return null;
        }

        $mapping = app(MappingService::class)->findByEcomId($entityType, $ecomId, $ecomDriver);

        return $mapping?->erp_id;
    }

    /**
     * Channel map — ecom external value → ERP ID (from channel_mappings table).
     */
    private function applyChannelMapToErp(string $type, mixed $value, string $ecomDriver): mixed
    {
        $type = strtolower(trim($type));

        if ($type === 'warehouse') {
            $lookup = $this->warehouseChannelMapLookupValue($value);
            if ($lookup === '') {
                return null;
            }

            $mapped = app(ChannelMappingService::class)->resolveWarehouseOdooIdForShopifyLocation($lookup)
                ?? app(ChannelMappingService::class)->odooWarehouse($lookup, $ecomDriver)
                ?? app(ChannelMappingService::class)->odooWarehouse($lookup, null);
            if ($mapped !== null && $mapped !== '') {
                return is_numeric($mapped) ? (int) $mapped : $mapped;
            }

            return null;
        }

        $candidates = $this->channelMapErpCandidates($type, $value);

        if ($candidates === []) {
            return null;
        }

        foreach ($candidates as $candidate) {
            $mapped = ChannelMapping::query()
                ->ofType($type)
                ->forChannel($ecomDriver)
                ->active()
                ->where(function ($q) use ($candidate) {
                    $q->where('external_id', $candidate)
                      ->orWhere('external_label', $candidate);
                })
                ->value('odoo_id');

            if ($mapped !== null && $mapped !== '') {
                return is_numeric($mapped) ? (int) $mapped : $mapped;
            }
        }

        return null;
    }

    private function warehouseChannelMapLookupValue(mixed $value): string
    {
        if (is_array($value)) {
            foreach (['id', 'external_id', 0, 1] as $key) {
                if (!array_key_exists($key, $value)) {
                    continue;
                }

                $candidate = trim(is_scalar($value[$key]) ? (string) $value[$key] : '');
                if ($candidate !== '') {
                    return $candidate;
                }
            }

            return '';
        }

        return ($value !== null && $value !== '' && $value !== false)
            ? trim((string) $value)
            : '';
    }

    private function looksLikeShopifyLocationId(mixed $value): bool
    {
        if ($value === null || $value === '' || is_array($value)) {
            return false;
        }

        $str = trim((string) $value);

        if (str_starts_with($str, 'gid://shopify/Location/')) {
            return true;
        }

        return ctype_digit($str) && strlen($str) >= 5;
    }

    /**
     * Build lookup keys for channel_map (ecom side → Odoo id).
     * Supports Shopify TaxonomyCategory objects and "A > B > C" fullName paths.
     */
    private function channelMapErpCandidates(string $type, mixed $value): array
    {
        if (is_array($value)) {
            $raw = array_values(array_filter([
                $value[0] ?? null,
                $value[1] ?? null,
                $value['id'] ?? null,
                $value['external_id'] ?? null,
                $value['fullName'] ?? null,
                $value['name'] ?? null,
            ], fn ($v) => $v !== null && $v !== '' && $v !== false));
        } else {
            $raw = ($value !== null && $value !== '' && $value !== false) ? [(string) $value] : [];
        }

        $candidates = [];
        foreach ($raw as $item) {
            $candidates[] = (string) $item;
            if ($type === 'warehouse') {
                if (str_starts_with((string) $item, 'gid://')) {
                    $numeric = (string) last(explode('/', (string) $item));
                    if ($numeric !== '') {
                        $candidates[] = $numeric;
                    }
                } elseif (ctype_digit((string) $item)) {
                    $candidates[] = "gid://shopify/Location/{$item}";
                }
            }
            if ($type === 'category' && str_contains((string) $item, '>')) {
                $parts = array_values(array_filter(array_map('trim', preg_split('/\s*>\s*/', (string) $item) ?: [])));
                if (!empty($parts)) {
                    $candidates[] = end($parts);
                }
            }
        }

        return array_values(array_unique($candidates));
    }

    /**
     * resolve_partner:{role} — channel map first, then ERP adapter lookup/create.
     */
    private function resolvePartnerForErp(string $role, mixed $value, ErpInterface $erp, string $ecomDriver): mixed
    {
        $label = trim(is_array($value) ? (string) ($value[1] ?? $value[0] ?? '') : (string) $value);
        if ($label === '') {
            return null;
        }

        $mapped = $this->applyChannelMapToErp('vendor', $label, $ecomDriver);
        if ($mapped !== null && $mapped !== '') {
            return $erp->extractRelationId($mapped) ?? $mapped;
        }

        return $erp->resolvePartnerReference($role, $label);
    }

    /** ERP ID → ecom external id (e.g. Odoo categ → Shopify Taxonomy GID). */
    private function applyChannelMapToEcom(string $type, mixed $value, string $ecomDriver): mixed
    {
        $lookupKeys = $this->channelMapEcomLookupKeys($type, $value);
        if ($lookupKeys === []) {
            return null;
        }

        $mapped = null;
        foreach ($lookupKeys as $erpKey) {
            $mapped = ChannelMapping::query()
                ->ofType($type)
                ->forChannel($ecomDriver)
                ->active()
                ->where(function ($q) use ($erpKey) {
                    $q->where('odoo_id', $erpKey)
                      ->orWhere('odoo_label', $erpKey);
                })
                ->value('external_id');

            if ($mapped !== null && $mapped !== '') {
                break;
            }
        }

        if (($mapped === null || $mapped === '') && $type === 'warehouse') {
            foreach ($lookupKeys as $erpKey) {
                $mapped = app(ChannelMappingService::class)->shopifyWarehouse($erpKey);
                if ($mapped !== null && $mapped !== '') {
                    break;
                }
            }
        }

        if (($mapped === null || $mapped === '') && $type === 'warehouse') {
            foreach ($lookupKeys as $erpKey) {
                $mapped = $this->passThroughShopifyWarehouseExternalId($erpKey, $ecomDriver);
                if ($mapped !== null && $mapped !== '') {
                    break;
                }
            }
        }

        return ($mapped === null || $mapped === '') ? null : $mapped;
    }

    /**
     * ERP-side lookup keys for channel_mappings (odoo_id / odoo_label).
     * ERPNext item groups normalize to [0, "Donations"] — use the label when id is empty/zero.
     *
     * @return list<string>
     */
    private function channelMapEcomLookupKeys(string $type, mixed $value): array
    {
        if (is_array($value)) {
            $raw = array_values(array_filter([
                $value[0] ?? null,
                $value[1] ?? null,
                $value['id'] ?? null,
                $value['fullName'] ?? null,
                $value['name'] ?? null,
            ], fn ($v) => $v !== null && $v !== '' && $v !== false && $v !== 0 && $v !== '0'));
        } else {
            $raw = ($value !== null && $value !== false && $value !== '' && $value !== 0 && $value !== '0')
                ? [(string) $value]
                : [];
        }

        $keys = [];
        foreach ($raw as $item) {
            $keys[] = (string) $item;
            if ($type === 'category' && str_contains((string) $item, '>')) {
                $parts = array_values(array_filter(array_map('trim', preg_split('/\s*>\s*/', (string) $item) ?: [])));
                if (!empty($parts)) {
                    $keys[] = end($parts);
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Product variant sync may already supply a Shopify location id — not an Odoo location id.
     */
    private function passThroughShopifyWarehouseExternalId(string $value, string $ecomDriver): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $candidates = [$value];
        if (str_starts_with($value, 'gid://')) {
            $numeric = (string) last(explode('/', $value));
            if ($numeric !== '') {
                $candidates[] = $numeric;
            }
        } else {
            $candidates[] = "gid://shopify/Location/{$value}";
        }

        $externalId = ChannelMapping::query()
            ->where('type', 'warehouse')
            ->whereIn('channel', [
                ChannelMapping::CHANNEL_SHOPIFY,
                ChannelMapping::CHANNEL_BOTH,
                $ecomDriver,
            ])
            ->where('is_active', true)
            ->whereIn('external_id', $candidates)
            ->value('external_id');

        if ($externalId === null || $externalId === '') {
            return null;
        }

        return str_starts_with((string) $externalId, 'gid://')
            ? (string) last(explode('/', (string) $externalId))
            : (string) $externalId;
    }

    /**
     * Download a Shopify (or other) image URL and encode for Odoo image_1920 writes.
     */
    private function transformImageUrlToBase64(mixed $value): ?string
    {
        $url = $this->resolveImageSourceUrl($value);
        if ($url === null) {
            return null;
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return is_string($value) && $value !== '' ? $value : null;
        }

        try {
            $response = Http::timeout(30)->get($url);
            if (!$response->successful()) {
                Log::warning("image_url_to_base64: HTTP {$response->status()} for {$url}");
                return null;
            }

            $body = $response->body();
            if ($body === '') {
                return null;
            }

            return base64_encode($body);
        } catch (\Throwable $e) {
            Log::warning("image_url_to_base64: {$e->getMessage()}");
            return null;
        }
    }

    /** @param mixed $value URL string, image node array, or images list */
    private function resolveImageSourceUrl(mixed $value): ?string
    {
        if (is_string($value)) {
            $value = trim($value);
            return $value !== '' ? $value : null;
        }

        if (!is_array($value)) {
            return null;
        }

        if (isset($value['url']) && is_string($value['url']) && $value['url'] !== '') {
            return $value['url'];
        }

        if (isset($value[0])) {
            return $this->resolveImageSourceUrl($value[0]);
        }

        return null;
    }

    /**
     * Set nested value in array using dot notation
     */
    private function setNestedValue(array &$array, string $path, $value): void
    {
        $this->fields->set($array, $path, $value);
    }

    /**
     * Check if a driver pair has any mappings configured
     */
    public function hasMappings(string $entityType, string $ecomDriver, string $erpDriver): bool
    {
        return $this->getMappings($entityType, $ecomDriver, $erpDriver)->isNotEmpty();
    }

    /**
     * Clear all cached mappings
     */
    public function clearCache(): void
    {
        Cache::flush(); // Or use specific cache tags if available
    }
}