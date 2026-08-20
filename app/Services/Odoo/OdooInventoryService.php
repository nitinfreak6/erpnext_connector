<?php

namespace App\Services\Odoo;

use App\Models\ProductFieldConfig;
use App\Services\FieldMappingService;
use Illuminate\Support\Collection;

class OdooInventoryService
{
    private const QUANT_REQUIRED = [
        'id', 'product_id', 'location_id', 'quantity', 'reserved_quantity', 'write_date',
    ];

    public function __construct(
        private readonly OdooService $odoo,
        private readonly FieldMappingService $fieldMapping,
    ) {}

    /**
     * Get inventory quants modified since write_date, filtered to internal locations only.
     */
    public function getModifiedSince(string $writeDate, ?int $locationId = null, ?array $fields = null): array
    {
        $domain = [
            ['write_date', '>', $writeDate],
            ['location_id.usage', '=', 'internal'],
        ];

        if ($locationId) {
            $domain[] = ['location_id', '=', $locationId];
        }

        return $this->odoo->searchRead(
            'stock.quant',
            $domain,
            $fields ?? $this->quantFields(),
            ['order' => 'write_date asc', 'limit' => 1000]
        );
    }

    /**
     * Get all quants for active products (full sync).
     */
    public function getAllForProducts(array $productIds, ?array $fields = null): array
    {
        return $this->odoo->searchRead(
            'stock.quant',
            [
                ['product_id', 'in', $productIds],
                ['location_id.usage', '=', 'internal'],
            ],
            $fields ?? $this->quantFields()
        );
    }

    /**
     * Calculate the sellable qty from a raw quant record.
     */
    public function availableQty(array $quant): int
    {
        return (int) max(0, ($quant['quantity'] ?? 0) - ($quant['reserved_quantity'] ?? 0));
    }

    /**
     * Set on-hand quantity for a product at a location — 100% field-config driven.
     * Mapped payload keys match active inventory ecom→erp erp_field names.
     */
    public function updateLevel(array $payload): void
    {
        $configs = $this->fieldMapping->getInventoryEcomToErpConfigs();

        if ($configs->isEmpty()) {
            throw new \RuntimeException(
                'Odoo inventory push aborted: no active ecom→erp inventory field configs.'
            );
        }

        ['productId' => $productId, 'locationId' => $locationId, 'qty' => $qty] = $this->extractWriteValues($payload, $configs);

        if (!$productId || !$locationId) {
            throw new \InvalidArgumentException('updateLevel requires product_id and location_id in mapped payload');
        }

        $quants = $this->odoo->searchRead('stock.quant', [
            ['product_id', '=', $productId],
            ['location_id', '=', $locationId],
        ], ['id', 'quantity'], ['limit' => 1]);

        if ($quants !== []) {
            $this->odoo->executeKw('stock.quant', 'write', [[$quants[0]['id']], ['inventory_quantity' => $qty]]);
            try {
                $this->odoo->executeKw('stock.quant', 'action_apply_inventory', [[$quants[0]['id']]]);
            } catch (\Throwable) {
                // Expected marshal noise on Odoo SaaS 17/18
            }

            return;
        }

        try {
            $quantId = $this->odoo->create('stock.quant', [
                'product_id'         => $productId,
                'location_id'        => $locationId,
                'inventory_quantity' => $qty,
            ]);
            try {
                $this->odoo->executeKw('stock.quant', 'action_apply_inventory', [[$quantId]]);
            } catch (\Throwable) {
                // Expected marshal noise on Odoo SaaS 17/18
            }
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'consumables or services')) {
                throw new \RuntimeException("Product #{$productId} is not storable — stock quant not applicable.");
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  Collection<int, ProductFieldConfig>  $configs
     * @return array{productId: int, locationId: int, qty: int}
     */
    private function extractWriteValues(array $payload, Collection $configs): array
    {
        $productId  = 0;
        $locationId = 0;
        $qty        = null;

        foreach ($configs as $config) {
            $erpField = trim($config->erp_field ?? '');
            if ($erpField === '' || !array_key_exists($erpField, $payload)) {
                continue;
            }

            $value = $payload[$erpField];

            if ($this->isProductIdentityConfig($config)) {
                $productId = $this->asInt($value);
                continue;
            }

            if ($this->isLocationConfig($config)) {
                $locationId = $this->asInt($value);
                continue;
            }

            if ($config->field_type === 'custom') {
                continue;
            }

            if ($this->isQuantityConfig($config) && is_numeric($value)) {
                $qty = (int) $value;
            }
        }

        if ($qty === null) {
            $qtyKeys = $configs
                ->filter(fn ($c) => $this->isQuantityConfig($c))
                ->map(fn ($c) => $c->erp_field)
                ->filter()
                ->values()
                ->all();

            throw new \InvalidArgumentException(
                'updateLevel requires quantity in mapped payload. Missing or empty key(s): '
                . implode(', ', $qtyKeys ?: FieldMappingService::INVENTORY_QTY_ERP_FIELDS)
            );
        }

        return [
            'productId'  => $productId,
            'locationId' => $locationId,
            'qty'        => $qty,
        ];
    }

    private function isProductIdentityConfig(ProductFieldConfig $config): bool
    {
        return in_array(trim($config->erp_field ?? ''), ['product_id', 'id'], true);
    }

    private function isLocationConfig(ProductFieldConfig $config): bool
    {
        $transform = strtolower(FieldMappingService::effectiveSystemTransform($config->transform, $config->reverse_transform) ?? '');

        if ($transform === 'channel_map:warehouse') {
            return true;
        }

        return str_contains(strtolower(trim($config->erp_field ?? '')), 'location');
    }

    private function isQuantityConfig(ProductFieldConfig $config): bool
    {
        if ($this->isProductIdentityConfig($config) || $this->isLocationConfig($config)) {
            return false;
        }

        if ($config->field_type === 'custom') {
            return false;
        }

        return FieldMappingService::isInventoryQuantityErpField(trim($config->erp_field ?? ''));
    }

    private function asInt(mixed $value): int
    {
        if (is_array($value)) {
            return (int) ($value[0] ?? 0);
        }

        return (int) $value;
    }

    /**
     * Resolve Odoo product.product ID from SKU / default_code.
     */
    public function resolveProductIdByReference(string $reference): ?int
    {
        if ($reference === '') {
            return null;
        }

        $results = $this->odoo->searchRead(
            'product.product',
            [['default_code', '=', $reference]],
            ['id'],
            ['limit' => 1]
        );

        return isset($results[0]['id']) ? (int) $results[0]['id'] : null;
    }

    /** @param  array<string, mixed>  $quant */
    public function normalizeQuant(array $quant): array
    {
        $available = $this->availableQty($quant);
        $targetField = $this->availableQuantityErpField('erp_to_ecom');

        if ($targetField !== null
            && (!array_key_exists($targetField, $quant) || $quant[$targetField] === null || $quant[$targetField] === '')) {
            $quant[$targetField] = $available;
        }

        return $quant;
    }

    /** @param  array<string, mixed>  $quant */
    public function productKeyFromQuant(array $quant): string
    {
        $field = $this->productErpField('erp_to_ecom') ?? $this->productErpField('ecom_to_erp') ?? 'product_id';

        return $this->relationKey($quant[$field] ?? null);
    }

    /** @param  array<string, mixed>  $quant */
    public function locationKeyFromQuant(array $quant): string
    {
        $field = $this->locationErpField('erp_to_ecom') ?? $this->locationErpField('ecom_to_erp') ?? 'location_id';

        return $this->relationKey($quant[$field] ?? null);
    }

    /** @param  array<string, mixed>  $quant */
    public function inventorySyncErpIdFromQuant(array $quant): string
    {
        return $this->productKeyFromQuant($quant);
    }

    public function defaultInventoryFetchLocationId(): ?int
    {
        $id = app(\App\Services\ChannelMappingService::class)->defaultWarehouseOdooId();

        if ($id === null || $id === '' || !is_numeric($id)) {
            return null;
        }

        return (int) $id;
    }

    public function formatInventoryLocationForWrite(mixed $locationId): int|string
    {
        $value = (string) $locationId;

        return is_numeric($value) ? (int) $value : $value;
    }

    /** @return list<string> */
    private function quantFields(): array
    {
        return $this->mergeWithRequired(
            self::QUANT_REQUIRED,
            $this->configuredErpFields()
        );
    }

    /** @return list<string> */
    private function configuredErpFields(): array
    {
        $sync = app(\App\Services\Sync\UniversalSyncService::class);

        return $sync->getErpFieldsToFetch('inventory', 'default');
    }

    /** @param list<string> $required */
    private function mergeWithRequired(array $required, array $configured): array
    {
        return array_values(array_unique(array_merge($required, $configured)));
    }

    /** @return Collection<int, ProductFieldConfig> */
    private function inventoryConfigs(string $direction): Collection
    {
        return $this->fieldMapping->getMappings(
            'inventory',
            app(\App\Services\SettingsService::class)->ecomDriver(),
            app(\App\Services\SettingsService::class)->erpDriver(),
            'default',
            $direction,
        );
    }

    private function productErpField(string $direction): ?string
    {
        $config = $this->inventoryConfigs($direction)->first(function (ProductFieldConfig $config) {
            if (($config->field_type ?? '') === 'custom') {
                return false;
            }

            $erpRoot = $this->configErpRoot($config);
            if ($erpRoot === '') {
                return false;
            }

            $ecom = strtolower(trim((string) ($config->ecom_field ?? '')));
            $transform = FieldMappingService::effectiveSystemTransform(
                $config->transform,
                $config->reverse_transform,
            );

            return FieldMappingService::isInventoryProductErpField($erpRoot)
                || $ecom === 'sku'
                || in_array($transform, ['resolve_product_by_sku', 'resolve_product_by_reference'], true);
        });

        $root = $config ? $this->configErpRoot($config) : null;

        return $root !== '' ? $root : null;
    }

    private function locationErpField(string $direction): ?string
    {
        $config = $this->inventoryConfigs($direction)->first(function (ProductFieldConfig $config) {
            $transform = FieldMappingService::effectiveSystemTransform(
                $config->transform,
                $config->reverse_transform,
            );

            return $transform === 'channel_map:warehouse'
                || FieldMappingService::isInventoryLocationErpField($this->configErpRoot($config));
        });

        $root = $config ? $this->configErpRoot($config) : null;

        return $root !== '' ? $root : null;
    }

    private function availableQuantityErpField(string $direction): ?string
    {
        $config = $this->inventoryConfigs($direction)->first(
            fn (ProductFieldConfig $config) => trim((string) ($config->ecom_field ?? '')) === 'quantities.0.quantity'
        );

        $root = $config ? $this->configErpRoot($config) : null;

        return $root !== '' ? $root : null;
    }

    private function configErpRoot(ProductFieldConfig $config): string
    {
        return trim(explode('.', (string) ($config->erp_field ?? $config->odoo_field ?? ''))[0]);
    }

    private function relationKey(mixed $value): string
    {
        if (is_array($value)) {
            return (string) ($value[0] ?? '');
        }

        return $value !== null && $value !== '' ? (string) $value : '';
    }
}
