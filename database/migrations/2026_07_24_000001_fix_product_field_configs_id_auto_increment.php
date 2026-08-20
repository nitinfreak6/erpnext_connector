<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Some installs (manual imports / host restores) end up with product_field_configs.id
 * as NOT NULL but without AUTO_INCREMENT, causing insert failures:
 * "Field 'id' doesn't have a default value".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('product_field_configs') || !Schema::hasColumn('product_field_configs', 'id')) {
            return;
        }

        $column = DB::selectOne("
            SELECT COLUMN_TYPE AS column_type, EXTRA AS extra, COLUMN_KEY AS column_key
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'product_field_configs'
              AND COLUMN_NAME = 'id'
        ");

        if (!$column) {
            return;
        }

        $extra = strtolower((string) ($column->extra ?? ''));

        if (str_contains($extra, 'auto_increment')) {
            return;
        }

        if (($column->column_key ?? '') !== 'PRI') {
            DB::statement('ALTER TABLE `product_field_configs` ADD PRIMARY KEY (`id`)');
        }

        DB::statement(
            'ALTER TABLE `product_field_configs` MODIFY `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT'
        );
    }

    public function down(): void
    {
        // Non-destructive — leave id as auto_increment.
    }
};
