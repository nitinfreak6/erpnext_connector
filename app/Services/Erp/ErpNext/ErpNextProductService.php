<?php

namespace App\Services\Erp\ErpNext;

use App\Models\ProductFieldConfig;
use App\Services\FieldMappingService;
use App\Services\SettingsService;

/**
 * ERPNext Item (product) reads/writes — native field names only (no Odoo aliases).
 */
class ErpNextProductService
{
    public function __construct(
        private readonly ErpNextService $api,
    ) {}

    /** @return list<array<string, mixed>> */
    public function getModifiedSince(string $writeDate): array
    {
        $items = $this->api->listDocs('Item', array_merge(
            [['modified', '>', $this->normalizeDate($writeDate)]],
            $this->productListFilters(),
        ), $this->api->configuredItemFields(), limit: 200);

        return $this->finalizeItems($items);
    }

    /** @return list<array<string, mixed>> */
    public function getAllActive(int $offset = 0, int $limit = 100): array
    {
        $items = $this->api->listDocs('Item', $this->productListFilters(), $this->api->configuredItemFields(), limit: $limit, offset: $offset);

        return $this->finalizeItems($items);
    }

    /** @return array<string, mixed>|null */
    public function getByName(string $name): ?array
    {
        $doc = $this->api->getDoc('Item', $name);

        return $doc ? $this->finalizeItem($doc) : null;
    }

    /** @return array<string, mixed>|null */
    public function getByNameFull(string $name): ?array
    {
        $doc = $this->api->getDoc('Item', $name);

        return $doc ? $this->finalizeItem($doc) : null;
    }

    /** @param  array<int|string>  $productIds */
    public function getVariantsForProducts(array $productIds): array
    {
        $variants = [];

        foreach ($productIds as $productId) {
            $item = $this->getByName((string) $productId);
            if ($item !== null) {
                $variants[] = $item;
            }
        }

        return $variants;
    }

    /** @param  array<string, mixed>  $data */
    public function create(array $data): string
    {
        $payload = $this->fromMappedPayload($data);
        $payload['doctype'] = 'Item';

        $result = $this->api->insertDoc('Item', $payload);

        return (string) ($result['name'] ?? $result['item_code'] ?? '');
    }

    /** @param  array<string, mixed>  $data */
    public function update(string $name, array $data): string
    {
        unset($data['id'], $data['name'], $data['doctype']);
        $payload = $this->fromMappedPayload($data);

        if ($payload === []) {
            return $name;
        }

        $this->api->updateDoc('Item', $name, $payload);

        return $name;
    }

    /** @param  array<string, mixed>  $data */
    public function upsert(array $data): string
    {
        $id = (string) ($data['id'] ?? $data['name'] ?? $data['item_code'] ?? '');

        if ($id !== '' && $this->getByName($id)) {
            return $this->update($id, $data);
        }

        return $this->create($data);
    }

    public function delete(string $name): bool
    {
        return $this->api->deleteDoc('Item', $name);
    }

    /**
     * Connector metadata only — ERPNext field names are preserved as-is.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public function normalizeItem(array $item): array
    {
        $item['id']         = (string) ($item['name'] ?? $item['item_code'] ?? '');
        $item['write_date'] = $this->normalizeDate($item['modified'] ?? '');

        return $item;
    }

    /**
     * ERPNext selling prices live on Item Price, not Item.standard_rate.
     * Merge price_list_rate (and backfill standard_rate when empty).
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function finalizeItems(array $items): array
    {
        if ($items === []) {
            return [];
        }

        $normalized = array_map(fn ($row) => $this->normalizeItem($row), $items);
        $codes      = array_values(array_filter(array_map(
            fn (array $item) => (string) ($item['item_code'] ?? $item['name'] ?? ''),
            $normalized,
        )));

        $sellingRates = $this->fetchSellingPriceRates($codes);

        return array_map(
            fn (array $item) => $this->applySellingPrice($item, $sellingRates),
            $normalized,
        );
    }

    /** @param  array<string, mixed>  $item */
    private function finalizeItem(array $item): array
    {
        return $this->finalizeItems([$item])[0];
    }

    /**
     * @param  list<string>  $itemCodes
     * @return array<string, float>
     */
    private function fetchSellingPriceRates(array $itemCodes): array
    {
        $itemCodes = array_values(array_unique(array_filter($itemCodes)));
        if ($itemCodes === []) {
            return [];
        }

        $priceList = $this->api->defaultSellingPriceList();
        $filters   = [
            ['item_code', 'in', $itemCodes],
            ['selling', '=', 1],
        ];

        if ($priceList !== '') {
            $filters[] = ['price_list', '=', $priceList];
        }

        $rows = $this->api->listDocs(
            'Item Price',
            $filters,
            ['item_code', 'price_list', 'price_list_rate'],
            limit: max(100, count($itemCodes) * 2),
        );

        if ($rows === [] && $priceList !== '') {
            $filters = [
                ['item_code', 'in', $itemCodes],
                ['selling', '=', 1],
            ];
            $rows = $this->api->listDocs(
                'Item Price',
                $filters,
                ['item_code', 'price_list', 'price_list_rate'],
                limit: max(100, count($itemCodes) * 2),
            );
        }

        $preferredList = strtolower($priceList);
        $rates         = [];

        foreach ($rows as $row) {
            $code = trim((string) ($row['item_code'] ?? ''));
            $rate = (float) ($row['price_list_rate'] ?? 0);
            if ($code === '' || $rate <= 0) {
                continue;
            }

            $listKey = strtolower(trim((string) ($row['price_list'] ?? '')));
            if (!isset($rates[$code])) {
                $rates[$code] = ['rate' => $rate, 'list' => $listKey];
                continue;
            }

            $currentPreferred = $rates[$code]['list'] === $preferredList;
            $nextPreferred    = $listKey === $preferredList;

            if ($nextPreferred && !$currentPreferred) {
                $rates[$code] = ['rate' => $rate, 'list' => $listKey];
            }
        }

        return array_map(fn (array $row) => (float) $row['rate'], $rates);
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, float>  $sellingRates
     * @return array<string, mixed>
     */
    private function applySellingPrice(array $item, array $sellingRates): array
    {
        $code = trim((string) ($item['item_code'] ?? $item['name'] ?? ''));
        if ($code === '' || !isset($sellingRates[$code])) {
            return $item;
        }

        $rate = $sellingRates[$code];
        $item['price_list_rate'] = $rate;

        if ((float) ($item['standard_rate'] ?? 0) <= 0) {
            $item['standard_rate'] = $rate;
        }

        return $item;
    }

    /** @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fromMappedPayload(array $data): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            if (str_starts_with((string) $key, '_')) {
                continue;
            }

            if ($key === 'disabled') {
                $out['disabled'] = $this->toDisabledFlag($value);
                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * ERPNext Item.disabled: 0 = enabled, 1 = disabled.
     * Accepts native 0/1 or Shopify-style status (ACTIVE, DRAFT, …).
     */
    private function toDisabledFlag(mixed $value): int
    {
        if ($value === 0 || $value === 1) {
            return (int) $value;
        }

        if ($value === '0' || $value === '1') {
            return (int) $value;
        }

        if (is_bool($value)) {
            return $value ? 0 : 1;
        }

        $normalized = strtolower(trim((string) $value));

        if (in_array($normalized, ['1', 'true', 'active', 'published', 'yes'], true)) {
            return 0;
        }

        if (in_array($normalized, ['false', 'draft', 'inactive', 'archived', 'no'], true)) {
            return 1;
        }

        return filter_var($value, FILTER_VALIDATE_INT) !== false ? (((int) $value) ? 1 : 0) : 1;
    }

    private function normalizeDate(string $value): string
    {
        if ($value === '') {
            return '2000-01-01 00:00:00';
        }

        return date('Y-m-d H:i:s', strtotime($value) ?: time());
    }

    /** @return list<array{0: string, 1: string, 2: mixed}> */
    private function productListFilters(): array
    {
        $settings = app(SettingsService::class);
        $configs  = app(FieldMappingService::class)->getMappings(
            'product',
            $settings->ecomDriver(),
            $settings->erpDriver(),
            'template',
            'ecom_to_erp',
        )->filter(fn (ProductFieldConfig $c) => ($c->field_type ?? '') === 'custom'
            && trim((string) ($c->erp_field ?? '')) !== ''
            && ($c->default_value ?? '') !== '');

        $filters = [];
        foreach ($configs as $config) {
            $field = trim(explode('.', (string) ($config->erp_field ?? ''))[0]);
            if ($field === '') {
                continue;
            }

            $filters[] = [$field, '=', $this->castListFilterValue($config->default_value)];
        }

        return $filters;
    }

    private function castListFilterValue(mixed $value): mixed
    {
        $normalized = strtolower(trim((string) $value));

        if (in_array($normalized, ['1', 'true', 'yes'], true)) {
            return 1;
        }

        if (in_array($normalized, ['0', 'false', 'no'], true)) {
            return 0;
        }

        return is_numeric($value) ? $value + 0 : $value;
    }
}
