<?php

use App\Models\ProductFieldConfig;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Remove placeholder "empty" defaults that break Shopify GraphQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        ProductFieldConfig::query()
            ->where('entity_type', 'inventory')
            ->where('direction', 'erp_to_ecom')
            ->whereIn('default_value', ['empty', 'Empty', 'EMPTY', 'null', 'none'])
            ->update(['default_value' => null]);

        ProductFieldConfig::query()
            ->where('entity_type', 'inventory')
            ->where('direction', 'erp_to_ecom')
            ->where('ecom_field', 'quantities.0.changeFromQuantity')
            ->update([
                'default_value' => '__NULL__',
                'ecom_cast'     => 'nullable',
                'field_type'    => 'custom',
            ]);

        ProductFieldConfig::query()
            ->where('entity_type', 'inventory')
            ->where('direction', 'erp_to_ecom')
            ->where('ecom_field', 'quantities.0.inventoryItemId')
            ->update([
                'default_value' => null,
                'transform'     => 'resolve_inventory_item_id',
                'ecom_cast'     => 'shopify_gid_inventory_item',
                'field_type'    => 'custom',
            ]);

        Cache::forget('field_configs_inventory_shopify_odoo_default_erp_to_ecom');
    }

    public function down(): void
    {
        // Non-destructive cleanup.
    }
};
