<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds erp_driver tracking to existing tables so records from multiple ERPs
 * can coexist without collision.
 *
 * Idempotent — safe when erp_driver column was added manually or partially migrated.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('sync_mappings', 'erp_driver')) {
            Schema::table('sync_mappings', function (Blueprint $table) {
                $table->string('erp_driver', 30)->default('odoo')->after('entity_type');
            });

            DB::table('sync_mappings')->update(['erp_driver' => 'odoo']);
        }

        $this->rebuildSyncMappingsUniques();

        if (!Schema::hasColumn('connector_settings', 'erp_driver')) {
            Schema::table('connector_settings', function (Blueprint $table) {
                $table->string('erp_driver', 30)->default('odoo')->after('group');
            });

            DB::table('connector_settings')->update(['erp_driver' => 'odoo']);
        }

        $this->rebuildConnectorSettingsUniques();
    }

    public function down(): void
    {
        if (Schema::hasColumn('sync_mappings', 'erp_driver')) {
            $this->dropIndexIfExists('sync_mappings', 'sync_mappings_erp_entity_odoo_unique');
            $this->dropIndexIfExists('sync_mappings', 'sync_mappings_erp_entity_shopify_unique');

            Schema::table('sync_mappings', function (Blueprint $table) {
                $table->dropColumn('erp_driver');
            });
        }

        if (Schema::hasColumn('connector_settings', 'erp_driver')) {
            $this->dropIndexIfExists('connector_settings', 'connector_settings_driver_key_unique');

            Schema::table('connector_settings', function (Blueprint $table) {
                $table->dropColumn('erp_driver');
            });
        }
    }

    private function rebuildSyncMappingsUniques(): void
    {
        if (!Schema::hasColumn('sync_mappings', 'erp_driver')) {
            return;
        }

        $this->dropIndexIfExists('sync_mappings', 'sync_mappings_entity_type_odoo_id_unique');
        $this->dropIndexIfExists('sync_mappings', 'sync_mappings_entity_type_shopify_id_unique');
        $this->dropIndexIfExists('sync_mappings', 'sync_mappings_erp_entity_odoo_unique');
        $this->dropIndexIfExists('sync_mappings', 'sync_mappings_erp_entity_shopify_unique');

        $odooCol = Schema::hasColumn('sync_mappings', 'erp_id') ? 'erp_id' : 'odoo_id';
        $ecomCol = Schema::hasColumn('sync_mappings', 'ecom_id') ? 'ecom_id' : 'shopify_id';

        if (!$this->indexExists('sync_mappings', 'sync_mappings_erp_entity_odoo_unique')) {
            Schema::table('sync_mappings', function (Blueprint $table) use ($odooCol) {
                $table->unique(['erp_driver', 'entity_type', $odooCol], 'sync_mappings_erp_entity_odoo_unique');
            });
        }

        if (!$this->indexExists('sync_mappings', 'sync_mappings_erp_entity_shopify_unique')) {
            Schema::table('sync_mappings', function (Blueprint $table) use ($ecomCol) {
                $table->unique(['erp_driver', 'entity_type', $ecomCol], 'sync_mappings_erp_entity_shopify_unique');
            });
        }
    }

    private function rebuildConnectorSettingsUniques(): void
    {
        if (!Schema::hasColumn('connector_settings', 'erp_driver')) {
            return;
        }

        $this->dropIndexIfExists('connector_settings', 'connector_settings_key_unique');
        $this->dropIndexIfExists('connector_settings', 'connector_settings_driver_key_unique');

        if (!$this->indexExists('connector_settings', 'connector_settings_driver_key_unique')) {
            Schema::table('connector_settings', function (Blueprint $table) {
                $table->unique(['erp_driver', 'key'], 'connector_settings_driver_key_unique');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $row = DB::selectOne('
            SELECT 1 AS found
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = ?
              AND index_name = ?
            LIMIT 1
        ', [$table, $indexName]);

        return $row !== null;
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if (!$this->indexExists($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($indexName) {
            $blueprint->dropUnique($indexName);
        });
    }
};
