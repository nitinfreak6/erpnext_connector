<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Create entity definitions system
 * 
 * This table defines which entities can be synced and their configuration
 * per driver (Shopify, WooCommerce, Odoo, NetSuite, etc.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 50)->unique()->comment('product, sales_order, customer, etc.');
            $table->string('label', 100)->comment('Human-readable label');
            $table->json('scopes')->comment('Available scopes: template/variant, header/line, etc.');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            
            $table->index('is_active');
        });

        // Create driver-specific entity configurations
        Schema::create('entity_driver_configs', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 50);
            $table->string('driver_type', 10)->comment('ecom or erp');
            $table->string('driver_name', 50)->comment('shopify, woocommerce, odoo, netsuite');
            $table->string('model_name', 100)->comment('Product, product, product.template, InventoryItem');
            $table->string('api_endpoint', 255)->nullable()->comment('REST endpoint or GraphQL mutation');
            $table->string('list_method', 100)->nullable()->comment('Method to list entities');
            $table->string('create_method', 100)->nullable()->comment('Method to create entity');
            $table->string('update_method', 100)->nullable()->comment('Method to update entity');
            $table->json('meta')->nullable()->comment('Driver-specific configuration');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->unique(['entity_type', 'driver_type', 'driver_name'], 'entity_driver_unique');
            $table->index(['entity_type', 'driver_name']);
        });

        // Seed initial entities
        DB::table('entity_definitions')->insert([
            [
                'entity_type' => 'product',
                'label' => 'Products',
                'scopes' => json_encode(['template', 'variant']),
                'description' => 'Product catalog sync',
                'is_active' => true,
                'sort_order' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'entity_type' => 'customer',
                'label' => 'Customers',
                'scopes' => json_encode(['default']),
                'description' => 'Customer contact sync',
                'is_active' => true,
                'sort_order' => 20,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'entity_type' => 'sales_order',
                'label' => 'Sales Orders',
                'scopes' => json_encode(['header', 'line']),
                'description' => 'Sales order sync',
                'is_active' => true,
                'sort_order' => 30,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'entity_type' => 'inventory',
                'label' => 'Inventory',
                'scopes' => json_encode(['default']),
                'description' => 'Stock level sync',
                'is_active' => true,
                'sort_order' => 40,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'entity_type' => 'dispatch',
                'label' => 'Dispatch / Fulfillment',
                'scopes' => json_encode(['header', 'line']),
                'description' => 'Fulfillment and shipping sync',
                'is_active' => false,
                'sort_order' => 50,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Seed driver configs for current setup (Shopify + Odoo)
        DB::table('entity_driver_configs')->insert([
            // Product - Shopify
            [
                'entity_type' => 'product',
                'driver_type' => 'ecom',
                'driver_name' => 'shopify',
                'model_name' => 'Product',
                'api_endpoint' => 'productCreate',
                'list_method' => 'products',
                'create_method' => 'productCreate',
                'update_method' => 'productUpdate',
                'meta' => json_encode(['uses_graphql' => true]),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            // Product - Odoo
            [
                'entity_type' => 'product',
                'driver_type' => 'erp',
                'driver_name' => 'odoo',
                'model_name' => 'product.template',
                'api_endpoint' => null,
                'list_method' => 'search_read',
                'create_method' => 'create',
                'update_method' => 'write',
                'meta' => json_encode(['uses_xmlrpc' => true]),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            // Customer - Shopify
            [
                'entity_type' => 'customer',
                'driver_type' => 'ecom',
                'driver_name' => 'shopify',
                'model_name' => 'Customer',
                'api_endpoint' => 'customerCreate',
                'list_method' => 'customers',
                'create_method' => 'customerCreate',
                'update_method' => 'customerUpdate',
                'meta' => json_encode(['uses_graphql' => true]),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            // Customer - Odoo
            [
                'entity_type' => 'customer',
                'driver_type' => 'erp',
                'driver_name' => 'odoo',
                'model_name' => 'res.partner',
                'api_endpoint' => null,
                'list_method' => 'search_read',
                'create_method' => 'create',
                'update_method' => 'write',
                'meta' => json_encode(['uses_xmlrpc' => true]),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            // Sales Order - Shopify
            [
                'entity_type' => 'sales_order',
                'driver_type' => 'ecom',
                'driver_name' => 'shopify',
                'model_name' => 'Order',
                'api_endpoint' => 'draftOrderCreate',
                'list_method' => 'orders',
                'create_method' => 'draftOrderCreate',
                'update_method' => 'orderUpdate',
                'meta' => json_encode(['uses_graphql' => true]),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            // Sales Order - Odoo
            [
                'entity_type' => 'sales_order',
                'driver_type' => 'erp',
                'driver_name' => 'odoo',
                'model_name' => 'sale.order',
                'api_endpoint' => null,
                'list_method' => 'search_read',
                'create_method' => 'create',
                'update_method' => 'write',
                'meta' => json_encode(['uses_xmlrpc' => true]),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_driver_configs');
        Schema::dropIfExists('entity_definitions');
    }
};