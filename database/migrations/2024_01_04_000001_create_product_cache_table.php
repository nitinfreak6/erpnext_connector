<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_cache', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('odoo_id')->unique();
            $table->string('name', 255);
            $table->string('default_code', 100)->nullable();   // SKU / internal ref
            $table->string('file_path', 500);                  // relative path to JSON file
            $table->string('shopify_status', 30)->default('pending');  // pending, sent, failed, skipped
            $table->string('amazon_status', 30)->default('pending');
            $table->string('shopify_product_id', 100)->nullable();
            $table->text('shopify_message')->nullable();
            $table->text('amazon_message')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamp('shopify_synced_at')->nullable();
            $table->timestamp('amazon_synced_at')->nullable();
            $table->timestamps();

            $table->index(['shopify_status', 'amazon_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_cache');
    }
};