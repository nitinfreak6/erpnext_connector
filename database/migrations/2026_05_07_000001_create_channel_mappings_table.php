<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('type', 50);           // warehouse, shipping, category, pricelist, payment, channel, sales_order_type, sales_rep, product_size, tax
            $table->string('channel', 20);         // shopify, amazon, both
            $table->string('odoo_id', 100)->nullable();
            $table->string('odoo_label', 255)->nullable();
            $table->string('external_id', 100)->nullable();
            $table->string('external_label', 255)->nullable();
            $table->json('meta')->nullable();      // extra fields per type
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['type', 'channel']);
            $table->index(['type', 'odoo_id']);
            $table->index(['type', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_mappings');
    }
};