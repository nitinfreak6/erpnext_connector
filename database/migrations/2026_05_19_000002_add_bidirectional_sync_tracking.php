<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Add columns to support bidirectional sync conflict resolution.
 * 
 * When sync_mode = 'bidirectional', we need to track when each side
 * was last updated to implement last-write-wins conflict resolution.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Check which columns exist
        $columns = Schema::getColumnListing('sync_mappings');
        
        // Step 1: Rename columns if they have old names
        if (in_array('odoo_id', $columns) && !in_array('erp_id', $columns)) {
            DB::statement('ALTER TABLE sync_mappings CHANGE odoo_id erp_id VARCHAR(100)');
        }
        if (in_array('shopify_id', $columns) && !in_array('ecom_id', $columns)) {
            DB::statement('ALTER TABLE sync_mappings CHANGE shopify_id ecom_id VARCHAR(100)');
        }
        if (in_array('shopify_secondary_id', $columns) && !in_array('ecom_secondary_id', $columns)) {
            DB::statement('ALTER TABLE sync_mappings CHANGE shopify_secondary_id ecom_secondary_id VARCHAR(100)');
        }
        if (in_array('odoo_reference', $columns) && !in_array('erp_reference', $columns)) {
            DB::statement('ALTER TABLE sync_mappings CHANGE odoo_reference erp_reference VARCHAR(255)');
        }
        if (in_array('shopify_handle', $columns) && !in_array('ecom_handle', $columns)) {
            DB::statement('ALTER TABLE sync_mappings CHANGE shopify_handle ecom_handle VARCHAR(255)');
        }

        // Refresh column list
        $columns = Schema::getColumnListing('sync_mappings');

        // Step 2: Add new columns
        Schema::table('sync_mappings', function (Blueprint $table) use ($columns) {
            if (!in_array('ecom_driver', $columns)) {
                $table->string('ecom_driver', 20)->default('shopify')->after('entity_type')
                    ->comment('shopify | woocommerce | magento');
            }
            if (!in_array('erp_updated_at', $columns)) {
                $table->timestamp('erp_updated_at')->nullable()->after('last_synced_at');
            }
            if (!in_array('ecom_updated_at', $columns)) {
                $table->timestamp('ecom_updated_at')->nullable()->after('erp_updated_at');
            }
            if (!in_array('last_sync_direction', $columns)) {
                $table->string('last_sync_direction', 20)->nullable()->after('ecom_updated_at')
                    ->comment('erp_to_ecom | ecom_to_erp');
            }
        });

        // Step 3: Drop old indexes and create new ones
        // Drop old unique constraints if they exist
        try {
            DB::statement('ALTER TABLE sync_mappings DROP INDEX sync_mappings_entity_type_odoo_id_unique');
        } catch (\Exception $e) {
            // Index doesn't exist, that's fine
        }
        
        try {
            DB::statement('ALTER TABLE sync_mappings DROP INDEX sync_mappings_entity_type_shopify_id_unique');
        } catch (\Exception $e) {
            // Index doesn't exist, that's fine
        }

        try {
            DB::statement('ALTER TABLE sync_mappings DROP INDEX sync_mappings_odoo_reference_index');
        } catch (\Exception $e) {
            // Index doesn't exist, that's fine
        }

        // Create new indexes
        try {
            DB::statement('ALTER TABLE sync_mappings ADD UNIQUE sync_mappings_entity_ecom_erp_unique (entity_type, ecom_driver, erp_id)');
        } catch (\Exception $e) {
            // Index already exists, that's fine
        }

        try {
            DB::statement('ALTER TABLE sync_mappings ADD UNIQUE sync_mappings_entity_ecom_ecomid_unique (entity_type, ecom_driver, ecom_id)');
        } catch (\Exception $e) {
            // Index already exists, that's fine
        }

        try {
            DB::statement('ALTER TABLE sync_mappings ADD INDEX sync_mappings_erp_reference_index (erp_reference)');
        } catch (\Exception $e) {
            // Index already exists, that's fine
        }

        try {
            DB::statement('ALTER TABLE sync_mappings ADD INDEX sync_mappings_last_sync_direction_index (last_sync_direction)');
        } catch (\Exception $e) {
            // Index already exists, that's fine
        }
    }

    public function down(): void
    {
        // Drop new indexes
        try {
            DB::statement('ALTER TABLE sync_mappings DROP INDEX sync_mappings_last_sync_direction_index');
        } catch (\Exception $e) {}
        
        try {
            DB::statement('ALTER TABLE sync_mappings DROP INDEX sync_mappings_erp_reference_index');
        } catch (\Exception $e) {}
        
        try {
            DB::statement('ALTER TABLE sync_mappings DROP INDEX sync_mappings_entity_ecom_erp_unique');
        } catch (\Exception $e) {}
        
        try {
            DB::statement('ALTER TABLE sync_mappings DROP INDEX sync_mappings_entity_ecom_ecomid_unique');
        } catch (\Exception $e) {}

        // Drop new columns
        Schema::table('sync_mappings', function (Blueprint $table) {
            $table->dropColumn([
                'erp_updated_at',
                'ecom_updated_at',
                'last_sync_direction',
                'ecom_driver',
            ]);
        });
        
        // Rename back to old column names
        $columns = Schema::getColumnListing('sync_mappings');
        
        if (in_array('erp_id', $columns)) {
            DB::statement('ALTER TABLE sync_mappings CHANGE erp_id odoo_id VARCHAR(100)');
        }
        if (in_array('ecom_id', $columns)) {
            DB::statement('ALTER TABLE sync_mappings CHANGE ecom_id shopify_id VARCHAR(100)');
        }
        if (in_array('ecom_secondary_id', $columns)) {
            DB::statement('ALTER TABLE sync_mappings CHANGE ecom_secondary_id shopify_secondary_id VARCHAR(100)');
        }
        if (in_array('erp_reference', $columns)) {
            DB::statement('ALTER TABLE sync_mappings CHANGE erp_reference odoo_reference VARCHAR(255)');
        }
        if (in_array('ecom_handle', $columns)) {
            DB::statement('ALTER TABLE sync_mappings CHANGE ecom_handle shopify_handle VARCHAR(255)');
        }
        
        // Recreate old indexes
        try {
            DB::statement('ALTER TABLE sync_mappings ADD UNIQUE sync_mappings_entity_type_odoo_id_unique (entity_type, odoo_id)');
        } catch (\Exception $e) {}
        
        try {
            DB::statement('ALTER TABLE sync_mappings ADD UNIQUE sync_mappings_entity_type_shopify_id_unique (entity_type, shopify_id)');
        } catch (\Exception $e) {}
        
        try {
            DB::statement('ALTER TABLE sync_mappings ADD INDEX sync_mappings_odoo_reference_index (odoo_reference)');
        } catch (\Exception $e) {}
    }
};