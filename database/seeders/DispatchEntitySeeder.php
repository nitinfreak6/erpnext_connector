<?php

namespace Database\Seeders;

use App\Models\EntityDefinition;
use App\Models\ProductFieldConfig;
use Illuminate\Database\Seeder;

/**
 * Seeds the 'dispatch' entity definition and its default field configs
 * for Shopify ↔ Odoo dispatch (fulfillment) sync.
 *
 * Run once:
 *   php artisan db:seed --class=DispatchEntitySeeder
 *
 * Safe to re-run — uses updateOrCreate throughout.
 */
class DispatchEntitySeeder extends Seeder
{
    public function run(): void
    {
        // ── 1. Entity definition ─────────────────────────────────────────
        EntityDefinition::updateOrCreate(
            ['entity_type' => 'dispatch'],
            [
                'label'       => 'Dispatch',
                'description' => 'Fulfillment / delivery sync from ERP to Ecom',
                'scopes'      => ['header', 'line'],
                'is_active'   => true,
                'sort_order'  => 40,
            ]
        );

        $this->command->info('dispatch entity_definition seeded.');

        // ── 2. Default field configs (Shopify ↔ Odoo) ────────────────────
        $base = [
            'entity_type' => 'dispatch',
            'ecom_driver' => 'shopify',
            'erp_driver'  => 'odoo',
            'field_type'  => 'default',
            'is_active'   => true,
        ];

        $configs = [
            // Header — picking-level fields
            [
                'ecom_field'       => 'tracking_number',
                'ecom_field_label' => 'Tracking Number',
                'erp_field'        => 'carrier_tracking_ref',
                'erp_field_label'  => 'Tracking Reference',
                'scope'            => 'header',
                'sort_order'       => 1,
            ],
            [
                'ecom_field'       => 'tracking_company',
                'ecom_field_label' => 'Tracking Company',
                'erp_field'        => 'carrier_id',
                'erp_field_label'  => 'Carrier',
                'scope'            => 'header',
                'transform'        => 'array_second',   // carrier_id = [id, "DHL"] → "DHL"
                'sort_order'       => 2,
            ],
            [
                'ecom_field'       => 'notify_customer',
                'ecom_field_label' => 'Notify Customer',
                'erp_field'        => null,
                'erp_field_label'  => '',
                'field_type'       => 'custom',
                'default_value'    => 'true',
                'scope'            => 'header',
                'sort_order'       => 3,
            ],

            // Line container — tells UniversalSyncService which field holds the lines array
            [
                'ecom_field'       => 'line_items',
                'ecom_field_label' => 'Line Items (container)',
                'erp_field'        => 'moves',          // picking['moves'] injected by PushFulfillmentToEcomJob
                'erp_field_label'  => 'Stock Moves',
                'scope'            => 'header',
                'transform'        => 'line_container',
                'sort_order'       => 4,
            ],

            // Line — stock.move level fields
            [
                'ecom_field'       => 'line_items.quantity',
                'ecom_field_label' => 'Line Item Quantity',
                'erp_field'        => 'quantity',       // Odoo 17+ (was quantity_done in v16)
                'erp_field_label'  => 'Done Quantity',
                'scope'            => 'line',
                'sort_order'       => 1,
            ],
            [
                'ecom_field'       => 'line_items.sku',
                'ecom_field_label' => 'Line Item SKU',
                'erp_field'        => 'product_id',
                'erp_field_label'  => 'Product',
                'scope'            => 'line',
                'transform'        => 'array_second',   // product_id = [id, "[REF] Name"] → "[REF] Name"
                'sort_order'       => 2,
            ],
        ];

        foreach ($configs as $config) {
            ProductFieldConfig::updateOrCreate(
                [
                    'entity_type' => $base['entity_type'],
                    'ecom_driver' => $base['ecom_driver'],
                    'erp_driver'  => $base['erp_driver'],
                    'ecom_field'  => $config['ecom_field'],
                    'scope'       => $config['scope'],
                ],
                array_merge($base, $config)
            );
        }

        $this->command->info('dispatch field configs seeded (' . count($configs) . ' rows).');
    }
}