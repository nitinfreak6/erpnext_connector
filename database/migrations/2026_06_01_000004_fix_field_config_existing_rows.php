// database/migrations/2026_06_01_000004_fix_field_config_existing_rows.php

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = Schema::getColumnListing('product_field_configs');

        $sets = [
            "ecom_driver = COALESCE(NULLIF(ecom_driver,''), 'shopify')",
            "erp_driver  = COALESCE(NULLIF(erp_driver,''),  'odoo')",
            "entity_type = COALESCE(NULLIF(entity_type,''), 'product')",
        ];

        if (in_array('ecom_field', $columns) && in_array('shopify_field', $columns)) {
            $sets[] = "ecom_field = COALESCE(NULLIF(ecom_field,''), shopify_field)";
        }

        if (in_array('erp_field', $columns) && in_array('odoo_field', $columns)) {
            $sets[] = "erp_field = COALESCE(NULLIF(erp_field,''), odoo_field)";
        }

        if (in_array('erp_field_2', $columns) && in_array('odoo_field_2', $columns)) {
            $sets[] = "erp_field_2 = COALESCE(NULLIF(erp_field_2,''), odoo_field_2)";
        }

        $sql = "UPDATE product_field_configs SET " . implode(', ', $sets)
             . " WHERE ecom_field IS NULL OR ecom_field = ''";

        DB::statement($sql);
    }

    public function down(): void {}
};