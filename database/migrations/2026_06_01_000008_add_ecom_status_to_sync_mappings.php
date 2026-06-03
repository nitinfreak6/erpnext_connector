<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_mappings', function (Blueprint $table) {
            $table->string('ecom_status', 20)->nullable()->after('last_sync_direction');
            // pending = fetched from ecom, not yet posted to ERP
            // posted  = successfully posted to ERP
            // failed  = failed to post to ERP
        });
    }

    public function down(): void
    {
        Schema::table('sync_mappings', function (Blueprint $table) {
            $table->dropColumn('ecom_status');
        });
    }
};
