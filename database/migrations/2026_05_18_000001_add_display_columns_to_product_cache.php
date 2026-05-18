<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_cache', function (Blueprint $table) {
            // ── Display columns — dashboard reads these, never touches JSON ──
            $table->decimal('price', 12, 4)->nullable()->after('default_code');
            $table->decimal('cost', 12, 4)->nullable()->after('price');
            $table->decimal('weight', 10, 4)->nullable()->after('cost');
            $table->string('barcode', 100)->nullable()->after('weight');
            $table->string('category', 255)->nullable()->after('barcode');
            $table->unsignedSmallInteger('variant_count')->default(0)->after('category');
            $table->boolean('is_active')->default(true)->after('variant_count');
            $table->string('product_type', 50)->nullable()->after('is_active');

            // ── Channel IDs ───────────────────────────────────────────────
            $table->string('amazon_asin', 20)->nullable()->after('shopify_product_id');
            $table->string('shopify_handle', 255)->nullable()->after('shopify_product_id');

            // ── Raw payload — replaces JSON file reads for the dashboard ──
            // JSON file on disk stays as audit/backup, never read by dashboard
            $table->json('raw_data')->nullable()->after('amazon_asin');

            // ── Indexes ───────────────────────────────────────────────────
            $table->index('is_active');
            $table->index('category');
            $table->index('shopify_product_id');
            $table->index('shopify_handle');
            $table->index('amazon_asin');
        });
    }

    public function down(): void
    {
        Schema::table('product_cache', function (Blueprint $table) {
            $table->dropIndex(['is_active']);
            $table->dropIndex(['category']);
            $table->dropIndex(['shopify_product_id']);
            $table->dropIndex(['shopify_handle']);
            $table->dropIndex(['amazon_asin']);
            $table->dropColumn([
                'price', 'cost', 'weight', 'barcode', 'category',
                'variant_count', 'is_active', 'product_type',
                'amazon_asin', 'shopify_handle', 'raw_data',
            ]);
        });
    }
};