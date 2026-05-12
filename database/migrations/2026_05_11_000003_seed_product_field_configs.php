<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('product_field_configs')->count() > 0) return;

        $now = now();

        DB::table('product_field_configs')->insert([
            // ── Template fields ──────────────────────────────────────────
            ['channel'=>'shopify','shopify_field'=>'title',        'shopify_field_label'=>'Title',                'field_type'=>'default','odoo_field'=>'name',                  'odoo_field_label'=>'Product Name',          'scope'=>'template','transform'=>null,                   'default_value'=>null,'min_length'=>1, 'max_length'=>255,'is_active'=>1,'sort_order'=>1, 'created_at'=>$now,'updated_at'=>$now],
            ['channel'=>'shopify','shopify_field'=>'body_html',    'shopify_field_label'=>'Description',          'field_type'=>'default','odoo_field'=>'description_sale',       'odoo_field_label'=>'Sales Description',     'scope'=>'template','transform'=>null,                   'default_value'=>null,'min_length'=>null,'max_length'=>null,'is_active'=>1,'sort_order'=>2, 'created_at'=>$now,'updated_at'=>$now],
            ['channel'=>'shopify','shopify_field'=>'product_type', 'shopify_field_label'=>'Product Type',         'field_type'=>'default','odoo_field'=>'categ_id',               'odoo_field_label'=>'Product Category',      'scope'=>'template','transform'=>'array_second',          'default_value'=>null,'min_length'=>null,'max_length'=>null,'is_active'=>1,'sort_order'=>3, 'created_at'=>$now,'updated_at'=>$now],
            ['channel'=>'shopify','shopify_field'=>'tags',         'shopify_field_label'=>'Tags',                 'field_type'=>'default','odoo_field'=>'website_meta_keywords',  'odoo_field_label'=>'Meta Keywords',         'scope'=>'template','transform'=>null,                   'default_value'=>null,'min_length'=>null,'max_length'=>null,'is_active'=>1,'sort_order'=>4, 'created_at'=>$now,'updated_at'=>$now],
            ['channel'=>'shopify','shopify_field'=>'status',       'shopify_field_label'=>'Status',               'field_type'=>'default','odoo_field'=>'is_published',           'odoo_field_label'=>'Published (Website)',   'scope'=>'template','transform'=>'boolean_status',        'default_value'=>'draft','min_length'=>null,'max_length'=>null,'is_active'=>1,'sort_order'=>5, 'created_at'=>$now,'updated_at'=>$now],
            ['channel'=>'shopify','shopify_field'=>'images',       'shopify_field_label'=>'Images',               'field_type'=>'default','odoo_field'=>'image_1920',             'odoo_field_label'=>'Product Image',         'scope'=>'template','transform'=>'base64_image',          'default_value'=>null,'min_length'=>null,'max_length'=>null,'is_active'=>1,'sort_order'=>6, 'created_at'=>$now,'updated_at'=>$now],
            // ── Variant fields ───────────────────────────────────────────
            ['channel'=>'shopify','shopify_field'=>'sku',          'shopify_field_label'=>'SKU',                  'field_type'=>'default','odoo_field'=>'default_code',           'odoo_field_label'=>'Internal Reference',    'scope'=>'variant', 'transform'=>null,                   'default_value'=>null,'min_length'=>null,'max_length'=>null,'is_active'=>1,'sort_order'=>10,'created_at'=>$now,'updated_at'=>$now],
            ['channel'=>'shopify','shopify_field'=>'price',        'shopify_field_label'=>'Price',                'field_type'=>'default','odoo_field'=>'lst_price',              'odoo_field_label'=>'Sales Price',           'scope'=>'variant', 'transform'=>'number_format',         'default_value'=>'0.00','min_length'=>null,'max_length'=>null,'is_active'=>1,'sort_order'=>11,'created_at'=>$now,'updated_at'=>$now],
            ['channel'=>'shopify','shopify_field'=>'compare_at_price','shopify_field_label'=>'Compare At Price',  'field_type'=>'default','odoo_field'=>'standard_price',         'odoo_field_label'=>'Cost Price',            'scope'=>'variant', 'transform'=>'number_format_nullable','default_value'=>null,'min_length'=>null,'max_length'=>null,'is_active'=>1,'sort_order'=>12,'created_at'=>$now,'updated_at'=>$now],
            ['channel'=>'shopify','shopify_field'=>'weight',       'shopify_field_label'=>'Weight',               'field_type'=>'default','odoo_field'=>'weight',                 'odoo_field_label'=>'Weight',                'scope'=>'variant', 'transform'=>null,                   'default_value'=>'0',  'min_length'=>null,'max_length'=>null,'is_active'=>1,'sort_order'=>13,'created_at'=>$now,'updated_at'=>$now],
            ['channel'=>'shopify','shopify_field'=>'barcode',      'shopify_field_label'=>'Barcode',              'field_type'=>'default','odoo_field'=>'barcode',                'odoo_field_label'=>'Barcode',               'scope'=>'variant', 'transform'=>null,                   'default_value'=>null,'min_length'=>null,'max_length'=>null,'is_active'=>1,'sort_order'=>14,'created_at'=>$now,'updated_at'=>$now],
        ]);
    }

    public function down(): void
    {
        DB::table('product_field_configs')->truncate();
    }
};