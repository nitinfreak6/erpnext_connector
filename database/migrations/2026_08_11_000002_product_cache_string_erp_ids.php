<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ERPNext uses string document names (e.g. ITEM-001) as product IDs.
 * Widen product_cache ID columns so ERPNext items cache correctly.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('product_cache')) {
            return;
        }

        $this->dropUniqueIndexIfExists('product_cache', 'product_cache_odoo_id_unique');

        if (Schema::hasColumn('product_cache', 'odoo_id')) {
            DB::statement('ALTER TABLE `product_cache` MODIFY `odoo_id` VARCHAR(100) NULL');
        }

        if (Schema::hasColumn('product_cache', 'erp_id')) {
            DB::statement('ALTER TABLE `product_cache` MODIFY `erp_id` VARCHAR(100) NULL');
        }

        DB::statement('UPDATE product_cache SET erp_id = COALESCE(erp_id, odoo_id) WHERE erp_id IS NULL AND odoo_id IS NOT NULL');
    }

    public function down(): void
    {
        if (!Schema::hasTable('product_cache')) {
            return;
        }

        if (Schema::hasColumn('product_cache', 'odoo_id')) {
            DB::statement('ALTER TABLE `product_cache` MODIFY `odoo_id` INT UNSIGNED NULL');
        }

        if (Schema::hasColumn('product_cache', 'erp_id')) {
            DB::statement('ALTER TABLE `product_cache` MODIFY `erp_id` INT UNSIGNED NULL');
        }
    }

    private function dropUniqueIndexIfExists(string $table, string $indexName): void
    {
        $exists = DB::selectOne("
            SELECT 1 AS found
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = ?
              AND index_name = ?
            LIMIT 1
        ", [$table, $indexName]);

        if ($exists) {
            DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$indexName}`");
        }
    }
};
