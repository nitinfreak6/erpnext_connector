<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_field_configs', function (Blueprint $table) {
            if (!Schema::hasColumn('product_field_configs', 'conditions')) {
                $table->text('conditions')->nullable()->after('transform');
            }
        });

        // Prices: Odoo float is fine — drop number_format transforms.
        DB::table('product_field_configs')
            ->whereIn('transform', ['number_format', 'number_format_nullable'])
            ->update(['transform' => null]);

        // Legacy boolean_status → config conditions.
        DB::table('product_field_configs')
            ->where('transform', 'boolean_status')
            ->where(function ($q) {
                $q->whereNull('conditions')->orWhere('conditions', '');
            })
            ->update([
                'conditions' => '1:active, 0:draft, true:active, false:draft',
                'transform'  => null,
            ]);

        DB::table('product_field_configs')
            ->where('transform', 'status_to_boolean')
            ->where(function ($q) {
                $q->whereNull('conditions')->orWhere('conditions', '');
            })
            ->update([
                'conditions' => 'active:1, draft:0, published:1, true:1, false:0',
                'transform'  => null,
            ]);
    }

    public function down(): void
    {
        Schema::table('product_field_configs', function (Blueprint $table) {
            if (Schema::hasColumn('product_field_configs', 'conditions')) {
                $table->dropColumn('conditions');
            }
        });
    }
};
