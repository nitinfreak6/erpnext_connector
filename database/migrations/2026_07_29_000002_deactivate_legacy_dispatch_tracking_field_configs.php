<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Legacy seed dispatch rows (direction NULL) mapped tracking → carrier_tracking_ref
 * and were still active for some ecom→erp builds. Deactivate them — use explicit
 * ecom_to_erp configs in Field Config instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('product_field_configs')
            ->where('entity_type', 'dispatch')
            ->whereIn('erp_field', ['carrier_tracking_ref', 'carrier_id', 'state'])
            ->where(function ($q) {
                $q->whereNull('direction')->orWhere('direction', '!=', 'ecom_to_erp');
            })
            ->whereIn('ecom_field', ['tracking_number', 'tracking_company', 'status'])
            ->update(['is_active' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // No rollback — user may have intentionally disabled these.
    }
};
