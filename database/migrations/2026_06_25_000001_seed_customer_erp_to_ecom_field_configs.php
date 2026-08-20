<?php

use App\Models\ProductFieldConfig;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Seeds Odoo → Shopify customer field mappings only (direction = erp_to_ecom).
 * Does not modify ecom_to_erp customer rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        $base = [
            'entity_type' => 'customer',
            'direction'   => 'erp_to_ecom',
            'ecom_driver' => 'shopify',
            'erp_driver'  => 'odoo',
            'scope'       => 'default',
            'is_active'   => true,
        ];

        $configs = [
            [
                'ecom_field'       => 'email',
                'ecom_field_label' => 'Email',
                'erp_field'        => 'email',
                'erp_field_label'  => 'Email',
                'field_type'       => 'default',
                'sort_order'       => 1,
            ],
            [
                'ecom_field'       => 'firstName',
                'ecom_field_label' => 'First Name',
                'erp_field'        => 'name',
                'erp_field_label'  => 'Name',
                'field_type'       => 'default',
                'sort_order'       => 2,
            ],
            [
                'ecom_field'       => 'lastName',
                'ecom_field_label' => 'Last Name',
                'erp_field'        => null,
                'erp_field_label'  => null,
                'field_type'       => 'custom',
                'default_value'    => '',
                'sort_order'       => 3,
            ],
            [
                'ecom_field'       => 'phone',
                'ecom_field_label' => 'Phone',
                'erp_field'        => 'phone',
                'erp_field_label'  => 'Phone',
                'field_type'       => 'default',
                'sort_order'       => 4,
            ],
            [
                'ecom_field'       => 'note',
                'ecom_field_label' => 'Note',
                'erp_field'        => 'comment',
                'erp_field_label'  => 'Notes',
                'field_type'       => 'default',
                'sort_order'       => 5,
            ],
        ];

        foreach ($configs as $config) {
            ProductFieldConfig::updateOrCreate(
                [
                    'entity_type' => $base['entity_type'],
                    'direction'   => $base['direction'],
                    'ecom_driver' => $base['ecom_driver'],
                    'erp_driver'  => $base['erp_driver'],
                    'ecom_field'  => $config['ecom_field'],
                    'scope'       => $base['scope'],
                ],
                array_merge($base, $config)
            );
        }

        Cache::forget(ProductFieldConfig::cacheKey('customer', 'shopify', 'odoo') . '_default_erp_to_ecom');
    }

    public function down(): void
    {
        ProductFieldConfig::query()
            ->where('entity_type', 'customer')
            ->where('direction', 'erp_to_ecom')
            ->where('ecom_driver', 'shopify')
            ->where('erp_driver', 'odoo')
            ->where('scope', 'default')
            ->whereIn('ecom_field', ['email', 'firstName', 'lastName', 'phone', 'note'])
            ->delete();

        Cache::forget(ProductFieldConfig::cacheKey('customer', 'shopify', 'odoo') . '_default_erp_to_ecom');
    }
};
