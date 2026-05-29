<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Widen scope enum to include all entity scopes
        DB::statement("ALTER TABLE product_field_configs MODIFY COLUMN scope VARCHAR(50) NOT NULL DEFAULT 'header'");

        // Add all entity types to entity_definitions
        $entities = [
            ['entity_type' => 'product',                   'label' => 'Products',                    'scopes' => ['template','variant'],    'sort_order' => 10],
            ['entity_type' => 'customer',                  'label' => 'Customers',                   'scopes' => ['default'],               'sort_order' => 20],
            ['entity_type' => 'sales_order',               'label' => 'Sales Order',                 'scopes' => ['header','line'],         'sort_order' => 30],
            ['entity_type' => 'dispatch',                  'label' => 'Dispatch',                    'scopes' => ['header','line'],         'sort_order' => 40],
            ['entity_type' => 'sales_credit',              'label' => 'Sales Credit',                'scopes' => ['header','line'],         'sort_order' => 50],
            ['entity_type' => 'sales_credit_confirmation', 'label' => 'Sales Credit Confirmation',   'scopes' => ['header','line'],         'sort_order' => 60],
            ['entity_type' => 'blind_return',              'label' => 'Blind Return',                'scopes' => ['header','line'],         'sort_order' => 70],
            ['entity_type' => 'purchase_order',            'label' => 'Purchase Order',              'scopes' => ['header','line'],         'sort_order' => 80],
            ['entity_type' => 'receipt_order',             'label' => 'Receipt Order',               'scopes' => ['header','line'],         'sort_order' => 90],
            ['entity_type' => 'inventory',                 'label' => 'Inventory Sync',              'scopes' => ['default'],               'sort_order' => 100],
            ['entity_type' => 'inventory_adjustment',      'label' => 'Inventory Adjustment',        'scopes' => ['default'],               'sort_order' => 110],
        ];

        foreach ($entities as $entity) {
            $exists = DB::table('entity_definitions')
                ->where('entity_type', $entity['entity_type'])
                ->exists();

            if (!$exists) {
                DB::table('entity_definitions')->insert([
                    'entity_type' => $entity['entity_type'],
                    'label'       => $entity['label'],
                    'scopes'      => json_encode($entity['scopes']),
                    'is_active'   => true,
                    'sort_order'  => $entity['sort_order'],
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }
        }

        // Fix existing product field config rows that have 'template'/'variant' scope
        // — these are correct already, no change needed
    }

    public function down(): void
    {
        DB::table('entity_definitions')
            ->whereIn('entity_type', [
                'sales_credit', 'sales_credit_confirmation', 'blind_return',
                'purchase_order', 'receipt_order', 'inventory_adjustment',
            ])
            ->delete();
    }
};
