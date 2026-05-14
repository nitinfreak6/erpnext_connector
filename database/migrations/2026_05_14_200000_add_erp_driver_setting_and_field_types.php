<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add ERP Driver setting to general group if not exists
        $exists = DB::table('connector_settings')
            ->where('key', 'erp_driver')
            ->exists();

        if (!$exists) {
            DB::table('connector_settings')->insert([
                'group'         => 'general',
                'erp_driver'    => 'odoo',
                'key'           => 'erp_driver',
                'label'         => 'ERP Driver',
                'value'         => 'odoo',
                'default_value' => 'odoo',
                'is_secret'     => false,
                'is_active'     => true,
                'description'   => 'Which ERP system to connect to. Options: odoo, sap, netsuite',
                'sort_order'    => 0,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }

        // Add field_type to connector_settings for select/toggle/textarea inputs
        if (!Schema::hasColumn('connector_settings', 'field_type')) {
            Schema::table('connector_settings', function ($table) {
                $table->string('field_type', 20)->default('text')->after('description');
                // Values: text, password, select, toggle, textarea, number
            });
        }

        // Backfill field_type for existing rows
        $fieldTypes = [
            'erp_driver'                  => 'select',
            'odoo_timeout'                => 'number',
            'odoo_location_map'           => 'textarea',
            'shopify_inventory_writeback' => 'toggle',
            'sync_products_enabled'       => 'toggle',
            'sync_inventory_enabled'      => 'toggle',
            'sync_orders_enabled'         => 'toggle',
            'sync_customers_enabled'      => 'toggle',
            'shopify_channel_enabled'     => 'toggle',
            'amazon_channel_enabled'      => 'toggle',
            'shopify_access_token'        => 'password',
            'amazon_client_id'            => 'password',
            'amazon_client_secret'        => 'password',
            'amazon_refresh_token'        => 'password',
            'odoo_api_key'                => 'password',
            'shopify_webhook_secret'      => 'password',
        ];

        foreach ($fieldTypes as $key => $type) {
            DB::table('connector_settings')
                ->where('key', $key)
                ->update(['field_type' => $type]);
        }
    }

    public function down(): void
    {
        DB::table('connector_settings')->where('key', 'erp_driver')->delete();
    }
};