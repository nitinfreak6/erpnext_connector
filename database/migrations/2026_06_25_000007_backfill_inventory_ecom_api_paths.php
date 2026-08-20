<?php

use App\Models\ProductFieldConfig;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Backfill ecom_api_path on inventory erp→ecom configs when missing (GraphQL wire builder).
 */
return new class extends Migration
{
    private array $paths = [
        'available'           => 'quantities.0.quantity',
        'quantity'            => 'quantities.0.quantity',
        'inventory_quantity'  => 'quantities.0.quantity',
        'shopify_location_id' => 'quantities.0.locationId',
        'location_id'         => 'quantities.0.locationId',
        'name'                => 'name',
        'reason'              => 'reason',
        // Legacy aliases (pre-rename)
        'quantity_name'       => 'name',
        'adjustment_reason'   => 'reason',
    ];

    public function up(): void
    {
        foreach ($this->paths as $ecomField => $apiPath) {
            ProductFieldConfig::query()
                ->where('entity_type', 'inventory')
                ->where('direction', 'erp_to_ecom')
                ->where('ecom_driver', 'shopify')
                ->where('erp_driver', 'odoo')
                ->where('scope', 'default')
                ->where('ecom_field', $ecomField)
                ->where(function ($q) {
                    $q->whereNull('ecom_api_path')->orWhere('ecom_api_path', '');
                })
                ->update(['ecom_api_path' => $apiPath]);
        }

        // Ensure GraphQL defaults exist for name/reason.
        $base = [
            'entity_type' => 'inventory',
            'direction'   => 'erp_to_ecom',
            'ecom_driver' => 'shopify',
            'erp_driver'  => 'odoo',
            'scope'       => 'default',
            'is_active'   => true,
        ];

        foreach ([
            ['name', 'Quantity state name (GraphQL)', 'name', 'available', 10],
            ['reason', 'Adjustment reason (GraphQL)', 'reason', 'correction', 11],
        ] as [$ef, $label, $path, $default, $sort]) {
            ProductFieldConfig::updateOrCreate(
                array_merge($base, ['ecom_field' => $ef]),
                array_merge($base, [
                    'ecom_field_label' => $label,
                    'erp_field'        => null,
                    'field_type'       => 'custom',
                    'default_value'    => $default,
                    'ecom_api_path'    => $path,
                    'sort_order'       => $sort,
                ])
            );
        }

        Cache::forget('field_configs_inventory_shopify_odoo_default_erp_to_ecom');
    }

    public function down(): void
    {
        // Non-destructive backfill — no down.
    }
};
