<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connector_settings', function (Blueprint $table) {
            $table->id();
            $table->string('group', 50);        // odoo, shopify, amazon, general
            $table->string('key', 100)->unique();
            $table->string('label', 150);
            $table->text('value')->nullable();   // encrypted for secrets
            $table->text('default_value')->nullable();
            $table->text('field_type')->nullable();
            $table->boolean('is_secret')->default(false); // mask in UI
            $table->boolean('is_active')->default(true);  // feature toggle
            $table->string('description', 500)->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['group', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connector_settings');
    }
};
