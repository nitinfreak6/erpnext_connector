<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('sync_mappings', 'sync_message')) {
            Schema::table('sync_mappings', function (Blueprint $table) {
                $table->text('sync_message')->nullable()->after('ecom_status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sync_mappings', 'sync_message')) {
            Schema::table('sync_mappings', function (Blueprint $table) {
                $table->dropColumn('sync_message');
            });
        }
    }
};
