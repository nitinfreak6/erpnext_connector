<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds erp_driver tracking to existing tables so records from multiple ERPs
 * can coexist without collision.
 *
 * sync_mappings: adds erp_driver column (default 'odoo' backfills all existing rows).
 * connector_settings: adds erp_driver column to group settings per ERP instance.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── sync_mappings ────────────────────────────────────────────────
        // Add erp_driver so the same ERP product ID from two different ERPs
        // doesn't collide on the (entity_type, odoo_id) unique index.
        Schema::table('sync_mappings', function (Blueprint $table) {
            $table->string('erp_driver', 30)->default('odoo')->after('entity_type');
        });

        // Backfill existing rows (all Odoo)
        DB::table('sync_mappings')->update(['erp_driver' => 'odoo']);

        // Drop old unique constraints and recreate them to include erp_driver
        Schema::table('sync_mappings', function (Blueprint $table) {
            $table->dropUnique(['entity_type', 'odoo_id']);
            $table->dropUnique(['entity_type', 'shopify_id']);

            $table->unique(['erp_driver', 'entity_type', 'odoo_id'],    'sync_mappings_erp_entity_odoo_unique');
            $table->unique(['erp_driver', 'entity_type', 'shopify_id'], 'sync_mappings_erp_entity_shopify_unique');
        });

        // ── connector_settings ───────────────────────────────────────────
        // Allow the same key to exist per ERP driver (e.g. odoo.url, sap.url)
        Schema::table('connector_settings', function (Blueprint $table) {
            // The existing unique(['key']) index needs to become (erp_driver, key)
            $table->string('erp_driver', 30)->default('odoo')->after('group');
        });

        DB::table('connector_settings')->update(['erp_driver' => 'odoo']);

        Schema::table('connector_settings', function (Blueprint $table) {
            $table->dropUnique(['key']);
            $table->unique(['erp_driver', 'key'], 'connector_settings_driver_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('sync_mappings', function (Blueprint $table) {
            $table->dropUnique('sync_mappings_erp_entity_odoo_unique');
            $table->dropUnique('sync_mappings_erp_entity_shopify_unique');
            $table->dropColumn('erp_driver');
            $table->unique(['entity_type', 'odoo_id']);
            $table->unique(['entity_type', 'shopify_id']);
        });

        Schema::table('connector_settings', function (Blueprint $table) {
            $table->dropUnique('connector_settings_driver_key_unique');
            $table->dropColumn('erp_driver');
            $table->unique(['key']);
        });
    }
};
