<?php

use App\Models\ProductFieldConfig;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * One-time cleanup: use ecom_field as the nested payload path (same as products).
 * Supersedes 000007 / 000008 / 000009 alias + ecom_api_path split.
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

        // Drop legacy alias rows (available, shopify_location_id, …) replaced by path in ecom_field.
        ProductFieldConfig::query()
            ->where($base)
            ->whereIn('ecom_field', [
                'available',
                'quantity',
                'inventory_quantity',
                'shopify_location_id',
                'location_id',
                'inventory_item_id',
                'changeFromQuantity',
                'quantity_name',
                'adjustment_reason',
            ])
            ->delete();

        $configs = [
            [
                'ecom_field'       => 'quantities.0.inventoryItemId',
                'ecom_field_label' => 'Inventory item ID',
                'erp_field'        => null,
                'field_type'       => 'custom',
                'transform'        => 'resolve_inventory_item_id',
                'ecom_cast'        => 'shopify_gid_inventory_item',
                'sort_order'       => 1,
                'is_active'        => true,
            ],
            [
                'ecom_field'       => 'quantities.0.quantity',
                'ecom_field_label' => 'Available Qty',
                'erp_field'        => 'available_quantity',
                'erp_field_label'  => 'Available Qty',
                'field_type'       => 'default',
                'ecom_cast'        => 'integer',
                'sort_order'       => 2,
                'is_active'        => true,
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
                'is_active'        => true,
            ],
            [
                'ecom_field'       => 'name',
                'ecom_field_label' => 'Quantity state name',
                'erp_field'        => null,
                'field_type'       => 'custom',
                'default_value'    => 'available',
                'sort_order'       => 10,
                'is_active'        => true,
            ],
            [
                'ecom_field'       => 'reason',
                'ecom_field_label' => 'Adjustment reason',
                'erp_field'        => null,
                'field_type'       => 'custom',
                'default_value'    => 'correction',
                'sort_order'       => 11,
                'is_active'        => true,
            ],
            [
                'ecom_field'       => 'quantities.0.changeFromQuantity',
                'ecom_field_label' => 'Change-from quantity',
                'erp_field'        => null,
                'field_type'       => 'custom',
                'default_value'    => '__NULL__',
                'ecom_cast'        => 'nullable',
                'sort_order'       => 12,
                'is_active'        => true,
            ],
        ];

        foreach ($configs as $config) {
            ProductFieldConfig::updateOrCreate(
                array_merge($base, ['ecom_field' => $config['ecom_field']]),
                array_merge($base, $config, ['ecom_api_path' => null])
            );
        }

        ProductFieldConfig::query()
            ->where($base)
            ->update(['ecom_api_path' => null]);

        Cache::forget('field_configs_inventory_shopify_odoo_default_erp_to_ecom');
    }

    public function down(): void
    {
        // Non-destructive — configs remain usable with ecom_field paths.
    }
};
