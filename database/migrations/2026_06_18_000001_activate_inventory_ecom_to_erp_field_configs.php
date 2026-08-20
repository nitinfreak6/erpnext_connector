<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $rows = [
            [
                'entity_type' => 'inventory',
                'ecom_field' => 'sku',
                'ecom_field_label' => 'SKU',
                'erp_field' => 'product_id',
                'erp_field_label' => 'Product',
                'scope' => 'default',
                'field_type' => 'default',
                'transform' => 'resolve_product_by_sku',
                'direction' => 'ecom_to_erp',
                'sort_order' => 1,
            ],
            [
                'entity_type' => 'inventory',
                'ecom_field' => 'available',
                'ecom_field_label' => 'Available Qty',
                'erp_field' => 'qty_available',
                'erp_field_label' => 'Available Qty',
                'scope' => 'default',
                'field_type' => 'default',
                'transform' => 'parse_int',
                'direction' => 'ecom_to_erp',
                'sort_order' => 2,
            ],
            [
                'entity_type' => 'inventory',
                'ecom_field' => 'shopify_location_id',
                'ecom_field_label' => 'Location ID',
                'erp_field' => 'location_id',
                'erp_field_label' => 'Location',
                'scope' => 'default',
                'field_type' => 'custom',
                'default_value' => null,
                'direction' => 'ecom_to_erp',
                'sort_order' => 3,
            ],
        ];

        foreach ($rows as $row) {
            $exists = DB::table('product_field_configs')
                ->where('entity_type', 'inventory')
                ->where('ecom_driver', 'shopify')
                ->where('erp_driver', 'odoo')
                ->where('direction', 'ecom_to_erp')
                ->where('ecom_field', $row['ecom_field'])
                ->exists();

            if ($exists) {
                DB::table('product_field_configs')
                    ->where('entity_type', 'inventory')
                    ->where('ecom_driver', 'shopify')
                    ->where('erp_driver', 'odoo')
                    ->where('direction', 'ecom_to_erp')
                    ->where('ecom_field', $row['ecom_field'])
                    ->update([
                        'is_active'  => true,
                        'updated_at' => $now,
                    ]);
                continue;
            }

            DB::table('product_field_configs')->insert(array_merge($row, [
                'ecom_driver'  => 'shopify',
                'erp_driver'   => 'odoo',
                'is_active'    => true,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]));
        }
    }

    public function down(): void
    {
        DB::table('product_field_configs')
            ->where('entity_type', 'inventory')
            ->where('direction', 'ecom_to_erp')
            ->where('ecom_driver', 'shopify')
            ->where('erp_driver', 'odoo')
            ->delete();
    }
};
