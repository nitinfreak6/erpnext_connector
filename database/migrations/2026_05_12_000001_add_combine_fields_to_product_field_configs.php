<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_field_configs', function (Blueprint $table) {
            // Second Odoo field for combine type
            $table->string('odoo_field_2', 100)->nullable()->after('odoo_field_label');
            $table->string('odoo_field_2_label', 255)->nullable()->after('odoo_field_2');

            // Separator used when combining two fields (default: space)
            $table->string('combine_separator', 20)->nullable()->default(' ')->after('odoo_field_2_label');
        });

        // Update the enum to add 'combine' type
        // MySQL requires re-defining the column for enum changes
        DB::statement("ALTER TABLE product_field_configs MODIFY COLUMN field_type ENUM('default', 'custom', 'combine') NOT NULL DEFAULT 'default'");
    }

    public function down(): void
    {
        Schema::table('product_field_configs', function (Blueprint $table) {
            $table->dropColumn(['odoo_field_2', 'odoo_field_2_label', 'combine_separator']);
        });

        DB::statement("ALTER TABLE product_field_configs MODIFY COLUMN field_type ENUM('default', 'custom') NOT NULL DEFAULT 'default'");
    }
};