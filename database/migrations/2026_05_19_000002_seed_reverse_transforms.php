<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Update field configs with reverse transforms
        
        // body_html: Shopify HTML → Odoo plain text (strip tags)
        DB::table('product_field_configs')
            ->where('shopify_field', 'body_html')
            ->update(['reverse_transform' => 'strip_tags']);
        
        // status: active/draft string → boolean
        DB::table('product_field_configs')
            ->where('shopify_field', 'status')
            ->update(['reverse_transform' => 'status_to_boolean']);
        
        // price: string → float
        DB::table('product_field_configs')
            ->where('shopify_field', 'price')
            ->update(['reverse_transform' => 'parse_float']);
        
        // compare_at_price: string → float (nullable)
        DB::table('product_field_configs')
            ->where('shopify_field', 'compare_at_price')
            ->update(['reverse_transform' => 'parse_float_nullable']);
        
        // weight: string → float
        DB::table('product_field_configs')
            ->where('shopify_field', 'weight')
            ->update(['reverse_transform' => 'parse_float']);
        
        // product_type: needs category lookup (for now, just pass through as string)
        DB::table('product_field_configs')
            ->where('shopify_field', 'product_type')
            ->update(['reverse_transform' => 'pass_through']);
        
        // images: complex - for now skip (would need download + base64 encode)
        DB::table('product_field_configs')
            ->where('shopify_field', 'images')
            ->update(['reverse_transform' => 'skip']);
    }

    public function down(): void
    {
        DB::table('product_field_configs')
            ->whereNotNull('reverse_transform')
            ->update(['reverse_transform' => null]);
    }
};