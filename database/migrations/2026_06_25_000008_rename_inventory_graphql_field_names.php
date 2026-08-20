<?php

use App\Models\ProductFieldConfig;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Align inventory GraphQL field config keys with Shopify InventorySetQuantitiesInput:
 * name + reason (not quantity_name / adjustment_reason).
 */
return new class extends Migration
{
    public function up(): void
    {
        $base = [
            'entity_type' => 'inventory',
            'direction'   => 'erp_to_ecom',
            'ecom_driver' => 'shopify',
            'erp_driver'  => 'odoo',
            'scope'       => 'default',
        ];

        $renames = [
            'quantity_name'     => ['name', 'Quantity state name (GraphQL)', 'available'],
            'adjustment_reason' => ['reason', 'Adjustment reason (GraphQL)', 'correction'],
        ];

        foreach ($renames as $oldField => [$newField, $label, $default]) {
            $existing = ProductFieldConfig::query()
                ->where($base)
                ->where('ecom_field', $oldField)
                ->first();

            if ($existing) {
                ProductFieldConfig::updateOrCreate(
                    array_merge($base, ['ecom_field' => $newField]),
                    [
                        'ecom_field_label' => $label,
                        'erp_field'        => null,
                        'field_type'       => 'custom',
                        'default_value'    => $default,
                        'ecom_api_path'    => $newField,
                        'is_active'        => $existing->is_active,
                        'sort_order'       => $existing->sort_order,
                        'direction'        => 'erp_to_ecom',
                        'ecom_driver'      => 'shopify',
                        'erp_driver'       => 'odoo',
                    ]
                );

                $existing->delete();
            }
        }

        foreach (['name', 'reason'] as $field) {
            ProductFieldConfig::query()
                ->where($base)
                ->where('ecom_field', $field)
                ->update(['ecom_api_path' => $field]);
        }

        Cache::forget('field_configs_inventory_shopify_odoo_default_erp_to_ecom');
    }

    public function down(): void
    {
        // Non-destructive rename — no down.
    }
};
