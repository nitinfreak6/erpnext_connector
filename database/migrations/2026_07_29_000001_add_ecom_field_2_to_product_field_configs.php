<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_field_configs', function (Blueprint $table) {
            if (!Schema::hasColumn('product_field_configs', 'ecom_field_2')) {
                $table->string('ecom_field_2', 100)->nullable()->after('ecom_field_label');
            }
            if (!Schema::hasColumn('product_field_configs', 'ecom_field_2_label')) {
                $table->string('ecom_field_2_label', 255)->nullable()->after('ecom_field_2');
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_field_configs', function (Blueprint $table) {
            if (Schema::hasColumn('product_field_configs', 'ecom_field_2_label')) {
                $table->dropColumn('ecom_field_2_label');
            }
            if (Schema::hasColumn('product_field_configs', 'ecom_field_2')) {
                $table->dropColumn('ecom_field_2');
            }
        });
    }
};
