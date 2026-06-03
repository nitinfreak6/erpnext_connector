<?php

namespace Database\Seeders;

use App\Models\ChannelMapping;
use Illuminate\Database\Seeder;

/**
 * Seeds placeholder / example rows for every ChannelMapping type.
 * Update odoo_id / external_id values to match your actual Odoo and Shopify IDs.
 *
 * Run: php artisan db:seed --class=ChannelMappingSeeder
 * Safe to re-run — uses updateOrCreate.
 */
class ChannelMappingSeeder extends Seeder
{
    public function run(): void
    {
        $shopify = ChannelMapping::CHANNEL_SHOPIFY;
        $amazon  = ChannelMapping::CHANNEL_AMAZON;

        // ── 1. Warehouse (Odoo stock.location ID → Shopify location ID) ──────
        // odoo_id   = Odoo stock.location integer ID (e.g. from stock.location search_read)
        // external_id = Shopify Location integer ID (found in Shopify Admin → Locations)
        $this->seed(ChannelMapping::TYPE_WAREHOUSE, [
            [
                'channel'       => $shopify,
                'odoo_id'       => '8',           // e.g. WH/Stock
                'odoo_label'    => 'WH/Stock (Main Warehouse)',
                'external_id'   => '0',           // replace with real Shopify location ID
                'external_label'=> 'Main Warehouse',
                'meta'          => ['odoo_value_field' => 'complete_name', 'external_value_field' => 'id'],
            ],
        ]);

        // ── 2. Shipping (Shopify shipping method title → Odoo delivery carrier ID) ─
        // odoo_id   = Odoo delivery.carrier integer ID
        // external_id = Shopify shipping line title (exact string from order)
        $this->seed(ChannelMapping::TYPE_SHIPPING, [
            [
                'channel'       => $shopify,
                'odoo_id'       => '1',           // Odoo delivery.carrier ID
                'odoo_label'    => 'Standard Delivery',
                'external_id'   => 'Standard Shipping',  // Shopify shipping title
                'external_label'=> 'Standard Shipping',
                'meta'          => ['odoo_value_field' => 'id', 'external_value_field' => 'title'],
            ],
            [
                'channel'       => $shopify,
                'odoo_id'       => '2',
                'odoo_label'    => 'Express Delivery',
                'external_id'   => 'Express Shipping',
                'external_label'=> 'Express Shipping',
                'meta'          => ['odoo_value_field' => 'id', 'external_value_field' => 'title'],
            ],
        ]);

        // ── 3. Category (Odoo product.category ID → Shopify product_type string) ──
        $this->seed(ChannelMapping::TYPE_CATEGORY, [
            [
                'channel'       => $shopify,
                'odoo_id'       => '1',           // Odoo product.category ID (All / Saleable)
                'odoo_label'    => 'All',
                'external_id'   => 'General',     // Shopify product_type value
                'external_label'=> 'General',
                'meta'          => ['odoo_value_field' => 'complete_name', 'external_value_field' => 'product_type'],
            ],
        ]);

        // ── 4. Pricelist (Shopify currency code → Odoo product.pricelist ID) ────
        $this->seed(ChannelMapping::TYPE_PRICELIST, [
            [
                'channel'       => $shopify,
                'odoo_id'       => '1',           // Odoo pricelist ID
                'odoo_label'    => 'Public Pricelist (INR)',
                'external_id'   => 'INR',         // Shopify currency code
                'external_label'=> 'Indian Rupee',
                'meta'          => ['odoo_value_field' => 'id', 'external_value_field' => 'currency'],
            ],
            [
                'channel'       => $shopify,
                'odoo_id'       => '2',
                'odoo_label'    => 'USD Pricelist',
                'external_id'   => 'USD',
                'external_label'=> 'US Dollar',
                'meta'          => ['odoo_value_field' => 'id', 'external_value_field' => 'currency'],
            ],
            [
                'channel'       => $amazon,
                'odoo_id'       => '1',
                'odoo_label'    => 'Public Pricelist',
                'external_id'   => 'INR',
                'external_label'=> 'Indian Rupee',
                'meta'          => ['odoo_value_field' => 'id'],
            ],
        ]);

        // ── 5. Payment (Shopify gateway → Odoo account.journal ID) ──────────
        $this->seed(ChannelMapping::TYPE_PAYMENT, [
            [
                'channel'       => $shopify,
                'odoo_id'       => '7',           // Odoo journal ID for bank/cash
                'odoo_label'    => 'Bank Journal',
                'external_id'   => 'shopify_payments', // Shopify gateway name
                'external_label'=> 'Shopify Payments',
                'meta'          => ['odoo_value_field' => 'id', 'external_value_field' => 'gateway'],
            ],
            [
                'channel'       => $shopify,
                'odoo_id'       => '6',
                'odoo_label'    => 'Cash Journal',
                'external_id'   => 'cash',
                'external_label'=> 'Cash',
                'meta'          => ['odoo_value_field' => 'id', 'external_value_field' => 'gateway'],
            ],
            [
                'channel'       => $shopify,
                'odoo_id'       => '7',
                'odoo_label'    => 'Bank Journal',
                'external_id'   => 'razorpay',
                'external_label'=> 'Razorpay',
                'meta'          => ['odoo_value_field' => 'id', 'external_value_field' => 'gateway'],
            ],
        ]);

        // ── 6. Channel (channel name → Odoo crm.team / sales team ID) ───────
        $this->seed(ChannelMapping::TYPE_CHANNEL, [
            [
                'channel'       => $shopify,
                'odoo_id'       => '1',           // Odoo crm.team ID
                'odoo_label'    => 'Sales Team',
                'external_id'   => 'shopify',     // channel identifier
                'external_label'=> 'Shopify',
                'meta'          => ['odoo_value_field' => 'id', 'external_value_field' => 'source'],
            ],
            [
                'channel'       => $amazon,
                'odoo_id'       => '2',
                'odoo_label'    => 'Amazon Sales Team',
                'external_id'   => 'amazon',
                'external_label'=> 'Amazon',
                'meta'          => ['odoo_value_field' => 'id'],
            ],
        ]);

        // ── 7. Sales Order Type (channel → Odoo sale.order.type ID) ─────────
        $this->seed(ChannelMapping::TYPE_SALES_ORDER_TYPE, [
            [
                'channel'       => $shopify,
                'odoo_id'       => '1',           // Odoo sale.order.type ID
                'odoo_label'    => 'Shopify Order',
                'external_id'   => 'shopify',
                'external_label'=> 'Shopify',
                'meta'          => ['odoo_value_field' => 'id'],
            ],
            [
                'channel'       => $amazon,
                'odoo_id'       => '2',
                'odoo_label'    => 'Amazon Order',
                'external_id'   => 'amazon',
                'external_label'=> 'Amazon',
                'meta'          => ['odoo_value_field' => 'id'],
            ],
        ]);

        // ── 8. Sales Rep (channel → Odoo res.users ID) ──────────────────────
        $this->seed(ChannelMapping::TYPE_SALES_REP, [
            [
                'channel'       => $shopify,
                'odoo_id'       => '2',           // Odoo res.users ID (Administrator = 2)
                'odoo_label'    => 'Administrator',
                'external_id'   => 'shopify',
                'external_label'=> 'Shopify',
                'meta'          => ['odoo_value_field' => 'id'],
            ],
            [
                'channel'       => $amazon,
                'odoo_id'       => '2',
                'odoo_label'    => 'Administrator',
                'external_id'   => 'amazon',
                'external_label'=> 'Amazon',
                'meta'          => ['odoo_value_field' => 'id'],
            ],
        ]);

        // ── 9. Product Size (Odoo attribute value → Shopify size option value) ─
        $this->seed(ChannelMapping::TYPE_PRODUCT_SIZE, [
            [
                'channel'       => $shopify,
                'odoo_id'       => 'S',
                'odoo_label'    => 'Small',
                'external_id'   => 'S',
                'external_label'=> 'Small',
                'meta'          => ['odoo_value_field' => 'name', 'external_value_field' => 'value'],
            ],
            [
                'channel'       => $shopify,
                'odoo_id'       => 'M',
                'odoo_label'    => 'Medium',
                'external_id'   => 'M',
                'external_label'=> 'Medium',
                'meta'          => ['odoo_value_field' => 'name', 'external_value_field' => 'value'],
            ],
            [
                'channel'       => $shopify,
                'odoo_id'       => 'L',
                'odoo_label'    => 'Large',
                'external_id'   => 'L',
                'external_label'=> 'Large',
                'meta'          => ['odoo_value_field' => 'name', 'external_value_field' => 'value'],
            ],
        ]);

        // ── 10. Tax (Shopify tax title → Odoo account.tax ID) ───────────────
        $this->seed(ChannelMapping::TYPE_TAX, [
            [
                'channel'       => $shopify,
                'odoo_id'       => '1',           // Odoo account.tax ID
                'odoo_label'    => 'CGST 9%',
                'external_id'   => 'CGST 9%',     // Shopify tax_lines[].title exact string
                'external_label'=> 'CGST 9%',
                'meta'          => ['odoo_value_field' => 'id', 'external_value_field' => 'title', 'default_value' => '1'],
            ],
            [
                'channel'       => $shopify,
                'odoo_id'       => '2',
                'odoo_label'    => 'SGST 9%',
                'external_id'   => 'SGST 9%',
                'external_label'=> 'SGST 9%',
                'meta'          => ['odoo_value_field' => 'id', 'external_value_field' => 'title'],
            ],
        ]);

        $this->command->info('ChannelMapping seeder complete.');
    }

    private function seed(string $type, array $rows): void
    {
        foreach ($rows as $row) {
            ChannelMapping::updateOrCreate(
                [
                    'type'       => $type,
                    'channel'    => $row['channel'],
                    'odoo_id'    => $row['odoo_id'],
                    'external_id'=> $row['external_id'],
                ],
                [
                    'odoo_label'    => $row['odoo_label']    ?? null,
                    'external_label'=> $row['external_label'] ?? null,
                    'meta'          => $row['meta']          ?? [],
                    'is_active'     => $row['is_active']     ?? true,
                ]
            );
        }
        $this->command->info("  ✓ {$type} (" . count($rows) . " rows)");
    }
}