<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ensure sync-direction toggle keys exist and remove legacy duplicate general-group toggles.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('connector_settings')
            ->whereIn('key', [
                'sync_products_enabled',
                'sync_inventory_enabled',
                'sync_orders_enabled',
                'sync_customers_enabled',
            ])
            ->delete();

        $rows = [
            ['key' => 'product_sync_enabled',     'label' => 'Enable Product Sync',        'value' => '1', 'default' => '1',  'sort' => 10],
            ['key' => 'product_sync_mode',        'label' => 'Product Sync Direction',     'value' => 'erp_to_ecom', 'default' => 'erp_to_ecom', 'sort' => 11],
            ['key' => 'inventory_sync_enabled',   'label' => 'Enable Inventory Sync',      'value' => '1', 'default' => '1',  'sort' => 15],
            ['key' => 'inventory_sync_mode',      'label' => 'Inventory Sync Direction',   'value' => 'erp_to_ecom', 'default' => 'erp_to_ecom', 'sort' => 16],
            ['key' => 'customer_sync_enabled',    'label' => 'Enable Customer Sync',       'value' => '0', 'default' => '0',  'sort' => 20],
            ['key' => 'customer_sync_mode',       'label' => 'Customer Sync Direction',    'value' => 'erp_to_ecom', 'default' => 'erp_to_ecom', 'sort' => 21],
            ['key' => 'sales_order_sync_enabled', 'label' => 'Enable Sales Order Sync',    'value' => '1', 'default' => '1',  'sort' => 30],
            ['key' => 'sales_order_sync_mode',    'label' => 'Sales Order Sync Direction', 'value' => 'erp_to_ecom', 'default' => 'erp_to_ecom', 'sort' => 31],
        ];

        foreach ($rows as $row) {
            $exists = DB::table('connector_settings')->where('key', $row['key'])->exists();
            if ($exists) {
                continue;
            }

            DB::table('connector_settings')->insert([
                'group'         => 'sync_direction',
                'key'           => $row['key'],
                'label'         => $row['label'],
                'value'         => $row['value'],
                'default_value' => $row['default'],
                'field_type'    => str_contains($row['key'], '_enabled') ? 'toggle' : 'sync_mode',
                'is_secret'     => false,
                'is_active'     => true,
                'description'   => null,
                'sort_order'    => $row['sort'],
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Non-destructive — legacy keys are not restored.
    }
};
