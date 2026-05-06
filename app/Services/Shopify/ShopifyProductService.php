<?php

namespace App\Services\Shopify;

use Illuminate\Support\Facades\Log;

class ShopifyProductService
{
    public function __construct(private readonly ShopifyService $shopify) {}

    /**
     * Create a product in Shopify from an Odoo product template.
     */
    public function create(array $productData): array
    {
        $response = $this->shopify->post('products.json', ['product' => $productData]);

        return $response['product'];
    }

    /**
     * Update an existing Shopify product.
     */
    public function update(string $shopifyProductId, array $productData): array
    {
        $response = $this->shopify->put("products/{$shopifyProductId}.json", ['product' => $productData]);

        return $response['product'];
    }

    /**
     * Get a product by Shopify ID.
     */
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
     * Build Shopify product payload from Odoo data.
     */
    public function buildPayload(array $odooTemplate, array $variants, array $attributeValues): array
    {
        $isPublished = !empty($odooTemplate['website_published']) 
            || !empty($odooTemplate['is_published']);

$status = $isPublished ? 'active' : 'draft';

        $shopifyVariants = array_map(function (array $variant) use ($attributeValues) {
            return $this->buildVariantPayload($variant, $attributeValues);
        }, $variants);

        $payload = [
            'title'        => $odooTemplate['name'],
            'body_html'    => $odooTemplate['description_sale'] ?? '',
            'product_type' => is_array($odooTemplate['categ_id']) ? $odooTemplate['categ_id'][1] : '',
            'status'       => $status,
            'variants'     => $shopifyVariants,
        ];

        // Tags from meta keywords
        if (!empty($odooTemplate['website_meta_keywords'])) {
            $payload['tags'] = $odooTemplate['website_meta_keywords'];
        }

        // Product image from base64
        if (!empty($odooTemplate['image_1920'])) {
            $payload['images'] = [
                ['attachment' => $odooTemplate['image_1920']],
            ];
        }

        // Build options from attribute lines
        if (!empty($odooTemplate['attribute_line_ids'])) {
            // Build options from attribute lines
			$builtOptions = $this->buildOptions($attributeValues, $shopifyVariants);
			if (!empty($builtOptions)) {
				$payload['options'] = $builtOptions;
			}
        }

        return $payload;
    }

    private function buildVariantPayload(array $variant, array $attributeValues): array
    {
        $avIds  = $variant['product_template_attribute_value_ids'] ?? [];
        $avMap  = array_column($attributeValues, null, 'id');

        $shopifyVariant = [
            'sku'              => $variant['default_code'] ?? '',
            'price'            => number_format($variant['lst_price'] ?? 0, 2, '.', ''),
            'compare_at_price' => $variant['standard_price'] > 0
                ? number_format($variant['standard_price'], 2, '.', '')
                : null,
            'weight'           => $variant['weight'] ?? 0,
            'weight_unit'      => 'kg',
            'barcode'          => $variant['barcode'] ?? '',
            'inventory_management' => 'shopify',
            'inventory_policy'     => 'deny',
        ];

        // Map up to 3 attribute values to option1/option2/option3
        foreach (array_slice($avIds, 0, 3) as $index => $avId) {
            $av = $avMap[$avId] ?? null;
            if ($av) {
                $shopifyVariant['option' . ($index + 1)] = $av['name'];
            }
        }

        return $shopifyVariant;
    }

    private function buildOptions(array $attributeValues, array $variants): array
    {
        $options  = [];
        $attrSeen = [];

        foreach ($variants as $variant) {
            foreach (['option1', 'option2', 'option3'] as $i => $optKey) {
                if (!empty($variant[$optKey]) && !isset($attrSeen[$i])) {
                    $attrSeen[$i] = true;
                    $options[]    = [
                        'name'     => 'Option ' . ($i + 1),
                        'values'   => [],
                    ];
                }
            }
        }

        // Collect unique values per option position
        foreach ($variants as $variant) {
            foreach (['option1', 'option2', 'option3'] as $i => $optKey) {
                if (isset($options[$i]) && !empty($variant[$optKey])) {
                    if (!in_array($variant[$optKey], $options[$i]['values'])) {
                        $options[$i]['values'][] = $variant[$optKey];
                    }
                }
            }
        }

        // Filter out options with no values
        $filtered = array_values(array_filter($options, fn($o) => !empty($o['values'])));
        return $filtered;
    }
}
