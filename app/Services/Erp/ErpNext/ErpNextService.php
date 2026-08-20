<?php

namespace App\Services\Erp\ErpNext;

use App\Models\ChannelMapping;
use App\Models\ProductFieldConfig;
use App\Services\ChannelMappingService;
use App\Services\FieldMappingService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ERPNext / Frappe REST API client.
 */
class ErpNextService
{
    /** @var list<array<string, mixed>> */
    private array $wireLog = [];

    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    public function baseUrl(): string
    {
        return rtrim($this->settings->erpnextUrl(), '/');
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl() !== ''
            && $this->settings->erpnextApiKey() !== ''
            && $this->settings->erpnextApiSecret() !== '';
    }

    public function defaultCompany(): string
    {
        return '';
    }

    /** Valid Company name — Field Config default, or explicit candidate. */
    public function resolveCompany(?string $candidate = null): string
    {
        $candidate = trim((string) ($candidate ?? ''));

        if ($candidate === '') {
            $candidate = app(FieldMappingService::class)->configuredCompany('sales_order');
        }

        $resolved = $this->findCompanyName($candidate);
        if ($resolved !== null) {
            return $resolved;
        }

        throw new \RuntimeException(
            'ERPNext company [' . $candidate . '] was not found. '
            . 'Set Field Config → custom → ERP field company → Default to an exact Company.name from ERPNext '
            . '(Setup → Company).'
        );
    }

    public function defaultWarehouse(): string
    {
        return $this->settings->erpnextWarehouse();
    }

    public function defaultSellingPriceList(): string
    {
        return $this->settings->erpnextSellingPriceList();
    }

    /** Company on a warehouse row — use for stock docs so company matches the warehouse. */
    public function resolveCompanyForWarehouse(string $warehouseName): ?string
    {
        $warehouseName = trim($warehouseName);
        if ($warehouseName === '') {
            return null;
        }

        $wh = $this->getDoc('Warehouse', $warehouseName, ['name', 'company']);
        if ($wh === null) {
            return null;
        }

        $company = trim((string) ($wh['company'] ?? ''));
        if ($company === '') {
            return null;
        }

        return $this->findCompanyName($company) ?? $company;
    }

    public function resolveCompanyForWarehouseOrDefault(string $warehouseName): string
    {
        return $this->resolveCompanyForWarehouse($warehouseName)
            ?? app(FieldMappingService::class)->configuredCompany('sales_order');
    }

    /** First non-group Cost Center for a company (from ERPNext list API). */
    public function resolveDefaultCostCenter(?string $company = null): ?string
    {
        $company = $this->resolveCompany($company);

        try {
            $rows = $this->listDocs('Cost Center', [
                ['company', '=', $company],
                ['is_group', '=', 0],
            ], ['name'], limit: 1, orderBy: 'name asc');
        } catch (\Throwable) {
            return null;
        }

        $first = trim((string) ($rows[0]['name'] ?? ''));

        return $first !== '' ? $first : null;
    }

    private function findCompanyName(string $candidate): ?string
    {
        $candidate = trim($candidate);
        if ($candidate === '') {
            return null;
        }

        if ($this->getDoc('Company', $candidate, ['name']) !== null) {
            return $candidate;
        }

        foreach ($this->listDocs('Company', [], ['name', 'abbr', 'company_name'], limit: 50) as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $abbr = trim((string) ($row['abbr'] ?? ''));
            $label = trim((string) ($row['company_name'] ?? ''));

            foreach ([$name, $abbr, $label] as $value) {
                if ($value !== '' && strcasecmp($value, $candidate) === 0) {
                    return $name;
                }
            }
        }

        return null;
    }

    private function companiesMatch(string $left, string $right): bool
    {
        $left  = trim($left);
        $right = trim($right);

        if ($left === '' || $right === '') {
            return $left === $right;
        }

        if (strcasecmp($left, $right) === 0) {
            return true;
        }

        $leftName  = $this->findCompanyName($left);
        $rightName = $this->findCompanyName($right);

        return $leftName !== null && $rightName !== null && $leftName === $rightName;
    }

    /** Valid warehouse for stock transactions — uses Mappings → Warehouse first. */
    public function resolveWarehouse(?string $candidate = null, ?string $shopifyLocationId = null, ?string $company = null): string
    {
        $company = $company !== null && trim($company) !== ''
            ? $this->resolveCompany($company)
            : $this->resolveCompany();

        $candidate = trim((string) ($candidate ?? ''));
        if ($candidate !== '' && $this->warehouseExists($candidate, $company)) {
            return $candidate;
        }

        $mapped = $this->resolveWarehouseFromMappings($shopifyLocationId, $company);
        if ($mapped !== null) {
            return $mapped;
        }

        $maps = app(ChannelMappingService::class);
        if ($maps->hasActiveWarehouseMappings()) {
            throw $this->warehouseMappingVerificationException(
                trim((string) ($shopifyLocationId ?? '')),
                $company,
            );
        }

        $settingsWh = trim($this->defaultWarehouse());
        if ($settingsWh !== '' && $this->warehouseExists($settingsWh, $company)) {
            return $settingsWh;
        }

        $rows = $this->listDocs('Warehouse', [
            ['company', '=', $company],
            ['is_group', '=', 0],
        ], ['name'], limit: 1);

        $name = trim((string) ($rows[0]['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        $hint = app(ChannelMappingService::class)->warehouseReverseMappingHint(
            trim((string) ($shopifyLocationId ?? '')),
            ChannelMapping::CHANNEL_SHOPIFY
        );

        throw new \RuntimeException(
            'ERPNext warehouse could not be resolved for sales order lines. '
            . 'Add Mappings → Warehouse (Shopify location id → ERPNext warehouse name, e.g. "Stores - YC") '
            . 'or set Default Warehouse in ERP Settings.'
            . ($hint ? ' ' . $hint : '')
        );
    }

    /** Resolve ERPNext warehouse name from channel_mappings / erpnext_warehouse_map. */
    private function resolveWarehouseFromMappings(?string $shopifyLocationId, string $company): ?string
    {
        $maps       = app(ChannelMappingService::class);
        $ecomDriver = ChannelMapping::CHANNEL_SHOPIFY;
        $locationId = trim((string) ($shopifyLocationId ?? ''));

        // Mappings → Warehouse — must belong to the target company (e.g. Sales Order company on dispatch).
        $soleMapped = method_exists($maps, 'soleActiveWarehouseOdooId')
            ? $maps->soleActiveWarehouseOdooId()
            : null;
        if ($soleMapped !== null) {
            $resolved = $this->resolveExistingWarehouseName($soleMapped, $company);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        if ($locationId === '') {
            $locationId = trim((string) ($maps->defaultShopifyWarehouseLocationId() ?? ''));
        }

        if ($locationId !== '') {
            $erpWh = $maps->odooWarehouse($locationId, $ecomDriver)
                ?? $maps->resolveWarehouseOdooIdForShopifyLocation($locationId);

            if ($erpWh !== null && $erpWh !== '') {
                $resolved = $this->resolveExistingWarehouseName((string) $erpWh, $company);
                if ($resolved !== null) {
                    return $resolved;
                }
            }

            // Legacy JSON map — only when Mappings → Warehouse table has no active rows.
            if (!$maps->hasActiveWarehouseMappings()) {
                foreach ($this->settings->erpnextWarehouseMap() as $erpWarehouse => $mappedShopifyLoc) {
                    if (!$maps->shopifyLocationMatches((string) $mappedShopifyLoc, $locationId)) {
                        continue;
                    }

                    $resolved = $this->resolveExistingWarehouseName(trim((string) $erpWarehouse), $company);
                    if ($resolved !== null) {
                        return $resolved;
                    }
                }
            }
        }

        $defaultMapped = $maps->defaultWarehouseOdooId($ecomDriver)
            ?? $maps->defaultWarehouseOdooId(null);
        if ($defaultMapped !== null && $defaultMapped !== '') {
            $resolved = $this->resolveExistingWarehouseName($defaultMapped, $company);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        if (!$maps->hasActiveWarehouseMappings()) {
            $warehouseMap = $this->settings->erpnextWarehouseMap();
            if (count($warehouseMap) === 1) {
                $only = trim((string) array_key_first($warehouseMap));
                $resolved = $this->resolveExistingWarehouseName($only, $company);
                if ($resolved !== null) {
                    return $resolved;
                }
            }
        }

        return null;
    }

    /** Find a non-group Warehouse.name in ERPNext (ignores ERP Settings company). */
    private function findWarehouseByName(string $candidate): ?string
    {
        $candidate = trim($candidate);
        if ($candidate === '') {
            return null;
        }

        $wh = $this->getDoc('Warehouse', $candidate, ['name', 'is_group']);
        if ($wh !== null && !(int) ($wh['is_group'] ?? 0)) {
            return trim((string) ($wh['name'] ?? $candidate));
        }

        foreach ([
            [['is_group', '=', 0], ['name', '=', $candidate]],
            [['is_group', '=', 0], ['warehouse_name', '=', $candidate]],
        ] as $filters) {
            $rows   = $this->listDocs('Warehouse', $filters, ['name', 'is_group'], limit: 1);
            $byName = trim((string) ($rows[0]['name'] ?? ''));
            if ($byName === '') {
                continue;
            }

            $doc = $this->getDoc('Warehouse', $byName, ['name', 'is_group']);
            if ($doc !== null && !(int) ($doc['is_group'] ?? 0)) {
                return $byName;
            }
        }

        return null;
    }

    private function warehouseMappingVerificationException(string $shopifyLocationId, string $documentCompany): \RuntimeException
    {
        $maps = app(ChannelMappingService::class);
        $hint = $maps->warehouseLookupDiagnostic($shopifyLocationId);

        $candidates = array_values(array_filter(array_unique([
            method_exists($maps, 'soleActiveWarehouseOdooId') ? $maps->soleActiveWarehouseOdooId() : null,
            $maps->odooWarehouse($shopifyLocationId, ChannelMapping::CHANNEL_SHOPIFY),
            $maps->resolveWarehouseOdooIdForShopifyLocation($shopifyLocationId),
        ])));

        foreach ($candidates as $mapped) {
            $found = $this->findWarehouseByName((string) $mapped);
            if ($found === null) {
                continue;
            }

            $warehouseCompany = $this->resolveCompanyForWarehouse($found);
            if ($warehouseCompany !== null && !$this->companiesMatch($warehouseCompany, $documentCompany)) {
                return new \RuntimeException(
                    "Mappings → Warehouse points to [{$found}] (company [{$warehouseCompany}]) "
                    . "but this document uses company [{$documentCompany}]. "
                    . "In Mappings → Warehouse, map your Shopify location to a warehouse that belongs to [{$documentCompany}] "
                    . "(check ERPNext → Stock → Warehouse), or create the Sales Order under [{$warehouseCompany}] instead. "
                    . $hint
                );
            }
        }

        $mappedLabel = $candidates !== [] ? implode(', ', $candidates) : 'unknown';

        return new \RuntimeException(
            'Mappings → Warehouse is configured but the ERPNext warehouse name could not be found. '
            . "Mapped value(s): [{$mappedLabel}]. "
            . 'Open ERPNext → Stock → Warehouse and copy the exact Warehouse.name into Mappings → Warehouse ERPNext ID '
            . '(not the display label). '
            . $hint
        );
    }

    /** Accept mapped warehouse id/label and return the ERPNext Warehouse.name when it exists. */
    private function resolveExistingWarehouseName(string $candidate, string $company): ?string
    {
        $candidate = trim($candidate);
        if ($candidate === '') {
            return null;
        }

        if ($this->warehouseExists($candidate, $company)) {
            return $candidate;
        }

        foreach ([
            [['is_group', '=', 0], ['name', '=', $candidate], ['company', '=', $company]],
            [['is_group', '=', 0], ['warehouse_name', '=', $candidate], ['company', '=', $company]],
        ] as $filters) {
            $rows = $this->listDocs('Warehouse', $filters, ['name', 'warehouse_name', 'company'], limit: 1);
            $byName = trim((string) ($rows[0]['name'] ?? ''));
            if ($byName !== '' && $this->warehouseExists($byName, $company)) {
                return $byName;
            }
        }

        return null;
    }

    private function warehouseExists(string $name, string $company): bool
    {
        $wh = $this->getDoc('Warehouse', $name, ['name', 'company', 'is_group']);

        if ($wh === null || (int) ($wh['is_group'] ?? 0)) {
            return false;
        }

        $warehouseCompany = trim((string) ($wh['company'] ?? ''));

        return $warehouseCompany === '' || $this->companiesMatch($warehouseCompany, $company);
    }

    /** @return list<array<string, mixed>> */
    public function takeWireLog(): array
    {
        $log       = $this->wireLog;
        $this->wireLog = [];

        return $log;
    }

    /**
     * @param  list<mixed>  $filters  Frappe filter expressions
     * @param  list<string>  $fields
     * @return list<array<string, mixed>>
     */
    public function listDocs(
        string $doctype,
        array $filters = [],
        array $fields = ['*'],
        int $limit = 100,
        int $offset = 0,
        ?string $orderBy = 'modified asc',
    ): array {
        if ($fields !== ['*']) {
            $fields = $this->sanitizeListFields($fields, forList: true);
        }

        $query = [
            'limit_page_length' => $limit,
            'limit_start'       => $offset,
            'fields'            => json_encode($fields),
        ];

        if ($filters !== []) {
            $query['filters'] = json_encode($filters);
        }

        if ($orderBy) {
            $query['order_by'] = $orderBy;
        }

        $response = $this->getResource($doctype, $query);

        return $response['data'] ?? [];
    }

    /** @return array<string, mixed>|null */
    public function getDoc(string $doctype, string $name, array $fields = []): ?array
    {
        $query = $fields !== [] ? ['fields' => json_encode($this->sanitizeListFields($fields, forList: false))] : [];
        $path  = '/api/resource/' . rawurlencode($doctype) . '/' . rawurlencode($name);

        try {
            $response = $this->request('GET', $path, query: $query);

            return $response['data'] ?? null;
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), '404')) {
                return null;
            }

            throw $e;
        }
    }

    /** @param  array<string, mixed>  $data */
    public function insertDoc(string $doctype, array $data): array
    {
        $this->assertWritableDocFields($doctype, $data);
        $response = $this->createResource($doctype, $data);

        return $response['data'] ?? $response;
    }

    /** @param  array<string, mixed>  $data */
    public function updateDoc(string $doctype, string $name, array $data): array
    {
        $this->assertWritableDocFields($doctype, $data);
        $response = $this->updateResource($doctype, $name, $data);

        return $response['data'] ?? $response;
    }

    public function deleteDoc(string $doctype, string $name): bool
    {
        $path = '/api/resource/' . rawurlencode($doctype) . '/' . rawurlencode($name);
        $this->request('DELETE', $path);

        return true;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return mixed
     */
    public function callMethod(string $method, array $args = [])
    {
        $path = '/api/method/' . ltrim($method, '/');

        $response = $this->request('POST', $path, body: $args);

        return $response['message'] ?? $response;
    }

    /** Resolve ISO-2 code or country name to ERPNext Country.name. */
    public function resolveCountryName(mixed $isoOrName): ?string
    {
        $value = trim((string) $isoOrName);
        if ($value === '') {
            return null;
        }

        if ($this->getDoc('Country', $value) !== null) {
            return $value;
        }

        try {
            $rows = $this->listDocs('Country', [['code', '=', strtoupper($value)]], ['name'], limit: 1);
            if ($rows !== []) {
                return (string) ($rows[0]['name'] ?? $value);
            }
        } catch (\Throwable) {
            // fall through
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function getResource(string $doctype, array $query = []): array
    {
        $path = '/api/resource/' . rawurlencode($doctype);

        return $this->request('GET', $path, query: $query);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createResource(string $doctype, array $data): array
    {
        $path = '/api/resource/' . rawurlencode($doctype);

        return $this->request('POST', $path, body: ['data' => $data]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateResource(string $doctype, string $name, array $data): array
    {
        $path = '/api/resource/' . rawurlencode($doctype) . '/' . rawurlencode($name);

        return $this->request('PUT', $path, body: ['data' => $data]);
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, ?array $body = null, array $query = []): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('ERPNext is not configured. Set Site URL, API Key, and API Secret in ERP Settings.');
        }

        $url = $this->baseUrl() . (str_starts_with($path, '/') ? $path : '/' . $path);

        $pending = Http::timeout($this->settings->erpnextTimeout())
            ->withHeaders([
                'Accept'        => 'application/json',
                'Authorization' => 'token ' . $this->settings->erpnextApiKey() . ':' . $this->settings->erpnextApiSecret(),
            ]);

        $response = match (strtoupper($method)) {
            'GET'    => $pending->get($url, $query),
            'POST'   => $pending->post($url, $body ?? []),
            'PUT'    => $pending->put($url, $body ?? []),
            'DELETE' => $pending->delete($url, $query),
            default  => throw new \InvalidArgumentException("Unsupported HTTP method [{$method}]"),
        };

        $this->wireLog[] = [
            'method'   => $method,
            'url'      => $url,
            'query'    => $query,
            'body'     => $body,
            'status'   => $response->status(),
            'response' => $response->json(),
        ];

        if (!$response->successful()) {
            Log::warning('ErpNextService request failed', [
                'method' => $method,
                'url'    => $url,
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            throw new \RuntimeException(
                'ERPNext API error (' . $response->status() . '): ' . $response->body()
            );
        }

        return $response->json() ?? [];
    }

    /** @return list<string> */
    public function configuredItemFields(): array
    {
        return $this->fetchFieldsForEntity('product', null, forList: true);
    }

    /** Child doctype for Delivery Note line rows (ERPNext schema). */
    public function deliveryNoteItemDoctype(): string
    {
        return 'Delivery Note Item';
    }

    public function isPermissionError(\Throwable $e): bool
    {
        $msg = $e->getMessage();

        return str_contains($msg, 'PermissionError')
            || str_contains($msg, 'Insufficient Permission')
            || str_contains($msg, '(403)');
    }

    public function isNegativeStockError(\Throwable $e): bool
    {
        $msg = $e->getMessage();

        return str_contains($msg, 'NegativeStockError')
            || str_contains($msg, 'Insufficient Stock');
    }

    public function summarizeNegativeStockError(\Throwable $e): string
    {
        $msg = strip_tags(html_entity_decode($e->getMessage()));

        if (preg_match('/([\d.]+)\s+units of .*?(?:Item\s+)?([^\s:]+).*?Warehouse\s+([^.]+)/i', $msg, $m)) {
            return trim("Need {$m[1]} unit(s) of {$m[2]} in warehouse {$m[3]}.");
        }

        return 'Required quantity is not available in the mapped ERPNext warehouse.';
    }

    /**
     * Field list for ERPNext API reads — driven by product_field_configs only.
     *
     * @param  list<string>  $extra  Optional sync helpers (e.g. docstatus)
     * @return list<string>
     */
    public function fetchFieldsForEntity(
        string $entityType,
        ?string $scope = null,
        ?string $direction = null,
        array $extra = [],
        bool $forList = false,
    ): array {
        $sync       = app(\App\Services\Sync\UniversalSyncService::class);
        $directions = $direction !== null ? [$direction] : ['erp_to_ecom', 'ecom_to_erp'];
        $scopes     = $scope !== null ? [$scope] : $this->entityScopes($entityType);

        $configured = [];
        foreach ($directions as $dir) {
            foreach ($scopes as $sc) {
                $configured = array_merge(
                    $configured,
                    $sync->getErpFieldsToFetch($entityType, $sc, $dir),
                );
            }
        }

        $enrichedRoots = $this->enrichedErpFieldRoots($entityType);
        if ($enrichedRoots !== []) {
            $configured = array_values(array_filter(
                $configured,
                static fn (string $field) => !in_array(
                    trim(explode('.', $field)[0]),
                    $enrichedRoots,
                    true,
                ),
            ));
        }

        $merged = $this->mergeFields(['name', 'modified'], array_merge($extra, $configured));
        $merged = $this->filterFetchFieldsForEntity($entityType, $merged);

        return $this->sanitizeListFields(
            $merged,
            forList: $forList,
            entityType: $entityType,
        );
    }

    /**
     * Drop erp_field roots that are not on any ERPNext doctype for this entity.
     * Prevents Frappe list/get errors (e.g. Odoo field names left in erpnext configs).
     *
     * @param  list<string>  $fields
     * @return list<string>
     */
    public function filterFetchFieldsForEntity(string $entityType, array $fields): array
    {
        $definitions = $this->entityDocTypeDefinitions($entityType);
        if ($definitions === []) {
            return $fields;
        }

        $allowed = ['name' => true, 'modified' => true];

        foreach ($definitions as $definition) {
            foreach ($this->docTypeFieldNames($definition['doctype']) as $fieldname) {
                $allowed[$fieldname] = true;
            }
        }

        if (count($allowed) <= 2) {
            return $fields;
        }

        $filtered = [];
        $dropped  = [];

        foreach ($fields as $field) {
            $root = trim(explode('.', (string) $field)[0]);

            if ($root === '' || isset($allowed[$root])) {
                $filtered[] = $field;
                continue;
            }

            $dropped[] = $field;
        }

        if ($dropped !== []) {
            Log::warning("ErpNextService: dropped invalid {$entityType} fetch field(s)", [
                'fields' => $dropped,
            ]);
        }

        return array_values(array_unique($filtered));
    }

    /** @return list<string> */
    private function entityScopes(string $entityType): array
    {
        return match ($entityType) {
            'product'     => ['template', 'variant'],
            'sales_order' => ['header', 'line'],
            'dispatch'    => ['header', 'line'],
            'customer'    => ['default', 'header', 'address', 'contact'],
            default       => ['default'],
        };
    }

    /**
     * Prepare field names for Frappe list/get queries.
     * Child-table roots and line containers come from field configs, not hardcoded lists.
     *
     * @param  list<string>  $fields
     * @return list<string>
     */
    public function sanitizeListFields(array $fields, bool $forList = false, ?string $entityType = null): array
    {
        $fields = array_values(array_unique(array_filter(
            $fields,
            static fn (string $field) => $field !== '' && $field !== 'id' && !str_starts_with($field, '_'),
        )));

        if ($forList) {
            $skip = $this->nonListableFieldRoots($entityType);
            $fields = array_values(array_filter(
                $fields,
                static fn (string $field) => !in_array($field, $skip, true),
            ));
        }

        if (!in_array('name', $fields, true)) {
            array_unshift($fields, 'name');
        }

        return array_values(array_unique($fields));
    }

    /**
     * Roots that cannot be selected on Frappe list API (child tables / line containers).
     *
     * @return list<string>
     */
    private function nonListableFieldRoots(?string $entityType = null): array
    {
        $settings = app(SettingsService::class);
        $query    = ProductFieldConfig::query()
            ->where('erp_driver', $settings->erpDriver())
            ->where('is_active', true)
            ->whereNotNull('erp_field');

        if ($entityType !== null) {
            $query->where('entity_type', $entityType);
        }

        $roots = [];
        foreach ($query->pluck('erp_field') as $field) {
            $field = (string) $field;
            if (preg_match('/^([^.]+)\.\d+\./', $field, $m)) {
                $roots[] = $m[1];
            }
        }

        $sync = app(\App\Services\Sync\UniversalSyncService::class);
        foreach (['product', 'sales_order', 'dispatch', 'inventory'] as $entity) {
            if ($entityType !== null && $entity !== $entityType) {
                continue;
            }

            foreach (['erp_to_ecom', 'ecom_to_erp'] as $direction) {
                $container = $sync->resolveLineContainer($entity, $direction);
                if ($container !== null) {
                    $roots[] = $container['erp_lines_key'];
                }
            }
        }

        return array_values(array_unique(array_filter($roots)));
    }

    /** @param  list<string>  $required
     * @param  list<string>  $configured
     * @return list<string>
     */
    protected function mergeFields(array $required, array $configured): array
    {
        return array_values(array_unique(array_merge($required, $configured)));
    }

    /** @var array<string, list<array{fieldname: string, label: string, fieldtype: string}>> */
    private array $docTypeMetaCache = [];

    /** @return list<string> */
    public function docTypeFieldNames(string $doctype): array
    {
        $names = [];

        foreach ($this->loadDocTypeRawFields($doctype) as $field) {
            $fieldname = (string) ($field['fieldname'] ?? '');
            $fieldtype = (string) ($field['fieldtype'] ?? '');

            if ($fieldname === '' || in_array($fieldtype, self::SKIP_FRAPPE_FIELD_TYPES, true)) {
                continue;
            }

            $names[] = $fieldname;
        }

        return array_values(array_unique($names));
    }

    /**
     * Reject writes that include erp_field keys not on the ERPNext doctype.
     * Frappe silently ignores unknown keys — this keeps field configs honest.
     *
     * @param  array<string, mixed>  $payload
     */
    public function assertWritableDocFields(string $doctype, array $payload): void
    {
        $fieldNames = $this->docTypeFieldNames($doctype);
        if ($fieldNames === []) {
            Log::warning("ErpNextService: skipping write field validation for [{$doctype}] — doctype meta unavailable.");

            return;
        }

        $allowed = array_fill_keys(array_merge($fieldNames, ['doctype', 'name']), true);

        $unknown = [];

        foreach (array_keys($payload) as $key) {
            $key = (string) $key;
            if (str_starts_with($key, '_')) {
                continue;
            }

            if (!isset($allowed[$key])) {
                $unknown[] = $key;
            }
        }

        if ($unknown === []) {
            return;
        }

        sort($unknown);

        throw new \RuntimeException(
            "ERPNext {$doctype} write rejected: unknown field(s) ["
            . implode(', ', $unknown)
            . ']. Fix Field Config erp_field to use real ERPNext field names (e.g. email_id, not email_id1).'
        );
    }

    private const SKIP_FRAPPE_FIELD_TYPES = [
        'Section Break',
        'Column Break',
        'Tab Break',
        'HTML',
        'Button',
        'Fold',
        'Heading',
        'Table MultiSelect',
        'Read Only',
    ];

    /**
     * Introspect ERPNext doctype fields for the field-config UI (no hardcoded lists).
     *
     * @return list<array{key: string, label: string, type: string, scope: string}>
     */
    public function discoverEntityFields(string $entityType): array
    {
        $fields = [];
        $seen   = [];

        foreach ($this->entityDocTypeDefinitions($entityType) as $definition) {
            foreach ($this->flattenDocTypeFields($definition['doctype']) as $field) {
                $dedupe = $definition['scope'] . ':' . $field['fieldname'];
                if (isset($seen[$dedupe])) {
                    continue;
                }

                $seen[$dedupe] = true;
                $fields[]      = [
                    'key'   => $field['fieldname'],
                    'label' => $field['label'],
                    'type'  => $field['fieldtype'],
                    'scope' => $definition['scope'],
                ];
            }
        }

        foreach ($this->syntheticEntityFields($entityType) as $field) {
            $dedupe = $field['scope'] . ':' . $field['key'];
            if (isset($seen[$dedupe])) {
                continue;
            }

            $seen[$dedupe] = true;
            $fields[]      = $field;
        }

        usort($fields, fn (array $a, array $b) => strcmp($a['label'], $b['label']));

        return $fields;
    }

    /** @return list<array{doctype: string, scope: string}> */
    private function entityDocTypeDefinitions(string $entityType): array
    {
        return match ($entityType) {
            'product' => [
                ['doctype' => 'Item', 'scope' => 'template'],
                ['doctype' => 'Item', 'scope' => 'variant'],
            ],
            'customer' => [
                ['doctype' => 'Customer', 'scope' => 'default'],
                ['doctype' => 'Address', 'scope' => 'address'],
                ['doctype' => 'Contact', 'scope' => 'contact'],
            ],
            'sales_order' => [
                ['doctype' => 'Sales Order', 'scope' => 'header'],
                ['doctype' => 'Sales Order Item', 'scope' => 'line'],
            ],
            'dispatch' => [
                ['doctype' => 'Delivery Note', 'scope' => 'header'],
                ['doctype' => 'Delivery Note Item', 'scope' => 'line'],
            ],
            'inventory' => [
                ['doctype' => 'Bin', 'scope' => 'default'],
                ['doctype' => 'Item Price', 'scope' => 'default'],
            ],
            default => [],
        };
    }

    /**
     * ERP fields populated by connector after fetch — must not be sent to Frappe list/get API.
     *
     * @return list<string>
     */
    public function enrichedErpFieldRoots(string $entityType): array
    {
        $roots = array_map(
            static fn (array $field) => $field['key'],
            $this->syntheticEntityFields($entityType),
        );

        if ($entityType === 'inventory') {
            $roots = array_merge($roots, ['available_quantity', 'available', 'qty_available']);
        }

        return array_values(array_unique($roots));
    }

    /**
     * Connector-enriched values not stored on the primary doctype document.
     *
     * @return list<array{key: string, label: string, type: string, scope: string}>
     */
    private function syntheticEntityFields(string $entityType): array
    {
        return match ($entityType) {
            'product' => [
                [
                    'key'   => 'price_list_rate',
                    'label' => 'Item Price Rate (enriched from Item Price at fetch)',
                    'type'  => 'Currency',
                    'scope' => 'variant',
                ],
                [
                    'key'   => 'write_date',
                    'label' => 'Modified timestamp (connector alias of modified)',
                    'type'  => 'Datetime',
                    'scope' => 'template',
                ],
            ],
            default => [],
        };
    }

    /**
     * @return list<array{fieldname: string, label: string, fieldtype: string}>
     */
    private function flattenDocTypeFields(string $doctype, string $prefix = '', string $labelPrefix = ''): array
    {
        $fields = [];

        foreach ($this->loadDocTypeRawFields($doctype) as $field) {
            $fieldname = (string) ($field['fieldname'] ?? '');
            $fieldtype = (string) ($field['fieldtype'] ?? '');
            $label     = trim((string) ($field['label'] ?? $fieldname));

            if ($fieldname === '' || in_array($fieldtype, self::SKIP_FRAPPE_FIELD_TYPES, true)) {
                continue;
            }

            $fullName  = $prefix !== '' ? $prefix . $fieldname : $fieldname;
            $fullLabel = $labelPrefix !== '' ? $labelPrefix . ' → ' . $label : $label;

            if ($fieldtype === 'Table') {
                $childDoctype = trim((string) ($field['options'] ?? ''));
                if ($childDoctype !== '') {
                    $fields = array_merge(
                        $fields,
                        $this->flattenDocTypeFields(
                            $childDoctype,
                            $fieldname . '.0.',
                            $fullLabel,
                        ),
                    );
                }

                continue;
            }

            $fields[] = [
                'fieldname' => $fullName,
                'label'     => $fullLabel,
                'fieldtype' => $fieldtype,
            ];
        }

        return $fields;
    }

    /** @return list<array<string, mixed>> */
    private function loadDocTypeRawFields(string $doctype): array
    {
        if (isset($this->docTypeMetaCache[$doctype])) {
            return $this->docTypeMetaCache[$doctype];
        }

        $rawFields = [];

        try {
            $payload = $this->callMethod('frappe.desk.form.load.getdoctype', [
                'doctype'     => $doctype,
                'with_parent' => 1,
            ]);

            if (is_array($payload)) {
                $docs      = $payload['docs'] ?? [];
                $rawFields = is_array($docs[0]['fields'] ?? null) ? $docs[0]['fields'] : [];
            }
        } catch (\Throwable $e) {
            Log::warning("ErpNextService: getdoctype failed for [{$doctype}]: " . $e->getMessage());
        }

        $this->docTypeMetaCache[$doctype] = $rawFields;

        return $rawFields;
    }
}
