<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Replaces the separate product_fetch_from / product_post_to rows
 * with a single product_sync_mode field that accepts:
 *
 *   'erp_to_shopify'   – one-way: ERP → Shopify  (default)
 *   'shopify_to_erp'   – one-way: Shopify → ERP
 *   'bidirectional'    – both directions
 *
 * Same pattern for sales orders and dispatch confirmations.
 *
 * The old fetch_from / post_to rows are left intact so existing installs
 * are not broken; they are simply superseded by the new mode field.
 */
return new class extends Migration
{
    private const GROUP = 'sync_direction';

    public function up(): void
    {
        $rows = [
            // ── Product ──────────────────────────────────────────────────
            [
                'key'           => 'product_sync_enabled',
                'label'         => 'Enable Product Sync',
                'value'         => '1',
                'default_value' => '1',
                'field_type'    => 'toggle',
                'sort_order'    => 10,
                'description'   => 'Master switch for product synchronisation.',
            ],
            [
                'key'           => 'product_sync_mode',
                'label'         => 'Product Sync Direction',
                'value'         => 'erp_to_shopify',
                'default_value' => 'erp_to_shopify',
                'field_type'    => 'sync_mode',   // rendered as the 3-option pill selector
                'sort_order'    => 11,
                'description'   => 'erp_to_shopify | shopify_to_erp | bidirectional',
            ],
            [
                'key'           => 'product_linking_enabled',
                'label'         => 'Enable Products Linking',
                'value'         => '0',
                'default_value' => '0',
                'field_type'    => 'toggle',
                'sort_order'    => 13,
                'description'   => 'Match products by SKU/barcode instead of creating duplicates.',
            ],

            // ── Customer ─────────────────────────────────────────────────
            [
                'key'           => 'customer_sync_enabled',
                'label'         => 'Enable Customer Sync',
                'value'         => '0',
                'default_value' => '0',
                'field_type'    => 'toggle',
                'sort_order'    => 20,
                'description'   => 'Master switch for customer synchronisation.',
            ],
            [
                'key'           => 'customer_sync_mode',
                'label'         => 'Customer Sync Direction',
                'value'         => 'erp_to_shopify',
                'default_value' => 'erp_to_shopify',
                'field_type'    => 'sync_mode',
                'sort_order'    => 21,
                'description'   => 'erp_to_shopify | shopify_to_erp | bidirectional',
            ],

            // ── Sales Order ──────────────────────────────────────────────
            [
                'key'           => 'sales_order_sync_enabled',
                'label'         => 'Enable Sales Order Sync',
                'value'         => '1',
                'default_value' => '1',
                'field_type'    => 'toggle',
                'sort_order'    => 30,
                'description'   => 'Master switch for sales order synchronisation.',
            ],
            [
                'key'           => 'sales_order_sync_mode',
                'label'         => 'Sales Order Sync Direction',
                'value'         => 'erp_to_shopify',
                'default_value' => 'erp_to_shopify',
                'field_type'    => 'sync_mode',
                'sort_order'    => 31,
                'description'   => 'erp_to_shopify | shopify_to_erp | bidirectional',
            ],

            // ── Dispatch Confirmation ────────────────────────────────────
            [
                'key'           => 'dispatch_confirmation_enabled',
                'label'         => 'Enable Dispatch Confirmation',
                'value'         => '1',
                'default_value' => '1',
                'field_type'    => 'toggle',
                'sort_order'    => 40,
                'description'   => 'Sync fulfilment / dispatch confirmations between systems.',
            ],
            [
                'key'           => 'dispatch_sync_mode',
                'label'         => 'Dispatch Confirmation Direction',
                'value'         => 'shopify_to_erp',
                'default_value' => 'shopify_to_erp',
                'field_type'    => 'sync_mode',
                'sort_order'    => 41,
                'description'   => 'erp_to_shopify | shopify_to_erp | bidirectional',
            ],
        ];

        foreach ($rows as $row) {
            $exists = DB::table('connector_settings')
                ->where('key', $row['key'])
                ->exists();

            if (! $exists) {
                DB::table('connector_settings')->insert(array_merge([
                    'group'      => self::GROUP,
                    'is_secret'  => false,
                    'is_active'  => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], $row));
            }
        }
    }

    public function down(): void
    {
        $keys = [
            'product_sync_enabled',    'product_sync_mode',    'product_linking_enabled',
            'customer_sync_enabled',   'customer_sync_mode',
            'sales_order_sync_enabled','sales_order_sync_mode',
            'dispatch_confirmation_enabled', 'dispatch_sync_mode',
        ];

        DB::table('connector_settings')->whereIn('key', $keys)->delete();
    }
};