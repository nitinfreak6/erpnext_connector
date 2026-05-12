<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_field_configs', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 20)->default('shopify');         // shopify, amazon
            $table->string('shopify_field', 100);                       // e.g. title, body_html, price
            $table->string('shopify_field_label', 255)->nullable();     // display label
            $table->enum('field_type', ['default', 'custom'])->default('default');
            $table->string('odoo_field', 100)->nullable();              // e.g. name, list_price (if field_type=default)
            $table->string('odoo_field_label', 255)->nullable();        // display label
            $table->enum('scope', ['template', 'variant'])->default('template'); // is item level
            $table->string('default_value', 500)->nullable();           // fallback if odoo value empty
            $table->string('transform', 50)->nullable();                // number_format, boolean_status, etc.
            $table->integer('min_length')->nullable();
            $table->integer('max_length')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['channel', 'is_active']);
            $table->index(['channel', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_field_configs');
    }
};