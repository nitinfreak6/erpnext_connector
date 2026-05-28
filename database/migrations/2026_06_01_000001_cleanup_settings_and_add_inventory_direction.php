<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix #11: Remove old duplicate general-group enable toggles that conflict with
 *          the direction-card toggles. The direction cards (product_sync_enabled,
 *          customer_sync_enabled, sales_order_sync_enabled) are the single source of truth.
 *
 * Fix #12: Add inventory_sync_enabled + inventory_sync_mode so the Inventory
 *          Settings card on the settings page has its own enable toggle and direction.
 *
 * Fix #6:  Remove shopify_display_name and shopify_channel_enabled (Shopify is now just
 *          the active ecom driver — it has no special named status).
 *          ecom_display_name already added by a previous migration; this migration
 *          ensures it exists and has a sane default.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Remove old duplicate general toggles ────────────────────────
        // These were seeded before the direction cards existed. The direction
        // cards submit product_sync_enabled / customer_sync_enabled /
        // sales_order_sync_enabled. Keep those; delete the legacy keys.
        DB::table('connector_settings')
            ->whereIn('key', [
                'sync_products_enabled',
                'sync_orders_enabled',
                'sync_customers_enabled',
                // Keep sync_inventory_enabled row if it exists — we rename it below
            ])
            ->delete();

        // ── Remove shopify_display_name (replaced by ecom_display_name) ─
        // FIX #6: shopify_display_name is no longer valid once the ecom driver
        // can be anything. ecom_display_name is the correct key.
        DB::table('connector_settings')
            ->whereIn('key', ['shopify_display_name', 'shopify_channel_enabled'])
            ->delete();

        // ── Ensure ecom_display_name exists ────────────────────────────
        $exists = DB::table('connector_settings')->where('key', 'ecom_display_name')->exists();
        if (!$exists) {
            DB::table('connector_settings')->insert([
                'group'         => 'general',
                'key'           => 'ecom_display_name',
                'label'         => 'Ecommerce Display Name',
                'value'         => null,
                'default_value' => 'Ecommerce',
                'field_type'    => 'text',
                'is_secret'     => false,
                'is_active'     => true,
                'description'   => 'Shown wherever the ecommerce platform name appears in the UI.',
                'sort_order'    => 4,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }

        // ── Add inventory direction settings (FIX #12) ──────────────────
        $inventoryRows = [
            [
                'key'           => 'inventory_sync_enabled',
                'label'         => 'Enable Inventory Sync',
                'value'         => '1',
                'default_value' => '1',
                'field_type'    => 'toggle',
                'sort_order'    => 15,
                'description'   => 'Master switch for inventory synchronisation.',
            ],
            [
                'key'           => 'inventory_sync_mode',
                'label'         => 'Inventory Sync Direction',
                'value'         => 'erp_to_ecom',
                'default_value' => 'erp_to_ecom',
                'field_type'    => 'sync_mode',
                'sort_order'    => 16,
                'description'   => 'erp_to_ecom | ecom_to_erp | bidirectional',
            ],
        ];

        foreach ($inventoryRows as $row) {
            $exists = DB::table('connector_settings')->where('key', $row['key'])->exists();
            if (!$exists) {
                DB::table('connector_settings')->insert(array_merge($row, [
                    'group'      => 'sync_direction',
                    'is_secret'  => false,
                    'is_active'  => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
        }

        // ── Migrate old sync_inventory_enabled value if it existed ──────
        $oldInv = DB::table('connector_settings')->where('key', 'sync_inventory_enabled')->first();
        if ($oldInv) {
            DB::table('connector_settings')
                ->where('key', 'inventory_sync_enabled')
                ->update(['value' => $oldInv->value, 'updated_at' => now()]);

            DB::table('connector_settings')->where('key', 'sync_inventory_enabled')->delete();
        }
    }

    public function down(): void
    {
        DB::table('connector_settings')
            ->whereIn('key', ['inventory_sync_enabled', 'inventory_sync_mode', 'ecom_display_name'])
            ->delete();
    }
};
