<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the ecom → erp config set (direction = 'ecom_to_erp').
 *
 * READ THE SAME WAY AS erp→ecom, just mirrored:
 *   source = ecom_field   (Shopify)   → dropdown in the popup
 *   target = erp_field    (Odoo)      → free text in the popup
 *   transform = the SAME `transform` column (no reverse_transform)
 *   scope = 'template' (read from product root) | 'variant' (read from variants[0])
 *
 * Driver pair is taken from settings at insert time; adjust if you run a
 * non shopify/odoo pair.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ecom = config('sync.ecom_driver', 'shopify');
        $erp  = config('sync.erp_driver', 'odoo');
        $now  = now();

        // Don't double-seed.
        DB::table('product_field_configs')
            ->where('direction', 'ecom_to_erp')
            ->where('ecom_driver', $ecom)
            ->where('erp_driver', $erp)
            ->delete();

        // ── PRODUCT (verified against the fetched Shopify product shape) ──────
        // ecom_field            erp_field          scope       field_type  transform     default  sort
        $product = [
            ['title',            'name',            'template', 'default',  null,          null,    1],
            ['description_html', 'description_sale','template', 'default',  'strip_tags',  null,    2],
            ['sku',              'default_code',    'variant',  'default',  null,          null,    10],
            ['price',            'list_price',      'variant',  'default',  'parse_float', null,    11],
            ['barcode',          'barcode',         'variant',  'default',  null,          null,    12],
            ['weight',           'weight',          'variant',  'default',  'parse_float', '0',     13],
        ];
        $this->insertRows('product', $product, $ecom, $erp, $now);

        // ── SALES ORDER (SCAFFOLD — VERIFY erp_field names against your Odoo
        //    sale.order model and ecom_field against your fetched order JSON
        //    before enabling). Seeded is_active = 0 so nothing syncs until you
        //    confirm each row. ─────────────────────────────────────────────────
        $salesOrder = [
            ['name',        'client_order_ref', 'header', 'default', null, null, 1],
            ['email',       'partner_id',       'header', 'default', null, null, 2], // needs a resolver/channel_map
            ['line_items',  'order_line',       'header', 'default', null, null, 3], // needs line_container handling
        ];
        $this->insertRows('sales_order', $salesOrder, $ecom, $erp, $now, isActive: false);

        // ── INVENTORY (SCAFFOLD — VERIFY). ───────────────────────────────────
        $inventory = [
            ['sku',               'default_code',     'default', 'default', null,          null, 1],
            ['inventory_quantity','qty_available',    'default', 'default', 'parse_int',   null, 2], // usually needs a stock.quant write, not a direct field
        ];
        $this->insertRows('inventory', $inventory, $ecom, $erp, $now, isActive: false);
    }

    public function down(): void
    {
        $ecom = config('sync.ecom_driver', 'shopify');
        $erp  = config('sync.erp_driver', 'odoo');

        DB::table('product_field_configs')
            ->where('direction', 'ecom_to_erp')
            ->where('ecom_driver', $ecom)
            ->where('erp_driver', $erp)
            ->delete();
    }

    private function insertRows(string $entity, array $rows, string $ecom, string $erp, $now, bool $isActive = true): void
    {
        foreach ($rows as [$ecomField, $erpField, $scope, $type, $transform, $default, $sort]) {
            DB::table('product_field_configs')->insert([
                'entity_type'      => $entity,
                'direction'        => 'ecom_to_erp',
                'ecom_driver'      => $ecom,
                'erp_driver'       => $erp,
                'ecom_field'       => $ecomField,
                'ecom_field_label' => ucwords(str_replace('_', ' ', $ecomField)),
                'erp_field'        => $erpField,
                'erp_field_label'  => ucwords(str_replace('_', ' ', $erpField)),
                'field_type'       => $type,
                'transform'        => $transform,
                'default_value'    => $default,
                'scope'            => $scope,
                'is_active'        => $isActive,
                'sort_order'       => $sort,
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);
        }
    }
};