<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE sync_logs MODIFY COLUMN direction ENUM(
            'erp_to_ecom',
            'ecom_to_erp',
            'erp_to_shopify',
            'shopify_to_erp',
            'odoo_to_shopify',
            'shopify_to_odoo'
        ) NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE sync_logs MODIFY COLUMN direction ENUM(
            'odoo_to_shopify',
            'shopify_to_odoo'
        ) NOT NULL");
    }
};
