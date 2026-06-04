<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('alert_type');          // e.g. 'pending_orders'
            $table->string('status')->default('active'); // active | inactive
            $table->string('send_to');             // primary recipient email(s)
            $table->string('cc')->nullable();
            $table->string('bcc')->nullable();
            $table->string('subject');
            $table->longText('body');              // HTML body with {body} placeholder
            $table->timestamps();
        });

        // ── Seed default alerts ───────────────────────────────────────────
        $defaults = [
            [
                'alert_type' => 'pending_orders',
                'subject'    => 'Pending Orders Alert',
                'body'       => '<p>Hello Team,</p><p>The following orders have been pending for more than 8 hours and require attention:</p>{body}<p>Kind regards<br>b.solutions</p>',
            ],
            [
                'alert_type' => 'pending_dispatch',
                'subject'    => 'Pending Dispatch Alert',
                'body'       => '<p>Hello Team,</p><p>The following dispatches have been pending for more than 8 hours after dispatch confirmation was received:</p>{body}<p>Kind regards<br>b.solutions</p>',
            ],
            [
                'alert_type' => 'pending_purchase_orders',
                'subject'    => 'Pending Purchase Orders Alert',
                'body'       => '<p>Hello Team,</p><p>The following purchase orders have been pending for more than 8 hours:</p>{body}<p>Kind regards<br>b.solutions</p>',
            ],
            [
                'alert_type' => 'pending_products',
                'subject'    => 'Pending Products Sync Alert',
                'body'       => '<p>Hello Team,</p><p>The following products are pending sync and have not been pushed:</p>{body}<p>Kind regards<br>b.solutions</p>',
            ],
            [
                'alert_type' => 'pending_customers',
                'subject'    => 'Pending Customers Sync Alert',
                'body'       => '<p>Hello Team,</p><p>The following customers are pending sync:</p>{body}<p>Kind regards<br>b.solutions</p>',
            ],
            [
                'alert_type' => 'pending_stock_sync',
                'subject'    => 'Pending Stock Sync Alert',
                'body'       => '<p>Hello Team,</p><p>The following stock items are pending sync:</p>{body}<p>Kind regards<br>b.solutions</p>',
            ],
            [
                'alert_type' => 'php_error',
                'subject'    => 'Application Error Alert',
                'body'       => '<p>Hello Team,</p><p>The following PHP errors were detected in the application:</p>{body}<p>Kind regards<br>b.solutions</p>',
            ],
        ];

        foreach ($defaults as $alert) {
            DB::table('alert_notifications')->insert(array_merge($alert, [
                'status'     => 'active',
                'send_to'    => '',   // admin must set their email
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_notifications');
    }
};