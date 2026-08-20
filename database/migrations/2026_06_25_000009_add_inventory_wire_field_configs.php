<?php

use App\Models\ProductFieldConfig;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Wire-only inventory field configs + ecom_cast for GraphQL transport (no PHP hardcoding).
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
            'is_active'   => true,
        ];

        $wireConfigs = [
            [
                'ecom_field'       => 'inventory_item_id',
                'ecom_field_label' => 'Inventory item ID (GraphQL wire)',
                'erp_field'        => null,
                'field_type'       => 'custom',
                'ecom_api_path'    => 'quantities.0.inventoryItemId',
                'ecom_cast'        => 'shopify_gid_inventory_item',
                'sort_order'       => 1,
            ],
            [
                'ecom_field'       => 'changeFromQuantity',
                'ecom_field_label' => 'Change-from quantity (GraphQL wire)',
                'erp_field'        => null,
                'field_type'       => 'custom',
                'default_value'    => '__NULL__',
                'ecom_api_path'    => 'quantities.0.changeFromQuantity',
                'ecom_cast'        => 'nullable',
                'sort_order'       => 12,
            ],
        ];

        foreach ($wireConfigs as $config) {
            ProductFieldConfig::updateOrCreate(
                array_merge($base, ['ecom_field' => $config['ecom_field']]),
                array_merge($base, $config)
            );
        }

        $casts = [
            'available'           => 'integer',
            'shopify_location_id' => 'shopify_gid_location',
        ];

        foreach ($casts as $ecomField => $cast) {
            ProductFieldConfig::query()
                ->where($base)
                ->where('ecom_field', $ecomField)
                ->update(['ecom_cast' => $cast]);
        }

        Cache::forget('field_configs_inventory_shopify_odoo_default_erp_to_ecom');
    }

    public function down(): void
    {
        ProductFieldConfig::query()
            ->where('entity_type', 'inventory')
            ->where('direction', 'erp_to_ecom')
            ->where('ecom_driver', 'shopify')
            ->where('erp_driver', 'odoo')
            ->where('scope', 'default')
            ->whereIn('ecom_field', ['inventory_item_id', 'changeFromQuantity'])
            ->delete();

        ProductFieldConfig::query()
            ->where('entity_type', 'inventory')
            ->where('direction', 'erp_to_ecom')
            ->where('ecom_driver', 'shopify')
            ->where('erp_driver', 'odoo')
            ->where('scope', 'default')
            ->whereIn('ecom_field', ['available', 'shopify_location_id'])
            ->update(['ecom_cast' => null]);

        Cache::forget('field_configs_inventory_shopify_odoo_default_erp_to_ecom');
    }
};
