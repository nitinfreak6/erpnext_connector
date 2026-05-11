<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // Default Shopify product field mappings
        // odoo_id      = Odoo field key (dot notation for nested: e.g. categ_id.1)
        // external_id  = Shopify field key
        // meta.scope   = 'template' (product-level) or 'variant' (variant-level)
        // meta.transform = optional transform: 'number_format', 'boolean_status', 'array_second'
        // meta.required  = true means skip product if this is empty

        $mappings = [
            // ── Template fields ───────────────────────────────────────────
            [
                'type'           => 'product_field',
                'channel'        => 'shopify',
                'odoo_id'        => 'name',
                'odoo_label'     => 'Product Name',
                'external_id'    => 'title',
                'external_label' => 'Title',
                'meta'           => json_encode(['scope' => 'template', 'required' => true]),
                'is_active'      => true,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            [
                'type'           => 'product_field',
                'channel'        => 'shopify',
                'odoo_id'        => 'description_sale',
                'odoo_label'     => 'Sales Description',
                'external_id'    => 'body_html',
                'external_label' => 'Body HTML',
                'meta'           => json_encode(['scope' => 'template']),
                'is_active'      => true,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            [
                'type'           => 'product_field',
                'channel'        => 'shopify',
                'odoo_id'        => 'categ_id',
                'odoo_label'     => 'Product Category',
                'external_id'    => 'product_type',
                'external_label' => 'Product Type',
                'meta'           => json_encode(['scope' => 'template', 'transform' => 'array_second']),
                'is_active'      => true,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            [
                'type'           => 'product_field',
                'channel'        => 'shopify',
                'odoo_id'        => 'website_meta_keywords',
                'odoo_label'     => 'Meta Keywords',
                'external_id'    => 'tags',
                'external_label' => 'Tags',
                'meta'           => json_encode(['scope' => 'template']),
                'is_active'      => true,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            [
                'type'           => 'product_field',
                'channel'        => 'shopify',
                'odoo_id'        => 'is_published',
                'odoo_label'     => 'Published (Website)',
                'external_id'    => 'status',
                'external_label' => 'Status (active/draft)',
                'meta'           => json_encode(['scope' => 'template', 'transform' => 'boolean_status']),
                'is_active'      => true,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            [
                'type'           => 'product_field',
                'channel'        => 'shopify',
                'odoo_id'        => 'image_1920',
                'odoo_label'     => 'Product Image',
                'external_id'    => 'images',
                'external_label' => 'Images',
                'meta'           => json_encode(['scope' => 'template', 'transform' => 'base64_image']),
                'is_active'      => true,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],

            // ── Variant fields ────────────────────────────────────────────
            [
                'type'           => 'product_field',
                'channel'        => 'shopify',
                'odoo_id'        => 'default_code',
                'odoo_label'     => 'Internal Reference (SKU)',
                'external_id'    => 'sku',
                'external_label' => 'SKU',
                'meta'           => json_encode(['scope' => 'variant']),
                'is_active'      => true,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            [
                'type'           => 'product_field',
                'channel'        => 'shopify',
                'odoo_id'        => 'lst_price',
                'odoo_label'     => 'Sales Price',
                'external_id'    => 'price',
                'external_label' => 'Price',
                'meta'           => json_encode(['scope' => 'variant', 'transform' => 'number_format']),
                'is_active'      => true,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            [
                'type'           => 'product_field',
                'channel'        => 'shopify',
                'odoo_id'        => 'standard_price',
                'odoo_label'     => 'Cost Price',
                'external_id'    => 'compare_at_price',
                'external_label' => 'Compare At Price',
                'meta'           => json_encode(['scope' => 'variant', 'transform' => 'number_format_nullable']),
                'is_active'      => true,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            [
                'type'           => 'product_field',
                'channel'        => 'shopify',
                'odoo_id'        => 'weight',
                'odoo_label'     => 'Weight',
                'external_id'    => 'weight',
                'external_label' => 'Weight',
                'meta'           => json_encode(['scope' => 'variant']),
                'is_active'      => true,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            [
                'type'           => 'product_field',
                'channel'        => 'shopify',
                'odoo_id'        => 'barcode',
                'odoo_label'     => 'Barcode',
                'external_id'    => 'barcode',
                'external_label' => 'Barcode',
                'meta'           => json_encode(['scope' => 'variant']),
                'is_active'      => true,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
        ];

        // Only insert if not already seeded
        $existing = DB::table('channel_mappings')
            ->where('type', 'product_field')
            ->count();

        if ($existing === 0) {
            DB::table('channel_mappings')->insert($mappings);
        }
    }

    public function down(): void
    {
        DB::table('channel_mappings')
            ->where('type', 'product_field')
            ->delete();
    }
};