<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migrate product_field_configs: rename shopify_field values from REST API
 * names to GraphQL field names.
 *
 * After this runs, the shopify_field column stores GraphQL keys directly.
 * The ShopifyProductService reads shopify_field and routes/nests values
 * by key pattern — no mapping table needed.
 *
 * REST → GraphQL renames applied to existing seeded rows:
 *   body_html           → descriptionHtml
 *   product_type        → productType
 *   compare_at_price    → compareAtPrice
 *   weight              → inventoryItem.measurement.weight.value
 *   weight_unit         → inventoryItem.measurement.weight.unit
 *   barcode             → inventoryItem.barcode
 *   inventory_management→ inventoryItem.tracked
 *   inventory_policy    → inventoryPolicy
 *   requires_shipping   → inventoryItem.requiresShipping
 *   template_suffix     → templateSuffix
 *
 * Keys that stay the same (GraphQL name = REST name):
 *   title, vendor, tags, status, handle, images,
 *   sku, price, taxable, option1, option2, option3
 */
return new class extends Migration
{
    private array $renames = [
        // Template scope
        'body_html'            => ['key' => 'descriptionHtml',                        'label' => 'Description (HTML)'],
        'product_type'         => ['key' => 'productType',                            'label' => 'Product Type'],
        'template_suffix'      => ['key' => 'templateSuffix',                         'label' => 'Template Suffix'],
        'published_at'         => ['key' => null,   'label' => null],  // remove — no GraphQL equivalent
        'published_scope'      => ['key' => null,   'label' => null],  // remove — deprecated

        // Variant scope
        'compare_at_price'     => ['key' => 'compareAtPrice',                         'label' => 'Compare At Price'],
        'weight'               => ['key' => 'inventoryItem.measurement.weight.value', 'label' => 'Weight'],
        'weight_unit'          => ['key' => 'inventoryItem.measurement.weight.unit',  'label' => 'Weight Unit'],
        'barcode'              => ['key' => 'inventoryItem.barcode',                  'label' => 'Barcode'],
        'inventory_management' => ['key' => 'inventoryItem.tracked',                  'label' => 'Inventory Tracked'],
        'inventory_policy'     => ['key' => 'inventoryPolicy',                        'label' => 'Inventory Policy'],
        'requires_shipping'    => ['key' => 'inventoryItem.requiresShipping',          'label' => 'Requires Shipping'],
    ];

    public function up(): void
    {
        foreach ($this->renames as $oldKey => $new) {
            if ($new['key'] === null) {
                // Field has no GraphQL equivalent — disable rather than delete
                // so existing config is not silently lost
                DB::table('product_field_configs')
                    ->where('channel', 'shopify')
                    ->where('shopify_field', $oldKey)
                    ->update([
                        'is_active'  => false,
                        'updated_at' => now(),
                    ]);
                continue;
            }

            DB::table('product_field_configs')
                ->where('channel', 'shopify')
                ->where('shopify_field', $oldKey)
                ->update([
                    'shopify_field'       => $new['key'],
                    'shopify_field_label' => $new['label'],
                    'updated_at'          => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Reverse: GraphQL key → REST key
        $reverse = [];
        foreach ($this->renames as $oldKey => $new) {
            if ($new['key'] !== null) {
                $reverse[$new['key']] = [
                    'key'   => $oldKey,
                    'label' => ucwords(str_replace('_', ' ', $oldKey)),
                ];
            }
        }

        foreach ($reverse as $gqlKey => $old) {
            DB::table('product_field_configs')
                ->where('channel', 'shopify')
                ->where('shopify_field', $gqlKey)
                ->update([
                    'shopify_field'       => $old['key'],
                    'shopify_field_label' => $old['label'],
                    'updated_at'          => now(),
                ]);
        }

        // Re-enable the ones we disabled
        foreach (['published_at', 'published_scope'] as $restKey) {
            DB::table('product_field_configs')
                ->where('channel', 'shopify')
                ->where('shopify_field', $restKey)
                ->update(['is_active' => true, 'updated_at' => now()]);
        }
    }
};