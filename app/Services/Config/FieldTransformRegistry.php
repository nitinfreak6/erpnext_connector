<?php

namespace App\Services\Config;

/**
 * Driver-agnostic field transforms for product_field_configs.
 * UI lists these; FieldMappingService executes them for both sync directions.
 */
class FieldTransformRegistry
{
    /** @return list<array{value:string,label:string}> */
    public static function channelMapTypeOptions(): array
    {
        return [
            ['value' => 'warehouse', 'label' => 'Warehouse (Odoo location ↔ Shopify location)'],
            ['value' => 'shipping',  'label' => 'Shipping carrier'],
            ['value' => 'category',  'label' => 'Category / department'],
            ['value' => 'vendor',    'label' => 'Vendor / supplier'],
            ['value' => 'pricelist', 'label' => 'Pricelist'],
            ['value' => 'payment',   'label' => 'Payment method'],
            ['value' => 'tax',       'label' => 'Tax'],
            ['value' => 'channel',   'label' => 'Sales channel'],
        ];
    }

    /** @return list<array{value:string,label:string,directions:list<string>,param_label:?string,param_hint:?string,param_options?:list<array{value:string,label:string}>}> */
    public static function all(): array
    {
        return [
            [
                'value'       => 'resolve_country_id',
                'label'       => 'Country code/name → ERP country relation ID',
                'directions'  => ['ecom_to_erp'],
                'param_label' => null,
                'param_hint'  => null,
            ],
            [
                'value'       => 'resolve_state_id',
                'label'       => 'State/province code → ERP state relation ID',
                'directions'  => ['ecom_to_erp'],
                'param_label' => 'Country source field (ecom path)',
                'param_hint'  => 'e.g. defaultAddress.countryCodeV2',
            ],
            [
                'value'       => 'resolve_country_code',
                'label'       => 'ERP country relation → ISO country code',
                'directions'  => ['erp_to_ecom'],
                'param_label' => null,
                'param_hint'  => null,
            ],
            [
                'value'       => 'resolve_state_code',
                'label'       => 'ERP state relation → province/state code',
                'directions'  => ['erp_to_ecom'],
                'param_label' => 'Country source field (ERP path)',
                'param_hint'  => 'e.g. country_id',
            ],
            [
                'value'         => 'channel_map',
                'label'         => 'Channel map lookup (external ↔ ERP id)',
                'directions'    => ['ecom_to_erp', 'erp_to_ecom'],
                'param_label'   => 'Map type',
                'param_hint'    => 'e.g. warehouse, shipping, category',
                'param_options' => self::channelMapTypeOptions(),
            ],
            [
                'value'       => 'array_second',
                'label'       => 'Many2one label (relation [id, name] → name)',
                'directions'  => ['erp_to_ecom'],
                'param_label' => null,
                'param_hint'  => null,
            ],
            [
                'value'       => 'line_container',
                'label'       => 'Line container (map ERP lines → nested ecom array)',
                'directions'  => ['erp_to_ecom'],
                'entities'    => ['dispatch', 'sales_order'],
                'param_label' => null,
                'param_hint'  => 'Header: ecom_field = lineItemsByFulfillmentOrder.fulfillmentOrderLineItems (or lineItems), erp_field = moves',
            ],
            [
                'value'       => 'resolve_product_by_sku',
                'label'       => 'Resolve product by SKU/reference',
                'directions'  => ['ecom_to_erp'],
                'param_label' => null,
                'param_hint'  => null,
            ],
            [
                'value'       => 'resolve_fulfillment_order_id',
                'label'       => 'Shopify order → open FulfillmentOrder ID',
                'directions'  => ['erp_to_ecom'],
                'entities'    => ['dispatch'],
                'param_label' => null,
                'param_hint'  => 'Map erp_field to _ecom_order_id (injected linked Shopify order) or use Custom + this transform',
            ],
            [
                'value'       => 'resolve_fulfillment_line_item_id',
                'label'       => 'Odoo product → Shopify fulfillment order line item ID',
                'directions'  => ['erp_to_ecom'],
                'entities'    => ['dispatch'],
                'param_label' => null,
                'param_hint'  => 'Uses product SyncMapping + Shopify order/fulfillment order lines',
            ],
            [
                'value'       => 'resolve_inventory_item_id',
                'label'       => 'Resolve Shopify inventory item ID from product mapping',
                'directions'  => ['erp_to_ecom'],
                'entities'    => ['inventory'],
                'param_label' => null,
                'param_hint'  => 'Uses product SyncMapping + Shopify product lookup',
            ],
            [
                'value'         => 'sync_mapping',
                'label'         => 'Sync mapping lookup (ERP id → ecom id)',
                'directions'    => ['erp_to_ecom', 'ecom_to_erp'],
                'param_label'   => 'Entity type',
                'param_hint'    => 'e.g. inventory, product',
                'param_options' => [
                    ['value' => 'inventory', 'label' => 'Inventory'],
                    ['value' => 'product', 'label' => 'Product'],
                    ['value' => 'product_variant', 'label' => 'Product variant'],
                ],
            ],
            [
                'value'       => 'resolve_partner',
                'label'       => 'Resolve/create ERP partner by role',
                'directions'  => ['ecom_to_erp'],
                'param_label' => 'Partner role',
                'param_hint'  => 'e.g. supplier',
            ],
        ];
    }

    /** @return list<array{value:string,label:string,directions:list<string>,entities?:list<string>,param_label:?string,param_hint:?string}> */
    public static function forDirection(string $direction): array
    {
        return array_values(array_filter(
            self::all(),
            fn (array $t) => in_array($direction, $t['directions'], true)
        ));
    }

    /** @return list<array{value:string,label:string,directions:list<string>,entities?:list<string>,param_label:?string,param_hint:?string}> */
    public static function forEntityAndDirection(string $entityType, string $direction): array
    {
        return array_values(array_filter(
            self::all(),
            function (array $t) use ($entityType, $direction): bool {
                if (!in_array($direction, $t['directions'], true)) {
                    return false;
                }

                $entities = $t['entities'] ?? [];
                if ($entities !== [] && !in_array($entityType, $entities, true)) {
                    return false;
                }

                return true;
            }
        ));
    }

    /** @return array{base:string,param:string} */
    public static function parse(?string $stored): array
    {
        $stored = trim($stored ?? '');
        if ($stored === '') {
            return ['base' => '', 'param' => ''];
        }

        foreach (['channel_map:', 'resolve_state_id:', 'resolve_state_code:', 'resolve_partner:', 'sync_mapping:'] as $prefix) {
            if (str_starts_with($stored, $prefix)) {
                return [
                    'base'  => rtrim($prefix, ':'),
                    'param' => trim(substr($stored, strlen($prefix))),
                ];
            }
        }

        return ['base' => $stored, 'param' => ''];
    }

    public static function build(string $base, ?string $param = null): ?string
    {
        $base  = trim($base);
        $param = trim($param ?? '');

        if ($base === '') {
            return null;
        }

        $prefixed = ['channel_map', 'resolve_state_id', 'resolve_state_code', 'resolve_partner', 'sync_mapping'];

        if (in_array($base, $prefixed, true)) {
            return $param !== '' ? "{$base}:{$param}" : null;
        }

        return $base;
    }

    public static function labelFor(?string $stored): string
    {
        $parsed = self::parse($stored);
        if ($parsed['base'] === '') {
            return '—';
        }

        foreach (self::all() as $def) {
            if ($def['value'] === $parsed['base']) {
                $label = $def['label'];
                if ($parsed['param'] !== '') {
                    $label .= ' (' . $parsed['param'] . ')';
                }

                return $label;
            }
        }

        return $stored ?? '—';
    }
}
