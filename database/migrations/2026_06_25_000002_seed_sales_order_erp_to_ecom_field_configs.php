<?php

use App\Models\ProductFieldConfig;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Odoo → Shopify sales order field mappings (direction = erp_to_ecom only).
 * Maps to Shopify DraftOrderInput (email, note, poNumber, lineItems).
 */
return new class extends Migration
{
    public function up(): void
    {
        $base = [
            'entity_type' => 'sales_order',
            'direction'   => 'erp_to_ecom',
            'ecom_driver' => 'shopify',
            'erp_driver'  => 'odoo',
            'is_active'   => true,
        ];

        $header = [
            [
                'ecom_field'       => 'email',
                'ecom_field_label' => 'Customer Email',
                'erp_field'        => 'partner_id',
                'erp_field_label'  => 'Customer',
                'scope'            => 'header',
                'field_type'       => 'default',
                'transform'        => 'array_second',
                'sort_order'       => 1,
            ],
            [
                'ecom_field'       => 'note',
                'ecom_field_label' => 'Note',
                'erp_field'        => 'note',
                'erp_field_label'  => 'Notes',
                'scope'            => 'header',
                'field_type'       => 'default',
                'sort_order'       => 2,
            ],
            [
                'ecom_field'       => 'poNumber',
                'ecom_field_label' => 'PO / Order Reference',
                'erp_field'        => 'name',
                'erp_field_label'  => 'Order Reference',
                'scope'            => 'header',
                'field_type'       => 'default',
                'sort_order'       => 3,
            ],
            [
                'ecom_field'       => 'lineItems',
                'ecom_field_label' => 'Line Items (container)',
                'erp_field'        => 'order_line',
                'erp_field_label'  => 'Order Lines',
                'scope'            => 'header',
                'field_type'       => 'default',
                'transform'        => 'line_container',
                'sort_order'       => 4,
            ],
        ];

        $lines = [
            [
                'ecom_field'       => 'title',
                'ecom_field_label' => 'Line Title',
                'erp_field'        => 'name',
                'erp_field_label'  => 'Description',
                'scope'            => 'line',
                'field_type'       => 'default',
                'sort_order'       => 1,
            ],
            [
                'ecom_field'       => 'quantity',
                'ecom_field_label' => 'Quantity',
                'erp_field'        => 'product_uom_qty',
                'erp_field_label'  => 'Ordered Qty',
                'scope'            => 'line',
                'field_type'       => 'default',
                'sort_order'       => 2,
            ],
            [
                'ecom_field'       => 'originalUnitPrice',
                'ecom_field_label' => 'Unit Price',
                'erp_field'        => 'price_unit',
                'erp_field_label'  => 'Unit Price',
                'scope'            => 'line',
                'field_type'       => 'default',
                'sort_order'       => 3,
            ],
            [
                'ecom_field'       => 'sku',
                'ecom_field_label' => 'SKU',
                'erp_field'        => 'product_id',
                'erp_field_label'  => 'Product',
                'scope'            => 'line',
                'field_type'       => 'default',
                'transform'        => 'array_second',
                'sort_order'       => 4,
            ],
        ];

        foreach (array_merge($header, $lines) as $config) {
            ProductFieldConfig::updateOrCreate(
                [
                    'entity_type' => $base['entity_type'],
                    'direction'   => $base['direction'],
                    'ecom_driver' => $base['ecom_driver'],
                    'erp_driver'  => $base['erp_driver'],
                    'ecom_field'  => $config['ecom_field'],
                    'scope'       => $config['scope'],
                ],
                array_merge($base, $config)
            );
        }

        foreach (['header', 'line'] as $scope) {
            Cache::forget("field_configs_sales_order_shopify_odoo_{$scope}_erp_to_ecom");
        }
    }

    public function down(): void
    {
        ProductFieldConfig::query()
            ->where('entity_type', 'sales_order')
            ->where('direction', 'erp_to_ecom')
            ->where('ecom_driver', 'shopify')
            ->where('erp_driver', 'odoo')
            ->whereIn('scope', ['header', 'line'])
            ->delete();

        foreach (['header', 'line'] as $scope) {
            Cache::forget("field_configs_sales_order_shopify_odoo_{$scope}_erp_to_ecom");
        }
    }
};
