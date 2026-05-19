<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Update the direction enum to include new generic values
        DB::statement("ALTER TABLE sync_logs MODIFY COLUMN direction ENUM(
            'erp_to_ecom',
            'ecom_to_erp',
            'erp_to_shopify',
            'shopify_to_erp',
            'odoo_to_shopify',
            'shopify_to_odoo'
        )");
        
        // Update existing records to use new values
        DB::statement("UPDATE sync_logs SET direction = 'erp_to_ecom' WHERE direction = 'erp_to_shopify'");
        DB::statement("UPDATE sync_logs SET direction = 'erp_to_ecom' WHERE direction = 'odoo_to_shopify'");
        DB::statement("UPDATE sync_logs SET direction = 'ecom_to_erp' WHERE direction = 'shopify_to_erp'");
        DB::statement("UPDATE sync_logs SET direction = 'ecom_to_erp' WHERE direction = 'shopify_to_odoo'");
    }

    public function down(): void
    {
        // Revert to old enum values
        DB::statement("ALTER TABLE sync_logs MODIFY COLUMN direction ENUM(
            'erp_to_shopify',
            'shopify_to_erp',
            'odoo_to_shopify',
            'shopify_to_odoo'
        )");
        
        // Revert records back
        DB::statement("UPDATE sync_logs SET direction = 'erp_to_shopify' WHERE direction = 'erp_to_ecom'");
        DB::statement("UPDATE sync_logs SET direction = 'shopify_to_erp' WHERE direction = 'ecom_to_erp'");
    }
};