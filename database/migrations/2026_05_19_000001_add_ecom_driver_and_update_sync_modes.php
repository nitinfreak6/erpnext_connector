<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add ecom_driver setting to make the platform multi-ecommerce capable.
 * Update sync mode terminology from 'erp_to_shopify' to 'erp_to_ecom'.
 * 
 * This enables the platform to support multiple ecommerce backends
 * (Shopify, WooCommerce, Magento, etc.) via driver pattern.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Add ecom_driver setting ──────────────────────────────────────
        $ecomDriverExists = DB::table('connector_settings')
            ->where('key', 'ecom_driver')
            ->exists();

        if (!$ecomDriverExists) {
            DB::table('connector_settings')->insert([
                'group'         => 'general',
                'key'           => 'ecom_driver',
                'label'         => 'E-commerce Platform',
                'value'         => 'shopify',
                'default_value' => 'shopify',
                'field_type'    => 'select', // Options: shopify, woocommerce, magento
                'is_secret'     => false,
                'is_active'     => true,
                'description'   => 'Select the e-commerce platform to sync with. Options: shopify, woocommerce, magento',
                'sort_order'    => 5,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }

        // ── Update sync mode values to use generic 'ecom' terminology ────
        $syncModeKeys = [
            'product_sync_mode',
            'customer_sync_mode',
            'sales_order_sync_mode',
            'dispatch_sync_mode',
        ];

        foreach ($syncModeKeys as $key) {
            $setting = DB::table('connector_settings')->where('key', $key)->first();
            
            if ($setting) {
                $newValue = match ($setting->value) {
                    'erp_to_shopify'   => 'erp_to_ecom',
                    'shopify_to_erp'   => 'ecom_to_erp',
                    'bidirectional'    => 'bidirectional',
                    default            => $setting->value,
                };

                $newDefaultValue = match ($setting->default_value) {
                    'erp_to_shopify'   => 'erp_to_ecom',
                    'shopify_to_erp'   => 'ecom_to_erp',
                    'bidirectional'    => 'bidirectional',
                    default            => $setting->default_value,
                };

                $newDescription = str_replace(
                    ['erp_to_shopify', 'shopify_to_erp'],
                    ['erp_to_ecom', 'ecom_to_erp'],
                    $setting->description ?? ''
                );

                DB::table('connector_settings')
                    ->where('key', $key)
                    ->update([
                        'value'         => $newValue,
                        'default_value' => $newDefaultValue,
                        'description'   => $newDescription,
                        'updated_at'    => now(),
                    ]);
            }
        }

        // ── Update display name keys ──────────────────────────────────────
        $displayNameExists = DB::table('connector_settings')
            ->where('key', 'ecom_display_name')
            ->exists();

        if (!$displayNameExists) {
            // Try to get the shopify_display_name value
            $shopifyDisplay = DB::table('connector_settings')
                ->where('key', 'shopify_display_name')
                ->value('value');

            DB::table('connector_settings')->insert([
                'group'         => 'general',
                'key'           => 'ecom_display_name',
                'label'         => 'E-commerce Display Name',
                'value'         => $shopifyDisplay ?: 'E-commerce',
                'default_value' => 'E-commerce',
                'field_type'    => 'text',
                'is_secret'     => false,
                'is_active'     => true,
                'description'   => 'Display name for the e-commerce platform in UI and logs',
                'sort_order'    => 6,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Revert sync mode values back to shopify-specific terminology
        $syncModeKeys = [
            'product_sync_mode',
            'customer_sync_mode',
            'sales_order_sync_mode',
            'dispatch_sync_mode',
        ];

        foreach ($syncModeKeys as $key) {
            $setting = DB::table('connector_settings')->where('key', $key)->first();
            
            if ($setting) {
                $oldValue = match ($setting->value) {
                    'erp_to_ecom'   => 'erp_to_shopify',
                    'ecom_to_erp'   => 'shopify_to_erp',
                    'bidirectional' => 'bidirectional',
                    default         => $setting->value,
                };

                $oldDefaultValue = match ($setting->default_value) {
                    'erp_to_ecom'   => 'erp_to_shopify',
                    'ecom_to_erp'   => 'shopify_to_erp',
                    'bidirectional' => 'bidirectional',
                    default         => $setting->default_value,
                };

                $oldDescription = str_replace(
                    ['erp_to_ecom', 'ecom_to_erp'],
                    ['erp_to_shopify', 'shopify_to_erp'],
                    $setting->description ?? ''
                );

                DB::table('connector_settings')
                    ->where('key', $key)
                    ->update([
                        'value'         => $oldValue,
                        'default_value' => $oldDefaultValue,
                        'description'   => $oldDescription,
                        'updated_at'    => now(),
                    ]);
            }
        }

        DB::table('connector_settings')->where('key', 'ecom_driver')->delete();
        DB::table('connector_settings')->where('key', 'ecom_display_name')->delete();
    }
};