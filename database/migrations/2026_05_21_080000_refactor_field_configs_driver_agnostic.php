<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * CRITICAL REFACTOR: Make field mappings driver-agnostic
 * 
 * BEFORE (hardcoded):
 * - shopify_field VARCHAR(100)
 * - odoo_field VARCHAR(100)
 * 
 * AFTER (dynamic):
 * - ecom_driver VARCHAR(50)   -- 'shopify', 'woocommerce', 'magento'
 * - ecom_field VARCHAR(100)   -- 'title', 'regular_price', 'name'
 * - erp_driver VARCHAR(50)    -- 'odoo', 'netsuite', 'sap'
 * - erp_field VARCHAR(100)    -- 'name', 'list_price', 'basePrice'
 * 
 * This allows:
 * - Shopify ↔ Odoo (existing)
 * - WooCommerce ↔ NetSuite (new)
 * - Magento ↔ SAP (future)
 * - Any combination without schema changes
 */
return new class extends Migration
{
    public function up(): void
    {
        // Step 1: Rename 'channel' column to 'entity_type' IF it exists
        if (Schema::hasColumn('product_field_configs', 'channel')) {
            Schema::table('product_field_configs', function (Blueprint $table) {
                $table->renameColumn('channel', 'entity_type');
            });
        }

        // Step 2: Ensure entity_type column exists and set to 'product' for all rows
        if (Schema::hasColumn('product_field_configs', 'entity_type')) {
            DB::statement("UPDATE product_field_configs SET entity_type = 'product' WHERE entity_type IS NULL OR entity_type = ''");
        } else {
            // If entity_type doesn't exist, create it
            Schema::table('product_field_configs', function (Blueprint $table) {
                $table->string('entity_type', 50)->default('product')->after('id');
            });
        }

        // Step 3: Add new driver-agnostic columns (only if they don't exist)
        Schema::table('product_field_configs', function (Blueprint $table) {
            if (!Schema::hasColumn('product_field_configs', 'ecom_driver')) {
                $table->string('ecom_driver', 50)->default('shopify')->after('entity_type');
            }
            if (!Schema::hasColumn('product_field_configs', 'ecom_field')) {
                $table->string('ecom_field', 100)->nullable()->after('ecom_driver');
                $table->string('ecom_field_label', 255)->nullable()->after('ecom_field');
                $table->string('ecom_api_path', 255)->nullable()->after('ecom_field_label')
                    ->comment('GraphQL path or REST field name');
                $table->string('ecom_cast', 50)->nullable()->after('ecom_api_path')
                    ->comment('GraphQL type cast');
            }
            if (!Schema::hasColumn('product_field_configs', 'erp_driver')) {
                $table->string('erp_driver', 50)->default('odoo')->after('field_type');
            }
            if (!Schema::hasColumn('product_field_configs', 'erp_field')) {
                $table->string('erp_field', 100)->nullable()->after('erp_driver');
                $table->string('erp_field_label', 255)->nullable()->after('erp_field');
            }
        });

        // Step 4: Migrate existing data from shopify_field → ecom_field, odoo_field → erp_field
        // Only if these columns exist and ecom_field is empty
        if (Schema::hasColumns('product_field_configs', ['shopify_field', 'ecom_field'])) {
            DB::statement("
                UPDATE product_field_configs 
                SET
                    ecom_driver = 'shopify',
                    ecom_field = COALESCE(ecom_field, shopify_field),
                    ecom_field_label = COALESCE(ecom_field_label, shopify_field_label),
                    erp_driver = 'odoo',
                    erp_field = COALESCE(erp_field, odoo_field),
                    erp_field_label = COALESCE(erp_field_label, odoo_field_label)
                WHERE ecom_field IS NULL OR ecom_field = ''
            ");
        }

        // Step 5: Drop old hardcoded columns (shopify_field, shopify_field_label)
        Schema::table('product_field_configs', function (Blueprint $table) {
            if (Schema::hasColumn('product_field_configs', 'shopify_field')) {
                $table->dropColumn('shopify_field');
            }
            if (Schema::hasColumn('product_field_configs', 'shopify_field_label')) {
                $table->dropColumn('shopify_field_label');
            }
        });

        // Step 6: Add composite index for driver pairs
        // Drop old indexes using SQL (safer than Blueprint)
        DB::statement("ALTER TABLE product_field_configs DROP INDEX IF EXISTS product_field_configs_channel_is_active_index");
        DB::statement("ALTER TABLE product_field_configs DROP INDEX IF EXISTS product_field_configs_channel_scope_index");
        
        // Add new indexes only if they don't already exist
        $indexExists = DB::selectOne("
            SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS 
            WHERE TABLE_NAME = 'product_field_configs' 
            AND INDEX_NAME = 'idx_driver_pair' 
            AND TABLE_SCHEMA = DATABASE()
        ");
        
        if (!$indexExists) {
            Schema::table('product_field_configs', function (Blueprint $table) {
                $table->index(['ecom_driver', 'erp_driver', 'is_active'], 'idx_driver_pair');
            });
        }
        
        // Add entity_scope index if it doesn't exist
        $indexExists = DB::selectOne("
            SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS 
            WHERE TABLE_NAME = 'product_field_configs' 
            AND INDEX_NAME = 'idx_entity_scope' 
            AND TABLE_SCHEMA = DATABASE()
        ");
        
        if (!$indexExists) {
            Schema::table('product_field_configs', function (Blueprint $table) {
                $table->index(['entity_type', 'scope'], 'idx_entity_scope');
            });
        }
        
        // Add entity_type index if it doesn't exist
        $indexExists = DB::selectOne("
            SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS 
            WHERE TABLE_NAME = 'product_field_configs' 
            AND INDEX_NAME = 'idx_entity_type' 
            AND TABLE_SCHEMA = DATABASE()
        ");
        
        if (!$indexExists) {
            Schema::table('product_field_configs', function (Blueprint $table) {
                $table->index(['entity_type'], 'idx_entity_type');
            });
        }
    }

    public function down(): void
    {
        // Reverse: entity_type back to channel
        DB::statement("UPDATE product_field_configs SET entity_type = 'shopify' WHERE entity_type = 'product'");
        
        // Reverse the changes
        Schema::table('product_field_configs', function (Blueprint $table) {
            $table->dropIndex('idx_driver_pair');
        });

        Schema::table('product_field_configs', function (Blueprint $table) {
            $table->string('shopify_field', 100)->after('entity_type');
            $table->string('shopify_field_label', 255)->nullable()->after('shopify_field');
        });

        DB::statement("
            UPDATE product_field_configs SET
                shopify_field = ecom_field,
                shopify_field_label = ecom_field_label
        ");

        Schema::table('product_field_configs', function (Blueprint $table) {
            $table->dropColumn([
                'ecom_driver',
                'ecom_field',
                'ecom_field_label',
                'ecom_api_path',
                'ecom_cast',
                'erp_driver',
                'erp_field',
                'erp_field_label',
            ]);
        });
    }
};