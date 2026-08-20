<?php

use App\Models\ProductFieldConfig;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Ensure inventory item ID field config resolves via transform (product mapping + Shopify lookup).
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

        ProductFieldConfig::updateOrCreate(
            array_merge($base, ['ecom_field' => 'quantities.0.inventoryItemId']),
            [
                'ecom_field_label' => 'Inventory item ID',
                'erp_field'        => null,
                'field_type'       => 'custom',
                'transform'        => 'resolve_inventory_item_id',
                'ecom_cast'        => 'shopify_gid_inventory_item',
                'sort_order'       => 1,
                'is_active'        => true,
                'ecom_api_path'    => null,
                'direction'        => 'erp_to_ecom',
                'ecom_driver'      => 'shopify',
                'erp_driver'       => 'odoo',
                'entity_type'      => 'inventory',
                'scope'            => 'default',
            ]
        );

        Cache::forget('field_configs_inventory_shopify_odoo_default_erp_to_ecom');
    }

    public function down(): void
    {
        // Keep row — only transform metadata changed.
    }
};
