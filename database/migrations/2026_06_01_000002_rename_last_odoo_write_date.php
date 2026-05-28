<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Rename last_odoo_write_date → last_erp_write_date in sync_queue_state.
 * Add last_ecom_write_date for bidirectional cursor tracking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_queue_state', function (Blueprint $table) {
            // Only rename if old column still exists
            if (Schema::hasColumn('sync_queue_state', 'last_odoo_write_date')) {
                $table->renameColumn('last_odoo_write_date', 'last_erp_write_date');
            } elseif (!Schema::hasColumn('sync_queue_state', 'last_erp_write_date')) {
                $table->string('last_erp_write_date', 30)->nullable();
            }

            if (!Schema::hasColumn('sync_queue_state', 'last_ecom_write_date')) {
                $table->string('last_ecom_write_date', 30)->nullable()->after('last_erp_write_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sync_queue_state', function (Blueprint $table) {
            if (Schema::hasColumn('sync_queue_state', 'last_erp_write_date')) {
                $table->renameColumn('last_erp_write_date', 'last_odoo_write_date');
            }
            if (Schema::hasColumn('sync_queue_state', 'last_ecom_write_date')) {
                $table->dropColumn('last_ecom_write_date');
            }
        });
    }
};
