<?php

namespace Database\Seeders;

use App\Models\ChannelMapping;
use App\Models\ProductFieldConfig;
use App\Services\SettingsService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

/**
 * Seeds ecom → ERP field mappings and channel mappings.
 *
 * Field configs use GraphQL / fetch keys (descriptionHtml, category.fullName, …)
 * matching ShopifyProductService::decodeGraphqlProduct() — NOT hardcoded
 * renames in code. Transforms mirror the erp→ecom set in reverse.
 *
 * Run:
 *   php artisan db:seed --class=EcomToErpSeeder
 *
 * Safe to re-run — uses updateOrCreate on each row.
 * Update odoo_id / external_id values in channel rows for your environment.
 */
class EcomToErpSeeder extends Seeder
{
    public function run(): void
    {
        [$ecom, $erp] = $this->resolveDrivers();

        if ($this->command) {
            $this->command->info("Seeding ecom→erp configs for {$ecom} → {$erp}…");
        }

        $fieldCount = $this->seedProductFieldConfigs($ecom, $erp);
        $mapCount   = $this->seedChannelMappings();

        Cache::flush();

        if ($this->command) {
            $this->command->info("EcomToErp seeder complete: {$fieldCount} field config row(s), {$mapCount} channel mapping row(s).");
        }
    }

    private function resolveDrivers(): array
    {
        try {
            $settings = app(SettingsService::class);
            $ecom     = $settings->ecomDriver();
            $erp      = $settings->erpDriver();
        } catch (\Throwable $e) {
            $ecom = config('connectors.default_ecom', env('ECOM_DRIVER', 'shopify'));
            $erp  = config('connectors.default_erp', env('ERP_DRIVER', 'odoo'));
        }

        return [$ecom, $erp];
    }

    private function seedProductFieldConfigs(string $ecom, string $erp): int
    {
        $rows = array_merge(
            $this->productTemplateMappings(),
            $this->productVariantMappings(),
            $this->productCustomDefaults(),
            $this->scaffoldMappings(),
        );

        foreach ($rows as $row) {
            ProductFieldConfig::updateOrCreate(
                [
                    'entity_type' => $row['entity_type'],
                    'direction'   => 'ecom_to_erp',
                    'ecom_driver' => $ecom,
                    'erp_driver'  => $erp,
                    'ecom_field'  => $row['ecom_field'],
                    'scope'       => $row['scope'],
                ],
                [
                    'ecom_field_label' => $row['ecom_field_label'] ?? $this->label($row['ecom_field']),
                    'erp_field'        => $row['erp_field'],
                    'erp_field_label'  => $row['erp_field_label'] ?? $this->label($row['erp_field']),
                    'field_type'       => $row['field_type'] ?? 'default',
                    'transform'        => $row['transform'] ?? null,
                    'default_value'    => $row['default_value'] ?? null,
                    'is_active'        => $row['is_active'] ?? true,
                    'is_readonly'      => $row['is_readonly'] ?? false,
                    'sort_order'       => $row['sort_order'] ?? 0,
                ]
            );
        }

        return count($rows);
    }

    /**
     * Template-scope — read from product root.
     */
    private function productTemplateMappings(): array
    {
        return [
            [
                'entity_type' => 'product',
                'ecom_field'  => 'title',
                'erp_field'   => 'name',
                'scope'       => 'template',
                'sort_order'  => 1,
            ],
            [
                'entity_type' => 'product',
                'ecom_field'  => 'descriptionHtml',
                'erp_field'   => 'description_sale',
                'scope'       => 'template',
                'transform'   => 'strip_tags',
                'sort_order'  => 2,
            ],
            [
                'entity_type'      => 'product',
                'ecom_field'       => 'category.fullName',
                'ecom_field_label' => 'Category (taxonomy full path)',
                'erp_field'        => 'categ_id',
                'scope'            => 'template',
                'transform'        => 'channel_map:category',
                'sort_order'       => 3,
            ],
            [
                'entity_type'      => 'product',
                'ecom_field'       => 'category.name',
                'ecom_field_label' => 'Category (taxonomy leaf name)',
                'erp_field'        => 'categ_id',
                'scope'            => 'template',
                'transform'        => 'channel_map:category',
                'is_active'        => false, // fallback if you prefer leaf name over fullName
                'sort_order'       => 4,
            ],
            [
                'entity_type'      => 'product',
                'ecom_field'       => 'productType',
                'ecom_field_label' => 'Product type (custom — not Shopify category)',
                'erp_field'        => 'categ_id',
                'scope'            => 'template',
                'transform'        => 'channel_map:category',
                'is_active'        => false, // productType ≠ Shopify Standard Product Category
                'sort_order'       => 5,
            ],
            [
                'entity_type' => 'product',
                'ecom_field'  => 'collections.0.title',
                'erp_field'   => 'categ_id',
                'scope'       => 'template',
                'transform'   => 'channel_map:category',
                'is_active'   => false, // enable if you map Shopify collections → Odoo categories
                'sort_order'  => 6,
            ],
            [
                'entity_type'      => 'product',
                'ecom_field'       => 'vendor',
                'ecom_field_label' => 'Vendor',
                'erp_field'        => 'seller_ids',
                'scope'            => 'template',
                'transform'        => 'resolve_partner:supplier',
                'sort_order'       => 7,
            ],
            [
                'entity_type'      => 'product',
                'ecom_field'       => 'vendor',
                'ecom_field_label' => 'Vendor (text only)',
                'erp_field'        => 'description_picking',
                'scope'            => 'template',
                'is_active'        => false, // optional: store vendor name as text, not as Odoo supplier
                'sort_order'       => 8,
            ],
            [
                'entity_type' => 'product',
                'ecom_field'  => 'tags',
                'erp_field'   => 'website_meta_keywords',
                'scope'       => 'template',
                'sort_order'  => 9,
            ],
            [
                'entity_type' => 'product',
                'ecom_field'  => 'status',
                'erp_field'   => 'is_published',
                'scope'       => 'template',
                'transform'   => 'status_to_boolean',
                'sort_order'  => 10,
            ],
        ];
    }

    /**
     * Variant-scope — read from variants[0].
     */
    private function productVariantMappings(): array
    {
        return [
            [
                'entity_type' => 'product',
                'ecom_field'  => 'sku',
                'erp_field'   => 'default_code',
                'scope'       => 'variant',
                'sort_order'  => 10,
            ],
            [
                'entity_type' => 'product',
                'ecom_field'  => 'price',
                'erp_field'   => 'list_price',
                'scope'       => 'variant',
                'transform'   => 'parse_float',
                'sort_order'  => 11,
            ],
            [
                'entity_type' => 'product',
                'ecom_field'  => 'compareAtPrice',
                'erp_field'   => 'standard_price',
                'scope'       => 'variant',
                'transform'   => 'parse_float_nullable',
                'sort_order'  => 12,
            ],
            [
                'entity_type' => 'product',
                'ecom_field'  => 'barcode',
                'erp_field'   => 'barcode',
                'scope'       => 'variant',
                'sort_order'  => 13,
            ],
            [
                'entity_type' => 'product',
                'ecom_field'  => 'inventoryItem.measurement.weight.value',
                'erp_field'   => 'weight',
                'scope'       => 'variant',
                'transform'   => 'parse_float',
                'default_value' => '0',
                'sort_order'  => 14,
            ],
        ];
    }

    /**
     * Odoo create defaults — field_type custom (no ecom source).
     */
    private function productCustomDefaults(): array
    {
        return [
            [
                'entity_type'  => 'product',
                'ecom_field'   => 'sale_ok',
                'erp_field'    => 'sale_ok',
                'scope'        => 'template',
                'field_type'   => 'custom',
                'default_value'=> '1',
                'sort_order'   => 20,
            ],
            [
                'entity_type'  => 'product',
                'ecom_field'   => 'purchase_ok',
                'erp_field'    => 'purchase_ok',
                'scope'        => 'template',
                'field_type'   => 'custom',
                'default_value'=> '1',
                'sort_order'   => 21,
            ],
            [
                'entity_type'  => 'product',
                'ecom_field'   => 'active',
                'erp_field'    => 'active',
                'scope'        => 'template',
                'field_type'   => 'custom',
                'default_value'=> '1',
                'sort_order'   => 22,
            ],
            [
                'entity_type'  => 'product',
                'ecom_field'   => 'type',
                'erp_field'    => 'type',
                'scope'        => 'template',
                'field_type'   => 'custom',
                'default_value'=> 'consu',
                'sort_order'   => 23,
            ],
            [
                'entity_type'      => 'product',
                'ecom_field'       => 'currency_id',
                'ecom_field_label' => 'Currency (INR default)',
                'erp_field'        => 'currency_id',
                'scope'            => 'template',
                'field_type'       => 'custom',
                'default_value'    => '20', // replace with your Odoo res.currency ID for INR
                'is_active'        => false,
                'sort_order'       => 24,
            ],
            [
                'entity_type'      => 'product',
                'ecom_field'       => 'cost_currency_id',
                'ecom_field_label' => 'Cost currency (INR default)',
                'erp_field'        => 'cost_currency_id',
                'scope'            => 'template',
                'field_type'       => 'custom',
                'default_value'    => '20',
                'is_active'        => false,
                'sort_order'       => 25,
            ],
        ];
    }

    /**
     * Other entities — inactive until verified against your Odoo models.
     */
    private function scaffoldMappings(): array
    {
        return [
            [
                'entity_type' => 'sales_order',
                'ecom_field'  => 'name',
                'erp_field'   => 'client_order_ref',
                'scope'       => 'header',
                'is_active'   => false,
                'sort_order'  => 1,
            ],
            [
                'entity_type' => 'sales_order',
                'ecom_field'  => 'email',
                'erp_field'   => 'partner_id',
                'scope'       => 'header',
                'is_active'   => false,
                'sort_order'  => 2,
            ],
            [
                'entity_type' => 'inventory',
                'ecom_field'  => 'sku',
                'erp_field'   => 'default_code',
                'scope'       => 'default',
                'is_active'   => false,
                'sort_order'  => 1,
            ],
        ];
    }

    /**
     * Channel mappings used by channel_map:* transforms (ecom value → Odoo id).
     */
    private function seedChannelMappings(): int
    {
        $shopify = ChannelMapping::CHANNEL_SHOPIFY;
        $count   = 0;

        $categories = [
            // Replace odoo_id with a real product.category ID from YOUR Odoo instance.
            // In Odoo: Inventory → Configuration → Product Categories (note the database ID).
            [
                'odoo_id'        => '1',
                'odoo_label'     => 'All / Saleable',
                'external_id'    => 'General',
                'external_label' => 'General',
                'is_active'      => false,
            ],
            [
                'odoo_id'        => '1',
                'odoo_label'     => 'All / Saleable',
                'external_id'    => 'Default',
                'external_label' => 'Default',
                'is_active'      => false,
            ],
            // Map Shopify Standard Product Category (taxonomy) — NOT productType.
            // external_id = category.fullName and/or leaf category.name from fetched JSON.
        ];

        foreach ($categories as $row) {
            ChannelMapping::updateOrCreate(
                [
                    'type'        => ChannelMapping::TYPE_CATEGORY,
                    'channel'     => $shopify,
                    'external_id' => $row['external_id'],
                ],
                [
                    'odoo_id'        => $row['odoo_id'],
                    'odoo_label'     => $row['odoo_label'],
                    'external_label' => $row['external_label'],
                    'meta'           => [
                        'direction'            => 'ecom_to_erp',
                        'external_value_field' => 'category.fullName',
                        'odoo_value_field'     => 'id',
                    ],
                    'is_active'      => $row['is_active'] ?? true,
                ]
            );
            $count++;
        }

        // Vendor (Shopify vendor string → Odoo res.partner ID). Optional — resolve_partner:supplier auto-creates partners.
        $vendors = [
            [
                'odoo_id'        => '0', // replace with real res.partner ID
                'odoo_label'     => 'Example Supplier',
                'external_id'    => 'immiteststore2',
                'external_label' => 'immiteststore2',
                'is_active'      => false,
            ],
        ];

        foreach ($vendors as $row) {
            ChannelMapping::updateOrCreate(
                [
                    'type'        => 'vendor',
                    'channel'     => $shopify,
                    'external_id' => $row['external_id'],
                ],
                [
                    'odoo_id'        => $row['odoo_id'],
                    'odoo_label'     => $row['odoo_label'],
                    'external_label' => $row['external_label'],
                    'meta'           => [
                        'direction'            => 'ecom_to_erp',
                        'external_value_field' => 'vendor',
                        'odoo_value_field'     => 'id',
                    ],
                    'is_active'      => $row['is_active'] ?? true,
                ]
            );
            $count++;
        }

        $warehouses = [
            [
                'odoo_id'        => '8',
                'odoo_label'     => 'WH/Stock',
                'external_id'    => '0', // replace with Shopify location ID
                'external_label' => 'Main Warehouse',
            ],
        ];

        foreach ($warehouses as $row) {
            ChannelMapping::updateOrCreate(
                [
                    'type'        => ChannelMapping::TYPE_WAREHOUSE,
                    'channel'     => $shopify,
                    'external_id' => $row['external_id'],
                ],
                [
                    'odoo_id'        => $row['odoo_id'],
                    'odoo_label'     => $row['odoo_label'],
                    'external_label' => $row['external_label'],
                    'meta'           => ['direction' => 'ecom_to_erp'],
                    'is_active'      => false, // enable when inventory ecom→erp is wired
                ]
            );
            $count++;
        }

        return $count;
    }

    private function label(string $key): string
    {
        return ucwords(preg_replace('/[._]/', ' ', $key) ?? $key);
    }
}
