<?php

namespace App\Services\Erp\ErpNext;

use App\Services\Erp\ErpInterface;

class ErpNextErpAdapter implements ErpInterface
{
    public function __construct(
        private readonly ErpNextService $api,
        private readonly ErpNextProductService $products,
        private readonly ErpNextCustomerService $customers,
        private readonly ErpNextOrderService $orders,
        private readonly ErpNextInventoryService $inventory,
        private readonly ErpNextDispatchService $dispatch,
    ) {}

    private function id(int|string $erpId): string
    {
        return (string) $erpId;
    }

    /** @return list<array<string, mixed>> */
    public function takeWireLog(): array
    {
        return $this->api->takeWireLog();
    }

    // ── Products ─────────────────────────────────────────────────────────

    public function getProductsModifiedSince(string $writeDate): array
    {
        return $this->products->getModifiedSince($writeDate);
    }

    public function getAllActiveProducts(int $offset = 0, int $limit = 100): array
    {
        return $this->products->getAllActive($offset, $limit);
    }

    public function getProductById(int|string $erpId): ?array
    {
        return $this->products->getByName($this->id($erpId));
    }

    public function getProductByIdFull(int|string $erpId): ?array
    {
        return $this->products->getByNameFull($this->id($erpId));
    }

    public function getVariantsForProducts(array $productIds): array
    {
        return $this->products->getVariantsForProducts($productIds);
    }

    public function resolveTemplateIdForVariant(int|string $variantId): ?string
    {
        return $variantId !== '' && $variantId !== null ? (string) $variantId : null;
    }

    public function getAttributeValues(array $valueIds): array
    {
        return [];
    }

    public function getCategory(int $categoryId): ?array
    {
        return null;
    }

    public function createProduct(array $data): int
    {
        $name = $this->products->upsert($data);

        return is_numeric($name) ? (int) $name : 0;
    }

    /** @return int|string */
    public function upsertProduct(array $productData): int|string
    {
        return $this->products->upsert($productData);
    }

    // ── Inventory ────────────────────────────────────────────────────────

    public function getInventoryModifiedSince(string $writeDate, ?int $locationId = null): array
    {
        $warehouse = $locationId ? (string) $locationId : null;

        return $this->inventory->getModifiedSince($writeDate, $warehouse ?: null);
    }

    public function getInventoryForProducts(array $productIds): array
    {
        return $this->inventory->getForProducts($productIds);
    }

    public function availableQty(array $quant): int
    {
        return $this->inventory->availableQty($quant);
    }

    public function updateInventoryLevel(array $payload): void
    {
        $this->inventory->updateLevel($payload);
    }

    public function normalizeInventoryQuant(array $quant): array
    {
        return $this->inventory->normalizeDocument($quant);
    }

    public function inventoryProductKeyFromQuant(array $quant): string
    {
        return $this->inventory->productKeyFromBin($quant);
    }

    public function inventoryLocationKeyFromQuant(array $quant): string
    {
        return $this->inventory->warehouseKeyFromBin($quant);
    }

    public function inventorySyncErpIdFromQuant(array $quant): string
    {
        return $this->inventory->productKeyFromBin($quant);
    }

    public function defaultInventoryFetchLocationId(): ?int
    {
        return null;
    }

    public function formatInventoryLocationForWrite(mixed $locationId): int|string
    {
        return (string) $locationId;
    }

    public function resolveProductIdByReference(string $reference): int|string|null
    {
        return $this->inventory->resolveProductIdByReference($reference);
    }

    // ── Orders ───────────────────────────────────────────────────────────

    public function getOrdersModifiedSince(string $writeDate, bool $onlyErpOrigin = false): array
    {
        return $this->orders->getModifiedSince($writeDate, $onlyErpOrigin);
    }

    public function getOrder(int|string $orderId): ?array
    {
        return $this->orders->getByName($this->id($orderId));
    }

    public function getOrderLines(array $lineIds): array
    {
        return [];
    }

    public function getPickings(array $pickingIds): array
    {
        $out = [];
        foreach ($pickingIds as $id) {
            $doc = $this->api->getDoc('Delivery Note', $this->id($id));
            if ($doc) {
                $out[] = $this->normalizePicking($doc);
            }
        }

        return $out;
    }

    public function getMoves(array $moveIds, ?array $fields = null): array
    {
        return [];
    }

    public function getFulfilledOrders(?string $sinceDate = null): array
    {
        return $this->dispatch->getFulfilledOrders($sinceDate);
    }

    public function applyFulfillmentToSaleOrder(int|string $saleOrderId, array $mappedPayload, array $sourceFulfillment): array
    {
        return $this->dispatch->applyFulfillmentToSaleOrder($saleOrderId, $mappedPayload, $sourceFulfillment);
    }

    public function createOrder(array $orderData, array $sourceOrder = []): int|string
    {
        $name = $this->orders->create($orderData, $sourceOrder);

        return is_numeric($name) ? (int) $name : (string) $name;
    }

    public function updateOrder(int|string $orderId, array $orderData, array $sourceOrder = []): bool
    {
        return $this->orders->update($this->id($orderId), $orderData, $sourceOrder);
    }

    public function confirmOrder(int|string $orderId): bool
    {
        return $this->orders->submit($this->id($orderId));
    }

    public function cancelOrder(int|string $orderId): bool
    {
        return $this->orders->cancel($this->id($orderId));
    }

    public function deleteOrder(int|string $orderId): bool
    {
        return $this->orders->delete($this->id($orderId));
    }

    public function deleteDispatch(int|string $pickingId): bool
    {
        return $this->dispatch->delete($this->id($pickingId));
    }

    public function deleteProduct(int|string $productId): bool
    {
        return $this->products->delete($this->id($productId));
    }

    public function deleteCustomer(int|string $customerId): bool
    {
        return $this->customers->delete($this->id($customerId));
    }

    // ── Customers ────────────────────────────────────────────────────────

    public function getCustomersModifiedSince(string $writeDate): array
    {
        return $this->customers->getModifiedSince($writeDate);
    }

    public function getCustomer(int|string $erpId): ?array
    {
        return $this->customers->getByName($this->id($erpId));
    }

    public function findCustomerByEmail(string $email): ?array
    {
        return $this->customers->findByEmail($email);
    }

    public function createCustomer(array $data, array $sourceCustomer = []): int|string
    {
        $name = $this->customers->create($data);

        if ($sourceCustomer !== []) {
            $this->customers->syncLinkedDocumentsFromEcom($sourceCustomer, (string) $name);
        }

        return is_numeric($name) ? (int) $name : (string) $name;
    }

    public function updateCustomer(int|string $customerId, array $data, array $sourceCustomer = []): bool
    {
        $updated = $this->customers->update($this->id($customerId), $data);

        if ($sourceCustomer !== []) {
            $this->customers->syncLinkedDocumentsFromEcom($sourceCustomer, $this->id($customerId));
        }

        return $updated;
    }

    public function resolveCountryLabel(mixed $value): ?string
    {
        return $this->api->resolveCountryName($value);
    }

    public function resolveCountry(string $iso2): ?int
    {
        return null;
    }

    public function resolveState(int $countryId, string $code): ?int
    {
        return null;
    }

    public function resolveCountryReference(mixed $value): ?int
    {
        return null;
    }

    public function resolveStateReference(mixed $stateValue, mixed $countryReference = null): ?int
    {
        return null;
    }

    public function resolveCountryCode(mixed $countryReference): ?string
    {
        return is_string($countryReference) ? $countryReference : null;
    }

    public function resolveStateCode(mixed $stateReference, mixed $countryReference = null): ?string
    {
        return is_string($stateReference) ? $stateReference : null;
    }

    public function resolveDefaultCostCenter(?string $company = null): ?string
    {
        return $this->api->resolveDefaultCostCenter($company);
    }

    public function resolveCompanyForWarehouse(string $warehouseName): ?string
    {
        return $this->api->resolveCompanyForWarehouse($warehouseName);
    }

    public function resolveCompanyForWarehouseOrDefault(string $warehouseName): string
    {
        return $this->api->resolveCompanyForWarehouseOrDefault($warehouseName);
    }

    public function prepareProductWriteValue(string $field, mixed $value): mixed
    {
        return $value;
    }

    public function extractRelationId(mixed $value): int|string|null
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        if (is_array($value)) {
            return $value[0] ?? $value[1] ?? null;
        }

        return is_numeric($value) ? (int) $value : (string) $value;
    }

    public function resolvePartnerReference(string $role, mixed $value): int|string|null
    {
        return $this->extractRelationId($value);
    }

    // ── Normalisation ────────────────────────────────────────────────────
    // Native ERPNext documents only — field configs map erp_field ↔ ecom_field.

    public function normalizeProduct(array $raw): array
    {
        return $this->products->normalizeItem($raw);
    }

    public function normalizeVariant(array $raw): array
    {
        return $this->products->normalizeItem($raw);
    }

    public function normalizeCustomer(array $raw): array
    {
        return $this->customers->normalizeDocument($raw);
    }

    public function normalizeOrder(array $raw): array
    {
        return $this->orders->normalizeDocument($raw);
    }

    public function driverName(): string
    {
        return 'erpnext';
    }

    public function normalizeFetchFieldList(string $entityType, array $fields, string $direction = 'erp_to_ecom'): array
    {
        $fields = array_values(array_filter(
            array_map(static fn (string $f) => $f === 'id' ? 'name' : $f, $fields),
            static fn (string $f) => $f !== 'id',
        ));

        $enriched = $this->api->enrichedErpFieldRoots($entityType);
        if ($enriched !== []) {
            $fields = array_values(array_filter(
                $fields,
                static fn (string $f) => !in_array($f, $enriched, true),
            ));
        }

        if (!in_array('name', $fields, true)) {
            array_unshift($fields, 'name');
        }

        return $this->api->filterFetchFieldsForEntity($entityType, array_values(array_unique($fields)));
    }

    public function salesOrderCustomerFieldKey(string $direction = 'ecom_to_erp'): string
    {
        return 'customer';
    }

    public function dispatchFulfillmentRequiresMappedPayload(): bool
    {
        return false;
    }

    public function enrichOrderForSync(array $order): array
    {
        return $this->orders->enrichOrderForSync($order);
    }

    public function normalizePicking(array $picking): array
    {
        return $this->dispatch->normalizeDocument($picking);
    }

    public function apiBaseUrl(): ?string
    {
        $url = rtrim(app(\App\Services\SettingsService::class)->erpnextUrl(), '/');

        return $url !== '' ? $url : null;
    }

    public function getAvailableFields(string $entityType): array
    {
        try {
            return $this->api->discoverEntityFields($entityType);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning(
                "ErpNextErpAdapter::getAvailableFields({$entityType}) failed: " . $e->getMessage()
            );

            return [];
        }
    }

    public function getMappingOptions(string $type, ?string $search = null): array
    {
        try {
            return match ($type) {
                \App\Models\ChannelMapping::TYPE_WAREHOUSE => $this->warehouseMappingOptions(),
                \App\Models\ChannelMapping::TYPE_CATEGORY  => $this->itemGroupMappingOptions($search),
                default                                    => [],
            };
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning(
                "ErpNextErpAdapter::getMappingOptions({$type}) failed: " . $e->getMessage()
            );

            return [];
        }
    }

    /** @return list<array{id: string, label: string}> */
    private function warehouseMappingOptions(): array
    {
        $rows = $this->api->listDocs(
            'Warehouse',
            [],
            ['name', 'warehouse_name', 'company', 'is_group'],
            limit: 200,
            orderBy: 'warehouse_name asc'
        );

        return array_values(array_filter(array_map(function (array $row) {
            if (!empty($row['is_group'])) {
                return null;
            }

            $id = trim((string) ($row['name'] ?? ''));
            if ($id === '') {
                return null;
            }

            $label = trim((string) ($row['warehouse_name'] ?? $id));
            $company = trim((string) ($row['company'] ?? ''));
            if ($company !== '' && !str_contains($label, $company)) {
                $label .= " ({$company})";
            }

            return ['id' => $id, 'label' => $label];
        }, $rows)));
    }

    /** @return list<array{id: string, label: string}> */
    private function itemGroupMappingOptions(?string $search): array
    {
        $filters = [];
        if ($search !== null && trim($search) !== '') {
            $filters[] = ['name', 'like', '%' . trim($search) . '%'];
        }

        $rows = $this->api->listDocs(
            'Item Group',
            $filters,
            ['name', 'item_group_name', 'parent_item_group'],
            limit: 200,
            orderBy: 'name asc'
        );

        return array_values(array_filter(array_map(function (array $row) {
            $id = trim((string) ($row['name'] ?? ''));
            if ($id === '') {
                return null;
            }

            return [
                'id'    => $id,
                'label' => trim((string) ($row['item_group_name'] ?? $row['name'] ?? $id)),
            ];
        }, $rows)));
    }
}
