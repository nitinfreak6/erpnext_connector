<?php

namespace App\Services\Erp\ErpNext;

use App\Models\ProductFieldConfig;
use App\Services\FieldMappingService;
use App\Services\SettingsService;
use Illuminate\Support\Collection;

class ErpNextInventoryService
{
    public function __construct(private readonly ErpNextService $api) {}

    /** @return list<array<string, mixed>> */
    public function getModifiedSince(string $writeDate, ?string $warehouse = null): array
    {
        $filters = [
            ['modified', '>', $this->normalizeDate($writeDate)],
        ];

        $warehouseField = $this->warehouseErpField('erp_to_ecom');
        $warehouseValue = $warehouse ?: $this->resolveDefaultWarehouseFilter();

        if ($warehouseField !== null && $warehouseValue !== null && $warehouseValue !== '') {
            $filters[] = [$warehouseField, '=', $warehouseValue];
        }

        $bins = $this->api->listDocs(
            'Bin',
            $filters,
            $this->inventoryFetchFields(),
            limit: 500,
        );

        return array_map(fn ($b) => $this->normalizeDocument($b), $bins);
    }

    /** @param  list<int|string>  $productIds */
    public function getForProducts(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $productField = $this->productErpField('erp_to_ecom') ?? $this->productErpField('ecom_to_erp');
        if ($productField === null) {
            throw new \RuntimeException(
                'Inventory fetch requires an inventory field config mapping SKU to an erp_field (e.g. item_code).'
            );
        }

        $filters = [[$productField, 'in', array_map('strval', $productIds)]];

        $warehouseField = $this->warehouseErpField('erp_to_ecom');
        $warehouseValue = $this->resolveDefaultWarehouseFilter();
        if ($warehouseField !== null && $warehouseValue !== null && $warehouseValue !== '') {
            $filters[] = [$warehouseField, '=', $warehouseValue];
        }

        $bins = $this->api->listDocs(
            'Bin',
            $filters,
            $this->inventoryFetchFields(),
            limit: 500,
        );

        return array_map(fn ($b) => $this->normalizeDocument($b), $bins);
    }

    public function availableQty(array $quant): int
    {
        $configs = $this->inventoryConfigs('erp_to_ecom');

        $availableField = $this->erpFieldForEcomPath($configs, 'quantities.0.quantity');
        if ($availableField !== null && isset($quant[$availableField]) && $quant[$availableField] !== '') {
            return (int) max(0, (float) $quant[$availableField]);
        }

        $actualField   = $this->erpFieldForCustomEcom($configs, '_actual_qty');
        $reservedField = $this->erpFieldForCustomEcom($configs, '_reserved_qty');

        if ($actualField === null || !array_key_exists($actualField, $quant)) {
            return 0;
        }

        $actual   = (float) ($quant[$actualField] ?? 0);
        $reserved = $reservedField !== null ? (float) ($quant[$reservedField] ?? 0) : 0.0;

        return (int) max(0, $actual - $reserved);
    }

    /** @param  array<string, mixed>  $payload  Mapped ERP payload from field configs */
    public function updateLevel(array $payload): void
    {
        $productField   = $this->productErpField('ecom_to_erp');
        $warehouseField = $this->warehouseErpField('ecom_to_erp');
        $quantityField  = $this->quantityErpField('ecom_to_erp');

        if ($productField === null || $warehouseField === null || $quantityField === null) {
            throw new \RuntimeException(
                'Inventory push requires ecom→erp field configs for product (item_code), warehouse, and quantity.'
            );
        }

        $itemCode = trim((string) ($payload[$productField] ?? ''));
        $rawWarehouse = trim((string) ($payload[$warehouseField] ?? ''));
        $shopifyLocation = null;

        if ($rawWarehouse !== ''
            && (ctype_digit($rawWarehouse) || str_contains($rawWarehouse, 'gid://shopify/Location/'))) {
            $shopifyLocation = $rawWarehouse;
            $rawWarehouse    = '';
        }

        $warehouse = $this->api->resolveWarehouse(
            $rawWarehouse !== '' ? $rawWarehouse : null,
            $shopifyLocation,
        );

        $qty = (float) ($payload[$quantityField] ?? 0);

        if ($itemCode === '' || $warehouse === '') {
            throw new \RuntimeException(
                "Inventory update missing mapped {$productField} or {$warehouseField} in field-config payload."
            );
        }

        $this->reconcileStockToQty($itemCode, $warehouse, $qty);
    }

    /** Set absolute on-hand qty via Stock Reconciliation (ERPNext has no update_stock_qty API). */
    private function reconcileStockToQty(string $itemCode, string $warehouse, float $targetQty): void
    {
        $currentQty = $this->currentStockQty($itemCode, $warehouse);

        if (abs($currentQty - $targetQty) < 0.0001) {
            return;
        }

        $company = $this->api->resolveCompanyForWarehouseOrDefault($warehouse);
        $now     = now();

        $inserted = $this->api->insertDoc('Stock Reconciliation', [
            'doctype'          => 'Stock Reconciliation',
            'purpose'          => 'Stock Reconciliation',
            'company'          => $company,
            'posting_date'     => $now->format('Y-m-d'),
            'posting_time'     => $now->format('H:i:s'),
            'set_posting_time' => 1,
            'items'            => [[
                'doctype'                   => 'Stock Reconciliation Item',
                'item_code'                 => $itemCode,
                'warehouse'                 => $warehouse,
                'qty'                       => $targetQty,
                'valuation_rate'            => $this->valuationRateForItem($itemCode, $warehouse),
                'allow_zero_valuation_rate' => 1,
            ]],
        ]);

        $name = trim((string) ($inserted['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('ERPNext Stock Reconciliation was not created.');
        }

        try {
            $this->api->callMethod('frappe.client.submit', [
                'doc' => $this->api->getDoc('Stock Reconciliation', $name),
            ]);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "Stock Reconciliation [{$name}] saved as draft but submit failed: " . $e->getMessage()
            );
        }
    }

    private function currentStockQty(string $itemCode, string $warehouse): float
    {
        try {
            $balance = $this->api->callMethod('erpnext.stock.utils.get_stock_balance', [
                'item_code' => $itemCode,
                'warehouse' => $warehouse,
            ]);

            return (float) $balance;
        } catch (\Throwable) {
            $rows = $this->api->listDocs('Bin', [
                ['item_code', '=', $itemCode],
                ['warehouse', '=', $warehouse],
            ], ['actual_qty'], limit: 1);

            return (float) ($rows[0]['actual_qty'] ?? 0);
        }
    }

    private function valuationRateForItem(string $itemCode, string $warehouse): float
    {
        $rows = $this->api->listDocs('Bin', [
            ['item_code', '=', $itemCode],
            ['warehouse', '=', $warehouse],
        ], ['valuation_rate'], limit: 1);

        $rate = (float) ($rows[0]['valuation_rate'] ?? 0);
        if ($rate > 0) {
            return $rate;
        }

        $item = $this->api->getDoc('Item', $itemCode, ['valuation_rate', 'standard_rate']);

        return (float) ($item['valuation_rate'] ?? $item['standard_rate'] ?? 0);
    }

    public function resolveProductIdByReference(string $reference): ?string
    {
        if ($reference === '') {
            return null;
        }

        $productField = $this->productErpField('ecom_to_erp')
            ?? $this->productVariantErpField();

        if ($productField === null) {
            $doc = $this->api->getDoc('Item', $reference);

            return $doc !== null ? (string) ($doc['name'] ?? '') : null;
        }

        $doc = $this->api->getDoc('Item', $reference)
            ?? $this->api->listDocs(
                'Item',
                [[$productField, '=', $reference]],
                ['name'],
                limit: 1,
            )[0] ?? null;

        if ($doc === null) {
            return null;
        }

        $idField = $this->productVariantErpField() ?? 'name';

        return (string) ($doc[$idField] ?? $doc['name'] ?? null);
    }

    /** @param  array<string, mixed>  $bin */
    public function productKeyFromBin(array $bin): string
    {
        $field = $this->productErpField('erp_to_ecom') ?? $this->productErpField('ecom_to_erp');

        return $field !== null ? (string) ($bin[$field] ?? '') : '';
    }

    /** @param  array<string, mixed>  $bin */
    public function warehouseKeyFromBin(array $bin): string
    {
        $field = $this->warehouseErpField('erp_to_ecom') ?? $this->warehouseErpField('ecom_to_erp');

        return $field !== null ? (string) ($bin[$field] ?? '') : '';
    }

    /** @param  array<string, mixed>  $bin */
    public function normalizeDocument(array $bin): array
    {
        $bin['id']         = (string) ($bin['name'] ?? '');
        $bin['write_date'] = $this->normalizeDate($bin['modified'] ?? '');

        $available = $this->availableQty($bin);
        $availableField = $this->erpFieldForEcomPath(
            $this->inventoryConfigs('erp_to_ecom'),
            'quantities.0.quantity',
        );

        if ($availableField !== null
            && (!array_key_exists($availableField, $bin) || $bin[$availableField] === null || $bin[$availableField] === '')) {
            $bin[$availableField] = $available;
        }

        return $bin;
    }

    /** @return Collection<int, ProductFieldConfig> */
    private function inventoryConfigs(string $direction): Collection
    {
        $settings = app(SettingsService::class);

        return app(FieldMappingService::class)->getMappings(
            'inventory',
            $settings->ecomDriver(),
            $settings->erpDriver(),
            'default',
            $direction,
        );
    }

    private function productErpField(string $direction): ?string
    {
        $configs = $this->inventoryConfigs($direction);

        $config = $configs->first(function (ProductFieldConfig $config) {
            if (($config->field_type ?? '') === 'custom') {
                return false;
            }

            $ecom = strtolower(trim((string) ($config->ecom_field ?? '')));
            $transform = FieldMappingService::effectiveSystemTransform(
                $config->transform,
                $config->reverse_transform,
            );

            return $ecom === 'sku'
                || str_ends_with($ecom, '.sku')
                || in_array($transform, ['resolve_product_by_sku', 'resolve_product_by_reference'], true);
        });

        return $config ? $this->configErpRoot($config) : null;
    }

    private function warehouseErpField(string $direction): ?string
    {
        $config = $this->inventoryConfigs($direction)->first(function (ProductFieldConfig $config) {
            $transform = FieldMappingService::effectiveSystemTransform(
                $config->transform,
                $config->reverse_transform,
            );

            return $transform === 'channel_map:warehouse';
        });

        $root = $config ? $this->configErpRoot($config) : null;

        return $root !== '' ? $root : null;
    }

    private function quantityErpField(string $direction): ?string
    {
        $config = $this->inventoryConfigs($direction)->first(function (ProductFieldConfig $config) {
            if (($config->field_type ?? '') === 'custom') {
                return false;
            }

            $root = $this->configErpRoot($config);
            if ($root === '') {
                return false;
            }

            return FieldMappingService::isInventoryPushQuantityErpField($root)
                && !FieldMappingService::isInventoryProductErpField($root)
                && !FieldMappingService::isInventoryLocationErpField($root);
        });

        $root = $config ? $this->configErpRoot($config) : null;

        return $root !== '' ? $root : null;
    }

    private function productVariantErpField(): ?string
    {
        $settings = app(SettingsService::class);
        $config   = app(FieldMappingService::class)->getMappings(
            'product',
            $settings->ecomDriver(),
            $settings->erpDriver(),
            'variant',
            'ecom_to_erp',
        )->first(fn (ProductFieldConfig $c) => strtolower(trim((string) ($c->ecom_field ?? ''))) === 'sku');

        return $config ? $this->configErpRoot($config) : null;
    }

    /** @param  Collection<int, ProductFieldConfig>  $configs */
    private function erpFieldForEcomPath(Collection $configs, string $ecomPath): ?string
    {
        $config = $configs->first(fn (ProductFieldConfig $c) => trim((string) ($c->ecom_field ?? '')) === $ecomPath);

        $root = $config ? $this->configErpRoot($config) : null;

        return $root !== '' ? $root : null;
    }

    /** @param  Collection<int, ProductFieldConfig>  $configs */
    private function erpFieldForCustomEcom(Collection $configs, string $ecomField): ?string
    {
        $config = $configs->first(fn (ProductFieldConfig $c) => ($c->field_type ?? '') === 'custom'
            && trim((string) ($c->ecom_field ?? '')) === $ecomField);

        $root = $config ? $this->configErpRoot($config) : null;

        return $root !== '' ? $root : null;
    }

    private function configErpRoot(ProductFieldConfig $config): string
    {
        return trim(explode('.', (string) ($config->erp_field ?? $config->odoo_field ?? ''))[0]);
    }

    private function resolveDefaultWarehouseFilter(): ?string
    {
        $maps = app(\App\Services\ChannelMappingService::class);

        // Prefer sole mapping when available (ERPNext: one Warehouse row is authoritative).
        if (method_exists($maps, 'soleActiveWarehouseOdooId')) {
            $sole = $maps->soleActiveWarehouseOdooId();
            if ($sole !== null && $sole !== '') {
                return (string) $sole;
            }
        }

        $mapped = $maps->defaultWarehouseOdooId();

        if ($mapped !== null && $mapped !== '') {
            return (string) $mapped;
        }

        $settingsWh = trim($this->api->defaultWarehouse());

        return $settingsWh !== '' ? $settingsWh : null;
    }

    private function normalizeDate(string $value): string
    {
        return $value === '' ? '2000-01-01 00:00:00' : date('Y-m-d H:i:s', strtotime($value) ?: time());
    }

    /**
     * Bin list reads — erp→ecom inventory configs only (ecom→erp write fields like quantity are not Bin columns).
     *
     * @return list<string>
     */
    private function inventoryFetchFields(): array
    {
        return $this->api->fetchFieldsForEntity('inventory', 'default', 'erp_to_ecom', [], true);
    }
}
