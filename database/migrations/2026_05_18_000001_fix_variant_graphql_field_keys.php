<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix variant-scope shopify_field values to match Shopify GraphQL schema.
 *
 * The original seed used REST API / informal field names. The GraphQL
 * ProductVariantsBulkInput type does NOT have top-level 'sku', 'barcode',
 * or 'weight' fields — they live inside inventoryItem.
 *
 * This migration corrects the shopify_field column so toGraphQLVariantInput()
 * routes them to the right place. No PHP code changes needed — the routing
 * logic already handles all these keys correctly once the DB is fixed.
 *
 * Mapping:
 *   sku              → inventoryItem.sku          (routed into inventoryItem{})
 *   barcode          → inventoryItem.barcode      (routed into inventoryItem{})
 *   weight           → inventoryItem.measurement.weight.value  (routed into measurement{})
 *   compare_at_price → compareAtPrice             (camelCase — passthrough to top-level)
 */
return new class extends Migration
{
    private array $renames = [
        'sku'              => 'inventoryItem.sku',
        'barcode'          => 'inventoryItem.barcode',
        'weight'           => 'inventoryItem.measurement.weight.value',
        'compare_at_price' => 'compareAtPrice',
    ];

    public function up(): void
    {
        foreach ($this->renames as $old => $new) {
            DB::table('product_field_configs')
                ->where('channel', 'shopify')
                ->where('scope', 'variant')
                ->where('shopify_field', $old)
                ->update([
                    'shopify_field'       => $new,
                    'shopify_field_label' => $this->label($new),
                    'updated_at'          => now(),
                ]);
        }

        // Also add weight unit row if it doesn't exist yet
        // Shopify requires both value AND unit when sending measurement.weight
        $hasUnit = DB::table('product_field_configs')
            ->where('channel', 'shopify')
            ->where('scope', 'variant')
            ->where('shopify_field', 'inventoryItem.measurement.weight.unit')
            ->exists();

        if (!$hasUnit) {
            DB::table('product_field_configs')->insert([
                'channel'             => 'shopify',
                'shopify_field'       => 'inventoryItem.measurement.weight.unit',
                'shopify_field_label' => 'Weight Unit (inventoryItem)',
                'field_type'          => 'custom',
                'odoo_field'          => null,
                'odoo_field_label'    => null,
                'odoo_field_2'        => null,
                'scope'               => 'variant',
                'transform'           => null,
                'default_value'       => 'KILOGRAMS',   // change to GRAMS/POUNDS/OUNCES if needed
                'min_length'          => null,
                'max_length'          => null,
                'is_active'           => 1,
                'sort_order'          => 135,           // sits just after weight value (sort 13)
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);
        }

        // Clear the field-config cache so the next sync picks up new keys immediately
        \Illuminate\Support\Facades\Cache::forget('product_field_configs_shopify');
    }

    public function down(): void
    {
        foreach (array_flip($this->renames) as $new => $old) {
            DB::table('product_field_configs')
                ->where('channel', 'shopify')
                ->where('scope', 'variant')
                ->where('shopify_field', $new)
                ->update([
                    'shopify_field'       => $old,
                    'shopify_field_label' => ucfirst(str_replace('_', ' ', $old)),
                    'updated_at'          => now(),
                ]);
        }

        DB::table('product_field_configs')
            ->where('channel', 'shopify')
            ->where('scope', 'variant')
            ->where('shopify_field', 'inventoryItem.measurement.weight.unit')
            ->where('field_type', 'custom')
            ->delete();

        \Illuminate\Support\Facades\Cache::forget('product_field_configs_shopify');
    }

    private function label(string $field): string
    {
        return match($field) {
            'inventoryItem.sku'                        => 'SKU (inventoryItem)',
            'inventoryItem.barcode'                    => 'Barcode (inventoryItem)',
            'inventoryItem.measurement.weight.value'   => 'Weight Value (inventoryItem)',
            'compareAtPrice'                           => 'Compare At Price',
            default                                    => $field,
        };
    }
};