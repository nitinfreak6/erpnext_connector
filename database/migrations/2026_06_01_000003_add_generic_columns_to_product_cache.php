<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_cache', function (Blueprint $table) {
            if (!Schema::hasColumn('product_cache', 'erp_id')) {
                $table->unsignedInteger('erp_id')->nullable()->after('odoo_id');
                $table->index('erp_id');
            }

            if (!Schema::hasColumn('product_cache', 'ecom_status')) {
                $table->string('ecom_status', 30)->default('pending')->after('shopify_status');
            }

            if (!Schema::hasColumn('product_cache', 'ecom_product_id')) {
                $table->string('ecom_product_id', 100)->nullable()->after('shopify_product_id');
            }

            if (!Schema::hasColumn('product_cache', 'ecom_message')) {
                $table->text('ecom_message')->nullable()->after('shopify_message');
            }

            if (!Schema::hasColumn('product_cache', 'ecom_synced_at')) {
                $table->timestamp('ecom_synced_at')->nullable()->after('shopify_synced_at');
            }
        });

        DB::statement("
            UPDATE product_cache
            SET
                erp_id          = COALESCE(erp_id, odoo_id),
                ecom_status     = COALESCE(ecom_status, shopify_status, 'pending'),
                ecom_product_id = COALESCE(ecom_product_id, shopify_product_id),
                ecom_message    = COALESCE(ecom_message, shopify_message),
                ecom_synced_at  = COALESCE(ecom_synced_at, shopify_synced_at)
        ");
    }

    public function down(): void
    {
        Schema::table('product_cache', function (Blueprint $table) {
            $cols = ['erp_id', 'ecom_status', 'ecom_product_id', 'ecom_message', 'ecom_synced_at'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('product_cache', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};