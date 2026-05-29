<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now        = now();
        $ecomDriver = 'shopify';
        $erpDriver  = 'odoo';

        // Skip if configs already exist for non-product entities
        $existing = DB::table('product_field_configs')
            ->where('ecom_driver', $ecomDriver)
            ->where('erp_driver', $erpDriver)
            ->whereNotIn('entity_type', ['product', 'shopify'])
            ->count();

        if ($existing > 0) {
            return;
        }

        $rows = [];

        // ── Customers ────────────────────────────────────────────────────
        foreach ([
            // ecom_field           ecom_label              erp_field           erp_label              scope     field_type  transform       default  sort
            ['email',              'Email',                 'email',            'Email',                'default','default',  null,           null,    1],
            ['first_name',         'First Name',            'name',             'Name',                 'default','default',  null,           null,    2],
            ['last_name',          'Last Name',             null,               null,                   'default','custom',   null,           '',      3],
            ['phone',              'Phone',                 'phone',            'Phone',                'default','default',  null,           null,    4],
            ['note',               'Note',                  'comment',          'Notes',                'default','default',  null,           null,    5],
        ] as [$ef, $el, $rf, $rl, $sc, $ft, $tr, $dv, $so]) {
            $rows[] = compact('now','ecomDriver','erpDriver') + [
                'entity_type' => 'customer', 'ecom_field' => $ef, 'ecom_field_label' => $el,
                'erp_field' => $rf, 'erp_field_label' => $rl, 'scope' => $sc,
                'field_type' => $ft, 'transform' => $tr, 'default_value' => $dv, 'sort_order' => $so,
            ];
        }

        // ── Sales Orders (header) ─────────────────────────────────────────
        foreach ([
            ['name',               'Order Name/Ref',        'name',             'Order Reference',      'header','default',  null,           null,    1],
            ['email',              'Customer Email',         'partner_id',       'Customer',             'header','default',  'array_second', null,    2],
            ['financial_status',   'Financial Status',       'invoice_status',   'Invoice Status',       'header','default',  null,           null,    3],
            ['fulfillment_status', 'Fulfillment Status',     'delivery_status',  'Delivery Status',      'header','default',  null,           null,    4],
            ['total_price',        'Total Price',            'amount_total',     'Total Amount',         'header','default',  'number_format',null,    5],
            ['subtotal_price',     'Subtotal',               'amount_untaxed',   'Untaxed Amount',       'header','default',  'number_format',null,    6],
            ['note',               'Note',                   'note',             'Notes',                'header','default',  null,           null,    7],
        ] as [$ef, $el, $rf, $rl, $sc, $ft, $tr, $dv, $so]) {
            $rows[] = compact('now','ecomDriver','erpDriver') + [
                'entity_type' => 'sales_order', 'ecom_field' => $ef, 'ecom_field_label' => $el,
                'erp_field' => $rf, 'erp_field_label' => $rl, 'scope' => $sc,
                'field_type' => $ft, 'transform' => $tr, 'default_value' => $dv, 'sort_order' => $so,
            ];
        }

        // ── Sales Order Lines ─────────────────────────────────────────────
        foreach ([
            ['sku',                'SKU',                   'product_id',       'Product',              'line', 'default',  'array_second', null,    1],
            ['quantity',           'Quantity',               'product_uom_qty',  'Ordered Qty',          'line', 'default',  null,           null,    2],
            ['price',              'Unit Price',             'price_unit',       'Unit Price',           'line', 'default',  'number_format',null,    3],
            ['title',              'Product Name',           'name',             'Description',          'line', 'default',  null,           null,    4],
        ] as [$ef, $el, $rf, $rl, $sc, $ft, $tr, $dv, $so]) {
            $rows[] = compact('now','ecomDriver','erpDriver') + [
                'entity_type' => 'sales_order', 'ecom_field' => $ef, 'ecom_field_label' => $el,
                'erp_field' => $rf, 'erp_field_label' => $rl, 'scope' => $sc,
                'field_type' => $ft, 'transform' => $tr, 'default_value' => $dv, 'sort_order' => $so,
            ];
        }

        // ── Inventory ─────────────────────────────────────────────────────
        foreach ([
            ['inventory_item_id',  'Inventory Item ID',     'product_id',       'Product',              'default','default',  'array_second', null,    1],
            ['available',          'Available Qty',          'qty_available',    'Available Qty',        'default','default',  null,           '0',     2],
            ['location_id',        'Location ID',            'location_id',      'Location',             'default','default',  'array_second', null,    3],
        ] as [$ef, $el, $rf, $rl, $sc, $ft, $tr, $dv, $so]) {
            $rows[] = compact('now','ecomDriver','erpDriver') + [
                'entity_type' => 'inventory', 'ecom_field' => $ef, 'ecom_field_label' => $el,
                'erp_field' => $rf, 'erp_field_label' => $rl, 'scope' => $sc,
                'field_type' => $ft, 'transform' => $tr, 'default_value' => $dv, 'sort_order' => $so,
            ];
        }

        // ── Dispatch / Fulfillment (header) ───────────────────────────────
        foreach ([
            ['tracking_number',    'Tracking Number',       'carrier_tracking_ref','Tracking Ref',      'header','default',  null,           null,    1],
            ['tracking_company',   'Carrier',               'carrier_id',         'Carrier',            'header','default',  'array_second', null,    2],
            ['status',             'Status',                'state',              'State',               'header','default',  null,           null,    3],
        ] as [$ef, $el, $rf, $rl, $sc, $ft, $tr, $dv, $so]) {
            $rows[] = compact('now','ecomDriver','erpDriver') + [
                'entity_type' => 'dispatch', 'ecom_field' => $ef, 'ecom_field_label' => $el,
                'erp_field' => $rf, 'erp_field_label' => $rl, 'scope' => $sc,
                'field_type' => $ft, 'transform' => $tr, 'default_value' => $dv, 'sort_order' => $so,
            ];
        }

        // ── Sales Credit (Refund) ─────────────────────────────────────────
        foreach ([
            ['id',                 'Credit Note ID',        'id',               'ID',                   'header','default',  null,           null,    1],
            ['note',               'Reason',                'narration',        'Narration',            'header','default',  null,           null,    2],
            ['total',              'Total Amount',           'amount_total',     'Total Amount',         'header','default',  'number_format',null,    3],
        ] as [$ef, $el, $rf, $rl, $sc, $ft, $tr, $dv, $so]) {
            $rows[] = compact('now','ecomDriver','erpDriver') + [
                'entity_type' => 'sales_credit', 'ecom_field' => $ef, 'ecom_field_label' => $el,
                'erp_field' => $rf, 'erp_field_label' => $rl, 'scope' => $sc,
                'field_type' => $ft, 'transform' => $tr, 'default_value' => $dv, 'sort_order' => $so,
            ];
        }

        // Insert all rows
        foreach ($rows as &$row) {
            unset($row['now'], $row['ecomDriver'], $row['erpDriver']);
            $row['ecom_driver'] = $ecomDriver;
            $row['erp_driver']  = $erpDriver;
            $row['is_active']   = true;
            $row['created_at']  = $now;
            $row['updated_at']  = $now;
        }

        DB::table('product_field_configs')->insert($rows);
    }

    public function down(): void
    {
        DB::table('product_field_configs')
            ->whereNotIn('entity_type', ['product', 'shopify'])
            ->where('ecom_driver', 'shopify')
            ->where('erp_driver',  'odoo')
            ->delete();
    }
};
