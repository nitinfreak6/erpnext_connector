<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Populate field mapping data in product_field_configs
 * 
 * The previous migration added the new columns but shopify_field was already dropped.
 * This migration populates ecom_field and erp_field from the data that's still in the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Map common Odoo fields to Shopify fields
        $fieldMappings = [
            'name' => 'title',
            'description_sale' => 'body_html',
            'list_price' => 'price',
            'default_code' => 'sku',
            'categ_id' => 'product_type',
            'website_meta_keywords' => 'tags',
            'is_published' => 'status',
            'image_1920' => 'images',
        ];

        $fieldLabels = [
            'name' => 'Product Name',
            'description_sale' => 'Sales Description',
            'list_price' => 'List Price',
            'default_code' => 'Internal Reference',
            'categ_id' => 'Product Category',
            'website_meta_keywords' => 'Meta Keywords',
            'is_published' => 'Published',
            'image_1920' => 'Product Image',
        ];

        // Get all records that need to be populated
        $configs = DB::table('product_field_configs')
            ->whereNull('ecom_field')
            ->orWhere('ecom_field', '')
            ->get();

        foreach ($configs as $config) {
            $erpField = $config->odoo_field;
            $ecomField = $fieldMappings[$erpField] ?? $erpField;
            $erpLabel = $fieldLabels[$erpField] ?? $erpField;

            DB::table('product_field_configs')
                ->where('id', $config->id)
                ->update([
                    'entity_type' => 'product',
                    'ecom_driver' => 'shopify',
                    'ecom_field' => $ecomField,
                    'ecom_field_label' => ucfirst(str_replace('_', ' ', $ecomField)),
                    'erp_driver' => 'odoo',
                    'erp_field' => $erpField,
                    'erp_field_label' => $erpLabel,
                ]);
        }

        // Verify all records have data
        DB::table('product_field_configs')
            ->whereNull('ecom_field')
            ->orWhere('ecom_field', '')
            ->update([
                'ecom_field' => DB::raw('odoo_field'),
                'erp_field' => DB::raw('odoo_field'),
            ]);
    }

    public function down(): void
    {
        // Can't really roll back without knowing original shopify_field values
        // Just clear the new columns
        DB::table('product_field_configs')->update([
            'entity_type' => 'shopify',
            'ecom_driver' => null,
            'ecom_field' => null,
            'ecom_field_label' => null,
            'erp_driver' => null,
            'erp_field' => null,
            'erp_field_label' => null,
        ]);
    }
};