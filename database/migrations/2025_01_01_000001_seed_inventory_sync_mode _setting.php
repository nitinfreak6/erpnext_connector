<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Only insert if the row doesn't already exist
        $exists = DB::table('connector_settings')
            ->where('key', 'inventory_sync_mode')
            ->exists();

        if (!$exists) {
            DB::table('connector_settings')->insert([
                'key'        => 'inventory_sync_mode',
                'value'      => 'ecom_to_erp',   // ← default matches your Shopify→Odoo direction
                'label'      => 'Inventory Sync Direction',
                'group'      => 'sync',
                'type'       => 'select',
                'is_secret'  => 0,
                'is_active'  => 1,
                'sort_order' => 30,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            // Row exists — update the value to ecom_to_erp to match product direction
            DB::table('connector_settings')
                ->where('key', 'inventory_sync_mode')
                ->update(['value' => 'ecom_to_erp', 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('connector_settings')
            ->where('key', 'inventory_sync_mode')
            ->delete();
    }
};