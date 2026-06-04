<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Table may already exist from a previous migration run — skip if so.
        if (!Schema::hasTable('alert_notifications')) {
            Schema::create('alert_notifications', function (Blueprint $table) {
                $table->id();
                $table->string('alert_type');
                $table->string('status')->default('active');
                $table->string('send_to');
                $table->string('cc')->nullable();
                $table->string('bcc')->nullable();
                $table->string('subject');
                $table->longText('body');
                $table->timestamps();
            });
        }

        // Remove old system-alert rows seeded by the first migration run.
        // System alerts are now hardcoded — no longer stored in DB.
        DB::table('alert_notifications')->whereIn('alert_type', [
            'pending_orders', 'pending_dispatch', 'pending_purchase_orders',
            'pending_products', 'pending_customers', 'pending_stock_sync', 'php_error',
        ])->delete();

        // Add alert_email to connector_settings for system alerts recipient.
        if (!DB::table('connector_settings')->where('key', 'alert_email')->exists()) {
            DB::table('connector_settings')->insert([
                'group'       => 'general',
                'key'         => 'alert_email',
                'label'       => 'System Alert Email',
                'value'       => '',
                'is_secret'   => false,
                'is_active'   => true,
                'description' => 'Email address that receives all system alerts (pending orders, dispatch, PHP errors, etc.)',
                'sort_order'  => 99,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_notifications');
        DB::table('connector_settings')->where('key', 'alert_email')->delete();
    }
};