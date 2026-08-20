<?php

use App\Models\ProductFieldConfig;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Simplify inventory wire fields: blank custom = null, no cast/transform on wire-only rows.
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

        ProductFieldConfig::query()
            ->where($base)
            ->where('ecom_field', 'quantities.0.changeFromQuantity')
            ->update([
                'default_value' => null,
                'transform'     => null,
                'ecom_cast'     => null,
                'field_type'    => 'custom',
            ]);

        ProductFieldConfig::query()
            ->where($base)
            ->where('ecom_field', 'quantities.0.inventoryItemId')
            ->update([
                'default_value' => null,
                'transform'     => null,
                'ecom_cast'     => null,
                'field_type'    => 'custom',
            ]);

        ProductFieldConfig::query()
            ->where($base)
            ->whereIn('default_value', ['empty', 'Empty', 'EMPTY', 'null', 'none', '__NULL__'])
            ->update(['default_value' => null]);

        Cache::forget('field_configs_inventory_shopify_odoo_default_erp_to_ecom');
    }

    public function down(): void
    {
        // Non-destructive.
    }
};
