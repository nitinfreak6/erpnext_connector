<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_field_configs', function (Blueprint $table) {
            // GraphQL field name — e.g. body_html → descriptionHtml
            $table->string('graphql_field', 100)->nullable()->after('shopify_field_label');

            // GraphQL cast — how to transform the value for GraphQL
            // Options: string, string_nullable, uppercase, array, float, boolean,
            //          base64_image, inventory_tracked
            $table->string('graphql_cast', 50)->default('string')->after('graphql_field');
        });

        // Backfill existing rows with correct graphql_field + graphql_cast
        $defaults = [
            // Template fields
            'title'        => ['graphql_field' => 'title',           'graphql_cast' => 'string'],
            'body_html'    => ['graphql_field' => 'descriptionHtml',  'graphql_cast' => 'string'],
            'product_type' => ['graphql_field' => 'productType',      'graphql_cast' => 'string'],
            'tags'         => ['graphql_field' => 'tags',             'graphql_cast' => 'array'],
            'status'       => ['graphql_field' => 'status',           'graphql_cast' => 'uppercase'],
            'images'       => ['graphql_field' => 'images',           'graphql_cast' => 'base64_image'],
            'vendor'       => ['graphql_field' => 'vendor',           'graphql_cast' => 'string'],
            'handle'       => ['graphql_field' => 'handle',           'graphql_cast' => 'string'],
            // Variant fields
            'sku'               => ['graphql_field' => 'sku',              'graphql_cast' => 'string'],
            'price'             => ['graphql_field' => 'price',            'graphql_cast' => 'string'],
            'compare_at_price'  => ['graphql_field' => 'compareAtPrice',   'graphql_cast' => 'string_nullable'],
            'weight'            => ['graphql_field' => 'weight',           'graphql_cast' => 'float'],
            'weight_unit'       => ['graphql_field' => 'weightUnit',       'graphql_cast' => 'uppercase'],
            'barcode'           => ['graphql_field' => 'barcode',          'graphql_cast' => 'string'],
            'taxable'           => ['graphql_field' => 'taxable',          'graphql_cast' => 'boolean'],
            'requires_shipping' => ['graphql_field' => 'requiresShipping', 'graphql_cast' => 'boolean'],
            'inventory_management' => ['graphql_field' => 'inventoryItem', 'graphql_cast' => 'inventory_tracked'],
            'inventory_policy'  => ['graphql_field' => 'inventoryPolicy',  'graphql_cast' => 'uppercase'],
        ];

        foreach ($defaults as $shopifyField => $values) {
            DB::table('product_field_configs')
                ->where('shopify_field', $shopifyField)
                ->update($values);
        }
    }

    public function down(): void
    {
        Schema::table('product_field_configs', function (Blueprint $table) {
            $table->dropColumn(['graphql_field', 'graphql_cast']);
        });
    }
};