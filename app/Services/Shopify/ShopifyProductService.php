<?php

namespace App\Services\Shopify;

use App\Models\ProductFieldConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ShopifyProductService
{
    public function __construct(private readonly ShopifyService $shopify) {}

    public function create(array $productData): array
    {
        $response = $this->shopify->post('products.json', ['product' => $productData]);
        return $response['product'];
    }

    public function update(string $shopifyProductId, array $productData): array
    {
        $response = $this->shopify->put("products/{$shopifyProductId}.json", ['product' => $productData]);
        return $response['product'];
    }

    public function get(string $shopifyProductId): ?array
    {
        try {
            $response = $this->shopify->get("products/{$shopifyProductId}.json");
            return $response['product'] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Build Shopify product payload from Odoo cached data.
     * All field mappings come from product_field_configs table — no hardcoded fields.
     * Add/edit/remove rows in dashboard → payload changes immediately (cache cleared on save).
     */
    public function buildPayload(array $odooTemplate, array $variants, array $attributeValues): array
    {
        $configs = $this->getFieldConfigs();

        $templateConfigs = array_filter($configs, fn($c) => $c['scope'] === 'template');
        $variantConfigs  = array_filter($configs, fn($c) => $c['scope'] === 'variant');

        // ── Template-level payload ───────────────────────────────────────
        $payload = [];

        foreach ($templateConfigs as $config) {
            $shopifyKey = $config['shopify_field'];

            // Inactive config → send empty string to CLEAR the field in Shopify.
            // If we simply omit the key, Shopify keeps whatever value it had before.
            if (!$config['is_active']) {
                if (!in_array($shopifyKey, ['images', 'status', 'variants', 'options'])) {
                    $payload[$shopifyKey] = '';
                }
                continue;
            }

            $value = $this->resolveValue($odooTemplate, $config);

            if ($value === null || $value === '') continue;

            if ($shopifyKey === 'images') {
                $payload['images'] = $value;
                continue;
            }

            $payload[$shopifyKey] = $value;
        }

        // Ensure status always present
        if (!isset($payload['status'])) {
            $payload['status'] = 'draft';
        }

        // Allow category resolver override from ProductSyncService
        if (!empty($odooTemplate['_shopify_product_type'])) {
            $payload['product_type'] = $odooTemplate['_shopify_product_type'];
        }

        // ── Variant-level payload ────────────────────────────────────────
        $shopifyVariants = array_map(function (array $variant) use ($variantConfigs, $attributeValues) {
            return $this->buildVariantPayload($variant, $attributeValues, $variantConfigs);
        }, $variants);

        $payload['variants'] = $shopifyVariants;

        // ── Options from attribute lines ─────────────────────────────────
        if (!empty($odooTemplate['attribute_line_ids'])) {
            $builtOptions = $this->buildOptions($attributeValues, $shopifyVariants);
            if (!empty($builtOptions)) {
                $payload['options'] = $builtOptions;
            }
        }

        return $payload;
    }

    // ── Config loader ────────────────────────────────────────────────────

    private function getFieldConfigs(): array
    {
        return Cache::remember('product_field_configs_shopify', 60, function () {
            // Load ALL configs (active + inactive).
            // Active  → resolve value from Odoo and send.
            // Inactive → send "" to explicitly CLEAR the field in Shopify.
            //            (Shopify keeps old values if you simply omit a field on update.)
            return ProductFieldConfig::where('channel', 'shopify')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn($c) => [
                    'shopify_field' => $c->shopify_field,
                    'field_type'    => $c->field_type,
                    'odoo_field'    => $c->odoo_field,
                    'scope'         => $c->scope,
                    'default_value' => $c->default_value,
                    'transform'     => $c->transform,
                    'min_length'    => $c->min_length,
                    'max_length'    => $c->max_length,
                    'is_active'     => (bool) $c->is_active,
                ])
                ->toArray();
        });
    }

    // ── Value resolver ───────────────────────────────────────────────────

    private function resolveValue(array $odooData, array $config): mixed
    {
        // custom → use default_value as the literal value
        if ($config['field_type'] === 'custom') {
            return $config['default_value'] ?? null;
        }

        // default → read from Odoo data
        $raw = $this->readOdooField($odooData, $config['odoo_field'] ?? '');

        // Odoo returns boolean false for empty fields — treat as null so it
        // doesn't get cast to the string "false" when sent to Shopify
        if ($raw === false) {
            $raw = null;
        }

        $value = $this->applyTransform($raw, $config['transform'], $odooData);

        // Fallback to default_value if empty
        if ($value === null || $value === false || $value === '') {
            $value = $config['default_value'] ?? null;
        }

        // Length constraints
        if (is_string($value)) {
            if ($config['min_length'] && strlen($value) < $config['min_length']) {
                return null;
            }
            if ($config['max_length'] && strlen($value) > $config['max_length']) {
                $value = substr($value, 0, $config['max_length']);
            }
        }

        return $value;
    }

    private function readOdooField(array $data, string $key): mixed
    {
        if ($key === '') return null;

        if (str_contains($key, '.')) {
            [$parent, $index] = explode('.', $key, 2);
            $parent = $data[$parent] ?? null;
            return is_array($parent) ? ($parent[(int)$index] ?? null) : null;
        }

        return $data[$key] ?? null;
    }

    private function applyTransform(mixed $value, ?string $transform, array $context = []): mixed
    {
        return match ($transform) {
            'number_format'          => number_format((float)($value ?? 0), 2, '.', ''),
            'number_format_nullable' => ($value > 0) ? number_format((float)$value, 2, '.', '') : null,
            'boolean_status'         => (!empty($value) || !empty($context['website_published']) || !empty($context['is_published'])) ? 'active' : 'draft',
            'array_second'           => is_array($value) ? ($value[1] ?? null) : $value,
            'base64_image'           => !empty($value) ? [['attachment' => $value]] : null,
            default                  => $value,
        };
    }

    // ── Variant payload ──────────────────────────────────────────────────

    private function buildVariantPayload(array $variant, array $attributeValues, array $variantConfigs): array
    {
        $avIds = $variant['product_template_attribute_value_ids'] ?? [];
        $avMap = array_column($attributeValues, null, 'id');

        $shopifyVariant = [
            'inventory_management' => 'shopify',
            'inventory_policy'     => 'deny',
            'weight_unit'          => 'kg',
        ];

        foreach ($variantConfigs as $config) {
            // Inactive → send empty string to clear the field in Shopify
            if (!$config['is_active']) {
                $shopifyVariant[$config['shopify_field']] = '';
                continue;
            }
            $value = $this->resolveValue($variant, $config);
            if ($value === null) continue;
            $shopifyVariant[$config['shopify_field']] = $value;
        }

        foreach (array_slice($avIds, 0, 3) as $index => $avId) {
            $av = $avMap[$avId] ?? null;
            if ($av) {
                $shopifyVariant['option' . ($index + 1)] = $av['_mapped_name'] ?? $av['name'];
            }
        }

        return $shopifyVariant;
    }

    // ── Options builder ──────────────────────────────────────────────────

    private function buildOptions(array $attributeValues, array $variants): array
    {
        $options = []; $attrSeen = [];

        foreach ($variants as $variant) {
            foreach (['option1','option2','option3'] as $i => $optKey) {
                if (!empty($variant[$optKey]) && !isset($attrSeen[$i])) {
                    $attrSeen[$i] = true;
                    $options[] = ['name' => 'Option '.($i+1), 'values' => []];
                }
            }
        }

        foreach ($variants as $variant) {
            foreach (['option1','option2','option3'] as $i => $optKey) {
                if (isset($options[$i]) && !empty($variant[$optKey])) {
                    if (!in_array($variant[$optKey], $options[$i]['values'])) {
                        $options[$i]['values'][] = $variant[$optKey];
                    }
                }
            }
        }

        return array_values(array_filter($options, fn($o) => !empty($o['values'])));
    }
}