<?php

use App\Models\ProductFieldConfig;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Odoo → Shopify inventory field mappings (direction = erp_to_ecom only).
 * ecom_field IS the nested payload path (same as products: quantities.0.quantity, name, …).
 * ecom_field_label is the human-readable name shown in the UI.
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

        $configs = [
            [
                'ecom_field'       => 'quantities.0.inventoryItemId',
                'ecom_field_label' => 'Inventory item ID',
                'erp_field'        => null,
                'field_type'       => 'custom',
                'ecom_cast'        => 'shopify_gid_inventory_item',
                'sort_order'       => 1,
            ],
            [
                'ecom_field'       => 'quantities.0.quantity',
                'ecom_field_label' => 'Available Qty',
                'erp_field'        => 'available_quantity',
                'erp_field_label'  => 'Available Qty',
                'field_type'       => 'default',
                'ecom_cast'        => 'integer',
                'sort_order'       => 2,
            ],
            [
                'ecom_field'       => 'quantities.0.locationId',
                'ecom_field_label' => 'Shopify Location ID',
                'erp_field'        => 'location_id',
                'erp_field_label'  => 'Location',
                'field_type'       => 'default',
                'transform'        => 'channel_map:warehouse',
                'ecom_cast'        => 'shopify_gid_location',
                'sort_order'       => 3,
            ],
            [
                'ecom_field'       => 'name',
                'ecom_field_label' => 'Quantity state name',
                'erp_field'        => null,
                'field_type'       => 'custom',
                'default_value'    => 'available',
                'sort_order'       => 10,
            ],
            [
                'ecom_field'       => 'reason',
                'ecom_field_label' => 'Adjustment reason',
                'erp_field'        => null,
                'field_type'       => 'custom',
                'default_value'    => 'correction',
                'sort_order'       => 11,
            ],
            [
                'ecom_field'       => 'quantities.0.changeFromQuantity',
                'ecom_field_label' => 'Change-from quantity',
                'erp_field'        => null,
                'field_type'       => 'custom',
                'default_value'    => '__NULL__',
                'ecom_cast'        => 'nullable',
                'sort_order'       => 12,
            ],
        ];

        foreach ($configs as $config) {
            ProductFieldConfig::updateOrCreate(
                [
                    'entity_type' => $base['entity_type'],
                    'direction'   => $base['direction'],
                    'ecom_driver' => $base['ecom_driver'],
                    'erp_driver'  => $base['erp_driver'],
                    'ecom_field'  => $config['ecom_field'],
                    'scope'       => $base['scope'],
                ],
                array_merge($base, $config, ['ecom_api_path' => null])
            );
        }

        ProductFieldConfig::query()
            ->where('entity_type', 'inventory')
            ->where('ecom_driver', 'shopify')
            ->where('erp_driver', 'odoo')
            ->where('scope', 'default')
            ->whereNull('direction')
            ->whereIn('ecom_field', ['available', 'location_id', 'shopify_location_id', 'inventory_item_id'])
            ->update(['is_active' => false]);

        Cache::forget('field_configs_inventory_shopify_odoo_default_erp_to_ecom');
        Cache::forget('field_configs_inventory_shopify_odoo_default');
    }

    public function down(): void
    {
        ProductFieldConfig::query()
            ->where('entity_type', 'inventory')
            ->where('direction', 'erp_to_ecom')
            ->where('ecom_driver', 'shopify')
            ->where('erp_driver', 'odoo')
            ->where('scope', 'default')
            ->whereIn('ecom_field', [
                'quantities.0.inventoryItemId',
                'quantities.0.quantity',
                'quantities.0.locationId',
                'quantities.0.changeFromQuantity',
                'name',
                'reason',
            ])
            ->delete();

        Cache::forget('field_configs_inventory_shopify_odoo_default_erp_to_ecom');
        Cache::forget('field_configs_inventory_shopify_odoo_default');
    }
};
