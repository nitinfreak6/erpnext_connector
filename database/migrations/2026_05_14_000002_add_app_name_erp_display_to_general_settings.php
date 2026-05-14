<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add app_name, erp_display_name, and erp_driver to general connector settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        $new = [
            [
                'group'         => 'general',
                'key'           => 'app_name',
                'label'         => 'Application Name',
                'description'   => 'Displayed in the browser tab, page header, and emails. Defaults to the APP_NAME in .env.',
                'default_value' => config('app.name', 'Connector'),
                'field_type'    => 'text',
                'is_secret'     => false,
                'is_active'     => true,
                'sort_order'    => 0,   // top of general
            ],
            [
                'group'         => 'general',
                'key'           => 'erp_display_name',
                'label'         => 'ERP Display Name',
                'description'   => 'Shown wherever the ERP name appears in the UI (e.g. "Odoo Field", column headers). Change this when switching ERP systems.',
                'default_value' => 'Odoo',
                'field_type'    => 'text',
                'is_secret'     => false,
                'is_active'     => true,
                'sort_order'    => 1,
            ],
            [
                'group'         => 'general',
                'key'           => 'erp_driver',
                'label'         => 'ERP Driver',
                'description'   => 'Active ERP adapter. Change takes effect immediately after Save.',
                'default_value' => 'odoo',
                'field_type'    => 'select',   // rendered as pill selector in the UI
                'is_secret'     => false,
                'is_active'     => true,
                'sort_order'    => 2,
            ],
        ];

        foreach ($new as $row) {
            // updateOrCreate so re-running the migration is safe
            DB::table('connector_settings')->updateOrInsert(
                ['key' => $row['key']],
                array_merge($row, [
                    'value'      => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }

        // Re-number existing general settings to sit after the new ones
        $bumps = [
            'sync_products_enabled'   => 10,
            'sync_inventory_enabled'  => 11,
            'sync_orders_enabled'     => 12,
            'sync_customers_enabled'  => 13,
            'shopify_channel_enabled' => 14,
            'amazon_channel_enabled'  => 15,
        ];

        foreach ($bumps as $key => $order) {
            DB::table('connector_settings')
                ->where('key', $key)
                ->update(['sort_order' => $order, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('connector_settings')
            ->whereIn('key', ['app_name', 'erp_display_name', 'erp_driver'])
            ->delete();
    }
};