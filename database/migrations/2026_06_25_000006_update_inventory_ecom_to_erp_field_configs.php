<?php

use App\Models\ProductFieldConfig;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Align Shopify → Odoo inventory field configs with erp→ecom pattern:
 * flat erp_field outgoing payload + channel_map:warehouse on location (no skip / hardcoded resolver).
 */
return new class extends Migration
{
    public function up(): void
    {
        $base = [
            'entity_type' => 'inventory',
            'direction'   => 'ecom_to_erp',
            'ecom_driver' => 'shopify',
            'erp_driver'  => 'odoo',
            'scope'       => 'default',
            'is_active'   => true,
        ];

        $configs = [
            [
                'ecom_field'       => 'sku',
                'ecom_field_label' => 'SKU',
                'erp_field'        => 'product_id',
                'erp_field_label'  => 'Product',
                'field_type'       => 'default',
                'transform'        => 'resolve_product_by_sku',
                'sort_order'       => 1,
            ],
            [
                'ecom_field'       => 'available',
                'ecom_field_label' => 'Available Qty',
                'erp_field'        => 'quantity',
                'erp_field_label'  => 'Quantity',
                'field_type'       => 'default',
                'sort_order'       => 2,
            ],
            [
                'ecom_field'       => 'shopify_location_id',
                'ecom_field_label' => 'Shopify Location',
                'erp_field'        => 'location_id',
                'erp_field_label'  => 'Location',
                'field_type'       => 'default',
                'transform'        => 'channel_map:warehouse',
                'sort_order'       => 3,
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
                array_merge($base, $config)
            );
        }

        // Ecom-only metadata row — not part of Odoo outgoing payload.
        ProductFieldConfig::updateOrCreate(
            [
                'entity_type' => $base['entity_type'],
                'direction'   => $base['direction'],
                'ecom_driver' => $base['ecom_driver'],
                'erp_driver'  => $base['erp_driver'],
                'ecom_field'  => 'inventory_item_id',
                'scope'       => $base['scope'],
            ],
            array_merge($base, [
                'ecom_field_label' => 'Inventory Item ID',
                'erp_field'        => null,
                'erp_field_label'  => null,
                'field_type'       => 'custom',
                'default_value'    => '',
                'is_active'        => false,
                'sort_order'       => 4,
            ])
        );

        foreach (['default', 'header', 'line'] as $scope) {
            Cache::forget("field_configs_inventory_shopify_odoo_{$scope}_ecom_to_erp");
        }
    }

    public function down(): void
    {
        ProductFieldConfig::query()
            ->where('entity_type', 'inventory')
            ->where('direction', 'ecom_to_erp')
            ->where('ecom_driver', 'shopify')
            ->where('erp_driver', 'odoo')
            ->where('scope', 'default')
            ->where('ecom_field', 'shopify_location_id')
            ->update(['transform' => 'skip']);

        Cache::forget('field_configs_inventory_shopify_odoo_default_ecom_to_erp');
    }
};
