<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a `direction` discriminator so the SAME table can hold two independent
 * config sets:
 *   - 'erp_to_ecom'  → read erp_field (source) → write ecom_field (target)
 *   - 'ecom_to_erp'  → read ecom_field (source) → write erp_field (target)
 *
 * SAFETY: every existing row is backfilled to 'erp_to_ecom'. The erp→ecom
 * reader excludes only 'ecom_to_erp' rows, so erp→ecom behaviour is preserved
 * even if this backfill were skipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_field_configs', function (Blueprint $table) {
            // Nullable + default keeps inserts that don't set it = erp_to_ecom.
            $table->string('direction', 20)->default('erp_to_ecom')->after('entity_type');
            $table->index(['entity_type', 'direction', 'is_active'], 'pfc_entity_direction_active_idx');
        });

        // Backfill anything pre-existing (and any NULLs) to erp_to_ecom.
        DB::table('product_field_configs')
            ->whereNull('direction')
            ->orWhere('direction', '')
            ->update(['direction' => 'erp_to_ecom']);
    }

    public function down(): void
    {
        Schema::table('product_field_configs', function (Blueprint $table) {
            $table->dropIndex('pfc_entity_direction_active_idx');
            $table->dropColumn('direction');
        });
    }
};