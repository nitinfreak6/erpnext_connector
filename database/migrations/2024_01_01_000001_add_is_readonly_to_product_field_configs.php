<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds is_readonly to product_field_configs.
 *
 * When is_readonly = true, the field is computed by the ERP
 * (e.g. amount_total, amount_untaxed in Odoo) and will be skipped
 * when building the ERP payload on create / update.
 * Admins toggle this from the Field Config admin UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_field_configs', function (Blueprint $table) {
            $table->boolean('is_readonly')
                  ->default(false)
                  ->after('is_active')
                  ->comment('If true, this field is computed by the ERP and must not be sent on create/update.');
        });
    }

    public function down(): void
    {
        Schema::table('product_field_configs', function (Blueprint $table) {
            $table->dropColumn('is_readonly');
        });
    }
};
