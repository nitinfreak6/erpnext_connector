<?php

namespace App\Services\Erp\ErpNext;

use App\Models\ProductFieldConfig;
use App\Services\ChannelMappingService;
use App\Services\FieldMappingService;
use App\Services\SettingsService;
use App\Services\Sync\UniversalSyncService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ErpNextOrderService
{
    public function __construct(
        private readonly ErpNextService $api,
        private readonly ErpNextCustomerService $customers,
    ) {}

    private function sync(): UniversalSyncService
    {
        return app(UniversalSyncService::class);
    }

    private function mapper(): FieldMappingService
    {
        return app(FieldMappingService::class);
    }

    private function settings(): SettingsService
    {
        return app(SettingsService::class);
    }

    /** @return list<array<string, mixed>> */
    public function getModifiedSince(string $writeDate, bool $onlyErpOrigin = false): array
    {
        $filters = [
            ['modified', '>', $this->normalizeDate($writeDate)],
            ['docstatus', '=', 1],
        ];

        if ($onlyErpOrigin) {
            $filters[] = ['order_type', '=', 'Sales'];
        }

        $rows = $this->api->listDocs('Sales Order', $filters, $this->api->fetchFieldsForEntity(
            'sales_order',
            'header',
            forList: true,
        ), limit: 200);

        return array_map(fn ($r) => $this->enrichOrderForSync($this->normalizeDocument($r)), $rows);
    }

    /** @return array<string, mixed>|null */
    public function getByName(string $name): ?array
    {
        $doc = $this->api->getDoc('Sales Order', $name);

        return $doc ? $this->enrichOrderForSync($this->normalizeDocument($doc)) : null;
    }

    /** @param  array<string, mixed>  $data */
    public function create(array $data, array $sourceOrder = []): string
    {
        $payload = $this->fromMapped($data, $sourceOrder);
        $payload['doctype'] = 'Sales Order';

        $company   = $this->mapper()->configuredCompany('sales_order');
        $warehouse = $this->api->resolveWarehouse(
            null,
            $this->shopifyLocationFromOrder($sourceOrder),
            $company,
        );
        $enrichment = $this->salesOrderEnrichment($warehouse, $company);
        $payload      = array_merge($payload, $enrichment['header']);
        $payload['company'] = $enrichment['company'];
        $this->applyLineEnrichment($payload, $enrichment['line']);

        $payload['delivery_date'] ??= date('Y-m-d');
        $payload['transaction_date'] ??= date('Y-m-d');

        $result = $this->api->insertDoc('Sales Order', $payload);

        return (string) ($result['name'] ?? '');
    }

    /** @param  array<string, mixed>  $data */
    public function update(string $name, array $data, array $sourceOrder = []): bool
    {
        unset($data['id'], $data['name']);
        $payload = $this->fromMapped($data, $sourceOrder);

        if ($payload === []) {
            return true;
        }

        $this->api->updateDoc('Sales Order', $name, $payload);

        return true;
    }

    public function submit(string $name): bool
    {
        try {
            $this->api->callMethod('frappe.client.submit', ['doc' => $this->api->getDoc('Sales Order', $name)]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function cancel(string $name): bool
    {
        try {
            $this->api->callMethod('frappe.client.cancel', ['doctype' => 'Sales Order', 'name' => $name]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function delete(string $name): bool
    {
        return $this->api->deleteDoc('Sales Order', $name);
    }

    /** @return list<array<string, mixed>> */
    public function getDeliveryNotesForOrder(string $salesOrderName): array
    {
        $salesOrderName = trim($salesOrderName);
        if ($salesOrderName === '') {
            return [];
        }

        $linkField = $this->sync()->dispatchSalesOrderLinkField('ecom_to_erp')
            ?? $this->sync()->dispatchSalesOrderLinkField('erp_to_ecom');
        if ($linkField === null) {
            $roots = $this->sync()->dispatchLineErpFieldRoots('ecom_to_erp');
            throw new \RuntimeException(
                'Cannot find delivery notes: add dispatch line field config with erp_field against_sales_order. '
                . 'Run php artisan cache:clear after SQL seed. '
                . ($roots !== [] ? 'Line erp fields: ' . implode(', ', $roots) . '.' : '')
            );
        }

        $fields = $this->api->fetchFieldsForEntity('dispatch', 'header', forList: true);

        try {
            return $this->api->listDocs('Delivery Note', [
                [$this->api->deliveryNoteItemDoctype(), $linkField, '=', $salesOrderName],
                ['docstatus', 'in', [0, 1]],
            ], $fields, limit: 50, orderBy: 'modified desc');
        } catch (\Throwable $e) {
            if ($this->api->isPermissionError($e)) {
                Log::warning(
                    "ErpNextOrderService: delivery note lookup skipped (API permission): {$e->getMessage()}"
                );

                return [];
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $salesOrderDoc
     * @return list<array<string, mixed>>
     */
    public function buildDeliveryNoteItemsFromSalesOrder(
        array $salesOrderDoc,
        string $salesOrderName,
        array $lineEnrichment = [],
    ): array {
        $linkField    = $this->sync()->dispatchSalesOrderLinkField('ecom_to_erp');
        $detailField  = $this->sync()->dispatchSalesOrderLineDetailField('ecom_to_erp');
        $detailSource = $this->sync()->salesOrderLineDetailSourceField('ecom_to_erp');
        $lineConfigs  = $this->salesOrderLineConfigs('ecom_to_erp');
        $linesKey     = $this->salesOrderLinesKey('ecom_to_erp');
        $productField = $this->lineProductErpField($lineConfigs);

        if ($linkField === null) {
            throw new \RuntimeException(
                'Delivery Note items require dispatch line config erp_field against_sales_order.'
            );
        }

        if ($detailField === null) {
            throw new \RuntimeException(
                'Delivery Note items require dispatch line custom config → ERP field so_detail (Against Sales Order Item). '
                . 'Add Field Config → dispatch → line → custom → so_detail, Transform empty, Active.'
            );
        }

        if ($detailSource === null) {
            throw new \RuntimeException(
                'Delivery Note items require sales_order line custom config → ERP field name (SO line row id). '
                . 'Add Field Config → sales_order → line → custom → name, Transform empty, Active.'
            );
        }

        if ($productField === null) {
            throw new \RuntimeException(
                'Delivery Note items require a sales_order line config mapping SKU to an erp_field (e.g. item_code).'
            );
        }

        $salesOrderDoc  = $this->ensureSalesOrderLineDetailIds($salesOrderDoc, $salesOrderName, $linesKey, $detailSource);
        $items          = [];
        $lineNumber     = 0;

        foreach ($salesOrderDoc[$linesKey] ?? [] as $line) {
            if (!is_array($line)) {
                continue;
            }

            $lineNumber++;
            $row = $this->mapLineFromConfigs($line, $lineConfigs, $productField);

            if (!$this->lineHasProduct($row, $productField)) {
                continue;
            }

            $row = array_merge($row, $lineEnrichment);

            $row[$linkField] = $salesOrderName;

            $soDetail = trim((string) ($line[$detailSource] ?? ''));
            if ($soDetail === '') {
                throw new \RuntimeException(
                    "Delivery Note push aborted: Sales Order [{$salesOrderName}] line {$lineNumber} has no child row id "
                    . "for {$detailField} (Against Sales Order Item). Re-fetch the order from ERPNext and retry dispatch."
                );
            }

            $row[$detailField] = $soDetail;
            $items[]           = array_filter($row, static fn ($v) => $v !== null && $v !== '');
        }

        if ($items === []) {
            throw new \RuntimeException(
                'Delivery Note push aborted: sales order has no mappable line items. '
                . 'Check sales_order line field configs in Field Config.'
            );
        }

        return $items;
    }

    /** @param  array<string, mixed>  $salesOrderDoc
     * @return array<string, mixed>
     */
    private function ensureSalesOrderLineDetailIds(
        array $salesOrderDoc,
        string $salesOrderName,
        string $linesKey,
        string $detailSource,
    ): array {
        $lines = $salesOrderDoc[$linesKey] ?? [];
        if (!is_array($lines) || $lines === []) {
            return $salesOrderDoc;
        }

        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }

            if (trim((string) ($line[$detailSource] ?? '')) !== '') {
                continue;
            }

            $fresh = $this->api->getDoc('Sales Order', $salesOrderName);
            if (is_array($fresh) && is_array($fresh[$linesKey] ?? null) && $fresh[$linesKey] !== []) {
                return $this->normalizeDocument($fresh);
            }

            break;
        }

        return $salesOrderDoc;
    }

    /** @param  array<string, mixed>  $fulfillment */
    public function shopifyLocationFromFulfillment(array $fulfillment): ?string
    {
        return $this->resolveShopifyLocationFromEcom($fulfillment, $fulfillment);
    }

    /** @param  array<string, mixed>  $row */
    public function normalizeDocument(array $row): array
    {
        $row['id']         = (string) ($row['name'] ?? '');
        $row['write_date'] = $this->normalizeDate($row['modified'] ?? '');
        $row['state']      = match ((int) ($row['docstatus'] ?? 0)) {
            2       => 'cancel',
            1       => 'sale',
            default => 'draft',
        };

        return $row;
    }

    /** @param  array<string, mixed>  $row */
    public function enrichOrderForSync(array $row): array
    {
        $orderName = trim((string) ($row['id'] ?? ''));
        if ($orderName === '') {
            return $row;
        }

        $customerName = trim((string) ($row['customer'] ?? ''));
        if ($customerName !== '') {
            $customer = $this->customers->getByName($customerName);
            if ($customer !== null) {
                $row['contact_email'] = trim((string) ($customer['email_id'] ?? $customer['email'] ?? ''));
            }
        }

        if ((float) ($row['per_delivered'] ?? 0) > 0 || (float) ($row['per_fulfilled'] ?? 0) > 0) {
            $row['picking_ids'] = array_values(array_filter(array_map(
                static fn (array $dn) => trim((string) ($dn['name'] ?? '')),
                array_filter(
                    $this->getDeliveryNotesForOrder($orderName),
                    static fn (array $dn) => (int) ($dn['docstatus'] ?? 0) === 1,
                ),
            )));
        } else {
            $row['picking_ids'] = [];
        }

        return $row;
    }

    /** @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fromMapped(array $data, array $sourceOrder = []): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (str_starts_with((string) $key, '_')) {
                continue;
            }
            $out[$key] = $value;
        }

        if (isset($out['customer'])) {
            $out['customer'] = $this->customers->findOrCreateByReference(
                $out['customer'],
                $this->customerHintsFromSourceOrder($sourceOrder)
            );
        }

        $linesKey     = $this->salesOrderLinesKey('ecom_to_erp');
        $lineConfigs  = $this->salesOrderLineConfigs('ecom_to_erp');
        $productField = $this->lineProductErpField($lineConfigs);
        $warehouseField = $this->lineWarehouseErpField($lineConfigs);

        if (isset($out[$linesKey]) && is_array($out[$linesKey])) {
            $warehouse = $warehouseField !== null
                ? $this->api->resolveWarehouse(null, $this->shopifyLocationFromOrder($sourceOrder))
                : null;

            $out[$linesKey] = $this->finalizeOrderLines($out[$linesKey], $lineConfigs, $productField, $warehouseField, $warehouse);
        }

        if (empty($out[$linesKey]) && is_array($sourceOrder)) {
            $container    = $this->requireLineContainer('ecom_to_erp');
            $ecomLinesKey = $container['ecom_lines_key'];
            $lines        = $this->mapper()->readEcomFieldValue($sourceOrder, $sourceOrder, $ecomLinesKey);
            $warehouse    = $warehouseField !== null
                ? $this->api->resolveWarehouse(null, $this->shopifyLocationFromOrder($sourceOrder))
                : null;

            if (is_array($lines) && $lines !== []) {
                $out[$linesKey] = array_values(array_filter(array_map(function ($line) use (
                    $lineConfigs,
                    $productField,
                    $warehouseField,
                    $warehouse,
                    $sourceOrder,
                ) {
                    if (!is_array($line)) {
                        return null;
                    }

                    $row = $warehouseField !== null && $warehouse !== null
                        ? [$warehouseField => $warehouse]
                        : [];

                    foreach ($lineConfigs as $config) {
                        if (($config->field_type ?? '') === 'custom') {
                            continue;
                        }

                        $erpField = $this->configErpRoot($config);
                        $ecomField = trim((string) ($config->ecom_field ?? ''));
                        if ($erpField === '' || $ecomField === '') {
                            continue;
                        }

                        $value = $this->mapper()->readEcomFieldValue($line, $sourceOrder, $ecomField);
                        if ($value !== null && $value !== '') {
                            $row[$erpField] = $value;
                        }
                    }

                    $row = $this->applyLineConfigDefaults($row, $lineConfigs);

                    return $this->lineHasProduct($row, $productField) ? $row : null;
                }, $lines)));
            }
        }

        if (empty($out[$linesKey])) {
            $container    = $this->requireLineContainer('ecom_to_erp');
            $ecomLinesKey = $container['ecom_lines_key'];
            $rawLines     = is_array($sourceOrder)
                ? $this->mapper()->readEcomFieldValue($sourceOrder, $sourceOrder, $ecomLinesKey)
                : null;
            $lineCount = is_array($rawLines) ? count($rawLines) : 0;

            throw new \RuntimeException(
                'Sales Order push aborted: no line items mapped.'
                . ($lineCount > 0
                    ? " Found {$lineCount} line(s) but none matched line field configs."
                    : " Order payload has no {$ecomLinesKey}. Re-fetch from Shopify and retry.")
                . ' Check ecom→erp sales_order line configs and lineItems line_container.'
            );
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function finalizeOrderLines(
        array $lines,
        Collection $lineConfigs,
        ?string $productField,
        ?string $warehouseField,
        ?string $warehouse,
    ): array {
        return array_values(array_filter(array_map(function ($line) use (
            $lineConfigs,
            $productField,
            $warehouseField,
            $warehouse,
        ) {
            if (!is_array($line)) {
                return null;
            }

            $row = $this->mapLineFromConfigs($line, $lineConfigs, $productField);
            $row = $this->applyLineConfigDefaults($row, $lineConfigs);

            if ($warehouseField !== null && $warehouse !== null && $warehouse !== '') {
                $row[$warehouseField] = $warehouse;
            }

            return $this->lineHasProduct($row, $productField) ? $row : null;
        }, $lines)));
    }

    /** @param  array<string, mixed>  $line */
    private function mapLineFromConfigs(array $line, Collection $lineConfigs, ?string $productField): array
    {
        $row = [];

        foreach ($lineConfigs as $config) {
            if (($config->field_type ?? '') === 'custom') {
                continue;
            }

            $erpField = $this->configErpRoot($config);
            if ($erpField === '') {
                continue;
            }

            if (array_key_exists($erpField, $line) && $line[$erpField] !== null && $line[$erpField] !== '') {
                $row[$erpField] = $line[$erpField];
            }
        }

        return $row;
    }

    /** @param  array<string, mixed>  $row */
    private function applyLineConfigDefaults(array $row, Collection $lineConfigs): array
    {
        foreach ($lineConfigs as $config) {
            if (($config->field_type ?? '') === 'custom') {
                continue;
            }

            $erpField = $this->configErpRoot($config);
            if ($erpField === '') {
                continue;
            }

            $current = $row[$erpField] ?? null;
            if ($current !== null && $current !== '') {
                continue;
            }

            $default = $config->default_value;
            if ($default !== null && $default !== '') {
                $row[$erpField] = $default;
            }
        }

        return $row;
    }

    /** @param  array<string, mixed>  $row */
    private function lineHasProduct(array $row, ?string $productField): bool
    {
        if ($productField === null || $productField === '') {
            return false;
        }

        return trim((string) ($row[$productField] ?? '')) !== '';
    }

    /** @return Collection<int, ProductFieldConfig> */
    private function salesOrderLineConfigs(string $direction = 'ecom_to_erp'): Collection
    {
        return $this->mapper()->getMappings(
            'sales_order',
            $this->settings()->ecomDriver(),
            $this->settings()->erpDriver(),
            'line',
            $direction,
        );
    }

    private function lineProductErpField(Collection $lineConfigs): ?string
    {
        $config = $lineConfigs->first(function (ProductFieldConfig $config) {
            if (($config->field_type ?? '') === 'custom') {
                return false;
            }

            $ecom = strtolower(trim((string) ($config->ecom_field ?? '')));
            $transform = FieldMappingService::effectiveSystemTransform(
                $config->transform,
                $config->reverse_transform,
            );

            return $ecom === 'sku' || $transform === 'synced_product';
        });

        return $config ? $this->configErpRoot($config) : null;
    }

    private function lineWarehouseErpField(Collection $lineConfigs): ?string
    {
        $config = $lineConfigs->first(function (ProductFieldConfig $config) {
            $transform = FieldMappingService::effectiveSystemTransform(
                $config->transform,
                $config->reverse_transform,
            );

            return $transform === 'channel_map:warehouse';
        });

        $root = $config ? $this->configErpRoot($config) : null;

        return $root !== '' ? $root : null;
    }

    private function lineErpFieldRoot(Collection $lineConfigs, string $erpRoot): ?string
    {
        $config = $lineConfigs->first(fn (ProductFieldConfig $c) => $this->configErpRoot($c) === $erpRoot);

        return $config ? $erpRoot : null;
    }

    private function configErpRoot(ProductFieldConfig $config): string
    {
        return trim(explode('.', (string) ($config->erp_field ?? $config->odoo_field ?? ''))[0]);
    }

    /** @param  array<string, mixed>  $payload */
    private function resolvedWarehouseFromPayload(array $payload, array $sourceOrder): string
    {
        $warehouseField = $this->lineWarehouseErpField($this->salesOrderLineConfigs('ecom_to_erp'));
        $linesKey       = $this->salesOrderLinesKey('ecom_to_erp');

        if ($warehouseField !== null && is_array($payload[$linesKey] ?? null)) {
            foreach ($payload[$linesKey] as $line) {
                if (!is_array($line)) {
                    continue;
                }

                $warehouse = trim((string) ($line[$warehouseField] ?? ''));
                if ($warehouse !== '') {
                    return $warehouse;
                }
            }
        }

        return $this->api->resolveWarehouse(null, $this->shopifyLocationFromOrder($sourceOrder));
    }

    /**
     * @return array{company: string, header: array<string, mixed>, line: array<string, mixed>}
     */
    private function salesOrderEnrichment(string $warehouse, string $company): array
    {
        $mapper = $this->mapper();

        $context = [
            '_warehouse' => $warehouse,
            '_company'   => $company,
            'company'    => $company,
        ];

        $headerFields = $mapper->buildErpEnrichmentPayload('sales_order', 'header', $context);
        $headerFields['company'] = trim((string) ($headerFields['company'] ?? $company));

        return [
            'company' => $headerFields['company'],
            'header'  => $headerFields,
            'line'    => $mapper->buildErpEnrichmentPayload('sales_order', 'line', $context),
        ];
    }

    /** @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $lineEnrichment
     */
    private function applyLineEnrichment(array &$payload, array $lineEnrichment): void
    {
        if ($lineEnrichment === []) {
            return;
        }

        $linesKey = $this->salesOrderLinesKey('ecom_to_erp');
        if (!is_array($payload[$linesKey] ?? null)) {
            return;
        }

        foreach ($payload[$linesKey] as $index => $line) {
            if (is_array($line)) {
                $payload[$linesKey][$index] = array_merge($line, $lineEnrichment);
            }
        }
    }

    /** @return array{erp_lines_key: string, ecom_lines_key: string} */
    private function requireLineContainer(string $direction): array
    {
        $container = $this->sync()->resolveLineContainer('sales_order', $direction);
        if ($container === null) {
            throw new \RuntimeException(
                "Sales order line_container field config missing (direction={$direction}). "
                . 'Add header scope row with line_container transform.'
            );
        }

        return $container;
    }

    private function salesOrderLinesKey(string $direction): string
    {
        return $this->documentLinesKey('sales_order', $direction);
    }

    public function salesOrderLineWarehouseErpField(): ?string
    {
        return $this->lineWarehouseErpField($this->salesOrderLineConfigs('ecom_to_erp'));
    }

    /** Child-table key from line_container field config for an entity. */
    public function documentLinesKey(string $entityType, string $direction = 'ecom_to_erp'): string
    {
        $container = $this->sync()->resolveLineContainer($entityType, $direction);
        if ($container === null) {
            throw new \RuntimeException(
                "Missing line_container field config for {$entityType} (direction={$direction})."
            );
        }

        return $container['erp_lines_key'];
    }

    private function dispatchLineErpField(string $ecomField): ?string
    {
        $config = $this->mapper()->getMappings(
            'dispatch',
            $this->settings()->ecomDriver(),
            $this->settings()->erpDriver(),
            'line',
            'ecom_to_erp',
        )->first(fn (ProductFieldConfig $c) => trim((string) ($c->ecom_field ?? '')) === $ecomField);

        if ($config === null) {
            return null;
        }

        $root = $this->configErpRoot($config);

        return $root !== '' ? $root : null;
    }

    /** @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $root
     */
    private function resolveShopifyLocationFromEcom(array $source, array $root): ?string
    {
        foreach ($this->warehouseLocationEcomPaths() as $path) {
            $value = $this->mapper()->readEcomFieldValue($source, $root, $path);
            if ($value !== null && $value !== '') {
                return (string) $value;
            }
        }

        $default = app(ChannelMappingService::class)->defaultShopifyWarehouseLocationId();

        return $default !== null && $default !== '' ? (string) $default : null;
    }

    /** @return list<string> */
    private function warehouseLocationEcomPaths(): array
    {
        $paths = [];

        foreach (['inventory', 'dispatch', 'sales_order'] as $entity) {
            foreach (['ecom_to_erp', 'erp_to_ecom'] as $direction) {
                $configs = $this->mapper()->getMappings(
                    $entity,
                    $this->settings()->ecomDriver(),
                    $this->settings()->erpDriver(),
                    null,
                    $direction,
                );

                foreach ($configs as $config) {
                    $transform = FieldMappingService::effectiveSystemTransform(
                        $config->transform,
                        $config->reverse_transform,
                    );

                    if ($transform !== 'channel_map:warehouse') {
                        continue;
                    }

                    $field = trim((string) ($config->ecom_field ?? ''));
                    if ($field !== '' && !str_starts_with($field, '_')) {
                        $paths[] = $field;
                    }
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /** @param  array<string, mixed>  $sourceOrder */
    private function shopifyLocationFromOrder(array $sourceOrder): ?string
    {
        return $this->resolveShopifyLocationFromEcom($sourceOrder, $sourceOrder);
    }

    /** @param  array<string, mixed>  $sourceOrder
     * @return array<string, mixed>
     */
    private function customerHintsFromSourceOrder(array $sourceOrder): array
    {
        $configs = $this->mapper()->getMappings(
            'customer',
            $this->settings()->ecomDriver(),
            $this->settings()->erpDriver(),
            'default',
            'ecom_to_erp',
        );

        $customerBlock = is_array($sourceOrder['customer'] ?? null) ? $sourceOrder['customer'] : [];
        $hints         = [];

        foreach ($configs as $config) {
            $erpRoot   = $this->configErpRoot($config);
            $ecomField = trim((string) ($config->ecom_field ?? ''));

            if ($erpRoot === '' || $ecomField === '' || in_array($erpRoot, ['email_id', 'email'], true)) {
                continue;
            }

            $value = $this->mapper()->readEcomFieldValue($customerBlock, $sourceOrder, $ecomField);
            if ($value !== null && $value !== '') {
                $hints[$erpRoot] = $value;
            }
        }

        return $hints;
    }

    private function normalizeDate(string $value): string
    {
        return $value === '' ? '2000-01-01 00:00:00' : date('Y-m-d H:i:s', strtotime($value) ?: time());
    }
}
