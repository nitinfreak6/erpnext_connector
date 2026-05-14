<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_field_configs', function (Blueprint $table) {
            // Drop GraphQL-specific columns — translation now handled internally in ShopifyProductService
            if (Schema::hasColumn('product_field_configs', 'graphql_field')) {
                $table->dropColumn('graphql_field');
            }
            if (Schema::hasColumn('product_field_configs', 'graphql_cast')) {
                $table->dropColumn('graphql_cast');
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_field_configs', function (Blueprint $table) {
            $table->string('graphql_field', 100)->nullable()->after('shopify_field_label');
            $table->string('graphql_cast', 50)->nullable()->after('graphql_field');
        });
    }
};