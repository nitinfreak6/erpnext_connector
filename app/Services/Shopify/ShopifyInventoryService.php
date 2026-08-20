<?php

namespace App\Services\Shopify;

use App\Exceptions\ShopifyApiException;
use App\Models\ProductFieldConfig;
use App\Services\Config\NestedFieldResolver;
use App\Services\FieldMappingService;
use App\Services\SettingsService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ShopifyInventoryService
{
    private array $wireLog = [];

    public function __construct(
        private readonly ShopifyGraphQLService $graphql,
        private readonly FieldMappingService $fieldMapping,
        private readonly NestedFieldResolver $fields,
        private readonly SettingsService $settings,
    ) {}

    // ── GID helpers ──────────────────────────────────────────────────────

    private function toGid(string $type, string|int $id): string
    {
        if (str_starts_with((string) $id, 'gid://')) return (string) $id;
        return "gid://shopify/{$type}/{$id}";
    }

    private function fromGid(string $gid): string
    {
        return (string) last(explode('/', $gid));
    }

    // ── Public API ───────────────────────────────────────────────────────

    /**
     * Set inventory level from a field-config mapped payload.
     * Payload keys/paths come from ecom_field — same as product field configs.
     *
     * @param  array<string, mixed>  $mappedPayload  Nested payload from buildErpToEcomInventoryPayload()
     * @param  array<string, mixed>  $wireContext    Runtime values keyed by ecom_field
     */
    public function setLevel(array $mappedPayload, array $wireContext = []): array
    {
        $configs = $this->fieldMapping->getInventoryErpToEcomConfigs();

        if ($configs->isEmpty()) {
            throw new \RuntimeException(
                'Shopify inventory push aborted: no active erp→ecom inventory field configs.'
            );
        }

        $payload   = $this->enrichPayloadWithWireContext($mappedPayload, $configs, $wireContext);
        $variables = $this->buildGraphQLVariablesFromPayload($payload, $configs);

        $this->activateInventoryAtLocationIfNeeded($variables['input'] ?? []);

        $mutation = <<<'GQL'
        mutation inventorySetQuantities($input: InventorySetQuantitiesInput!, $idempotencyKey: String!) {
            inventorySetQuantities(input: $input) @idempotent(key: $idempotencyKey) {
                inventoryAdjustmentGroup {
                    createdAt
                    reason
                    changes {
                        name
                        delta
                        quantityAfterChange
                        item { id sku }
                        location { id name }
                    }
                }
                userErrors { field message code }
            }
        }
        GQL;

        $data = $this->gql(
            'inventorySetQuantities',
            $mutation,
            $variables,
            $this->displayPayload($variables['input'] ?? $payload)
        );

        $errors = $this->graphql->extractUserErrors($data, 'inventorySetQuantities');

        if (!empty($errors)) {
            throw new ShopifyApiException(
                'Shopify inventorySetQuantities errors: ' . implode('; ', $errors),
                422,
                'inventorySetQuantities'
            );
        }

        Log::info('Shopify inventory set via GraphQL', ['wire_input' => $this->displayPayload($payload)]);

        return $data['inventorySetQuantities']['inventoryAdjustmentGroup'] ?? [];
    }

    /**
     * Batch inventory updates are not supported — use setLevel() per item with field-config payloads.
     *
     * @deprecated Use setLevel() with mapped payloads built from inventory field configs.
     */
    public function setLevelBatch(array $quantities, string $shopifyLocationId): array
    {
        throw new \RuntimeException(
            'Shopify batch inventory push is not supported. '
            . 'Push each item with setLevel() using inventory field-config mapped payloads.'
        );
    }

    /**
     * Update inventory for a product (by product GID or numeric ID).
     * Called by ShopifyEcomAdapter::updateInventory() — payload comes from field configs only.
     */
    public function update(string|int $productGidOrId, int $quantity, ?string $locationId = null, ?array $mappedPayload = null): void
    {
        unset($quantity, $locationId);

        if (!is_array($mappedPayload) || $mappedPayload === []) {
            throw new \RuntimeException(
                'Shopify inventory push aborted: mapped payload is required (build from inventory field configs).'
            );
        }

        $configs = $this->fieldMapping->getInventoryErpToEcomConfigs();
        if ($configs->isEmpty()) {
            throw new \RuntimeException(
                'Shopify inventory push aborted: no active erp→ecom inventory field configs.'
            );
        }

        $inventoryItemIds = $this->resolveInventoryItemIds($productGidOrId);

        if ($inventoryItemIds === []) {
            throw new \RuntimeException(
                "Shopify inventory push aborted: no tracked inventory items for product {$productGidOrId}."
            );
        }

        foreach ($inventoryItemIds as $inventoryItemId) {
            $itemPayload = $mappedPayload;
            $this->injectInventoryItemId($itemPayload, $configs, (string) $inventoryItemId);
            $this->setLevel($itemPayload);
        }
    }

    /**
     * Resolve tracked inventory item numeric IDs for a Shopify product.
     *
     * @return list<string>
     */
    public function resolveInventoryItemIdsForProduct(string|int $productGidOrId): array
    {
        return $this->resolveInventoryItemIds($productGidOrId);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  Collection<int, ProductFieldConfig>  $configs
     */
    private function injectInventoryItemId(array &$payload, Collection $configs, string $inventoryItemId): void
    {
        foreach ($configs as $config) {
            if (!$config->is_active) {
                continue;
            }

            $writePath = trim($config->ecom_field ?? $config->shopify_field ?? '');
            if ($writePath === '' || !str_contains($writePath, 'inventoryItemId')) {
                continue;
            }

            $current = $this->fields->get($payload, $writePath);
            if ($current === null || $current === '') {
                $this->fields->set($payload, $writePath, $inventoryItemId);
            }
        }
    }

    /**
     * Apply ecom_cast and validate nested payload — GraphQL input matches field-config paths.
     *
     * @param  array<string, mixed>  $payload
     * @param  Collection<int, ProductFieldConfig>  $configs
     * @return array<string, mixed>
     */
    private function buildGraphQLVariablesFromPayload(
        array $payload,
        Collection $configs
    ): array {
        $this->assertInventoryGraphQLInput($payload, $configs);

        $input = $this->buildGraphQLWireInput($payload, $configs);
        $input = $this->normalizeQuantitiesListShape($input);
        $input = $this->ensureChangeFromQuantityPresent($input, $configs);
        $this->ensureInventoryItemIdBeforeAssert($input, $configs);

        return [
            'idempotencyKey' => (string) Str::uuid(),
            'input'          => $input,
        ];
    }

    /**
     * Build the Shopify inventorySetQuantities input from mapped payload — wire paths only.
     * Root keys like sku / _actual_qty come from field configs for ERP mapping but are not
     * valid on InventorySetQuantitiesInput.
     *
     * @param  array<string, mixed>  $payload
     * @param  Collection<int, ProductFieldConfig>  $configs
     * @return array<string, mixed>
     */
    private function buildGraphQLWireInput(array $payload, Collection $configs): array
    {
        $input = [];

        foreach ($configs as $config) {
            if (!$config->is_active) {
                continue;
            }

            $writePath = trim($config->ecom_field ?? $config->shopify_field ?? '');
            if ($writePath === '' || !$this->isInventoryGraphQLWirePath($writePath)) {
                continue;
            }

            if (!$this->configValuePresent($payload, $config, $writePath)) {
                continue;
            }

            $value = $this->fields->get($payload, $writePath);
            $value = $this->applyAutomaticWireCast($value, $writePath);
            $this->fields->set($input, $writePath, $value);
        }

        return $input;
    }

    /**
     * Paths that map to Shopify InventorySetQuantitiesInput / InventoryQuantityInput fields.
     */
    private function isInventoryGraphQLWirePath(string $writePath): bool
    {
        if (in_array($writePath, ['name', 'reason', 'referenceDocumentUri'], true)) {
            return true;
        }

        if (!str_starts_with($writePath, 'quantities.')) {
            return false;
        }

        $leaf = (string) last(explode('.', $writePath));

        return in_array($leaf, ['inventoryItemId', 'locationId', 'quantity', 'changeFromQuantity'], true);
    }

    /**
     * Shopify 2026-04: changeFromQuantity key is mandatory on every quantity row (null = skip CAS check).
     *
     * @param  array<string, mixed>  $input
     * @param  Collection<int, ProductFieldConfig>  $configs
     * @return array<string, mixed>
     */
    private function ensureChangeFromQuantityPresent(array $input, Collection $configs): array
    {
        $quantities = $input['quantities'] ?? null;
        if (!is_array($quantities)) {
            return $input;
        }

        $configuredValue = $this->resolveChangeFromQuantityFromConfigs($configs);

        foreach ($quantities as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            if (!array_key_exists('changeFromQuantity', $row)) {
                $row['changeFromQuantity'] = $configuredValue;
                $input['quantities'][$index] = $row;
            }
        }

        return $input;
    }

    /**
     * @param  Collection<int, ProductFieldConfig>  $configs
     */
    private function resolveChangeFromQuantityFromConfigs(Collection $configs): ?int
    {
        foreach ($configs as $config) {
            if (!$config->is_active) {
                continue;
            }

            $path = trim($config->ecom_field ?? $config->shopify_field ?? '');
            if ($path === '' || !str_contains($path, 'changeFromQuantity')) {
                continue;
            }

            if ($config->field_type === 'custom') {
                $default = trim((string) ($config->default_value ?? ''));
                if ($default === '' || in_array(strtolower($default), ['empty', 'null', 'none'], true)) {
                    return null;
                }

                return is_numeric($default) ? (int) $default : null;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  Collection<int, ProductFieldConfig>  $configs
     */
    private function assertInventoryGraphQLInput(array $payload, Collection $configs): void
    {
        $missing = [];

        foreach ($configs as $config) {
            if (!$config->is_active) {
                continue;
            }

            $writePath = trim($config->ecom_field ?? $config->shopify_field ?? '');
            if ($writePath === '' || !$this->isInventoryGraphQLWirePath($writePath)) {
                continue;
            }

            if ($this->configValueIsOptional($config)) {
                continue;
            }

            $current = $this->fields->get($payload, $writePath);
            if ($this->graphQLWireValuePresent($current, $config)) {
                continue;
            }

            $label     = trim($config->ecom_field ?? '') ?: $writePath;
            $missing[] = "{$label} ({$writePath})";
        }

        if ($missing === []) {
            return;
        }

        throw new \RuntimeException(
            'Shopify inventory push aborted: payload incomplete — missing '
            . implode(', ', $missing)
            . '. Check inventory field configs (ecom_field paths). '
            . 'Payload: '
            . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }

    private function configValuePresent(array $payload, ProductFieldConfig $config, string $writePath): bool
    {
        if ($this->configValueIsOptional($config)) {
            return true;
        }

        $current = $this->fields->get($payload, $writePath);
        if ($this->graphQLWireValuePresent($current, $config)) {
            return true;
        }

        return false;
    }

    private function configValueIsOptional(ProductFieldConfig $config): bool
    {
        $writePath = trim($config->ecom_field ?? $config->shopify_field ?? '');
        if (str_contains($writePath, 'inventoryItemId') || str_contains($writePath, 'changeFromQuantity')) {
            return true;
        }

        if ($config->field_type !== 'custom') {
            return false;
        }

        if (trim($config->transform ?? '') !== '') {
            return false;
        }

        $default = trim((string) ($config->default_value ?? ''));

        return $default === ''
            || in_array(strtolower($default), ['empty', 'null', 'none'], true);
    }

    private function graphQLWireValuePresent(mixed $value, ProductFieldConfig $config): bool
    {
        if ($this->configValueIsOptional($config)) {
            return true;
        }

        if ($value === null || $value === '') {
            return false;
        }

        return !is_array($value);
    }

    /**
     * Fail clearly if inventoryItemId config exists but value was not injected before GraphQL send.
     *
     * @param  array<string, mixed>  $input
     * @param  Collection<int, ProductFieldConfig>  $configs
     */
    private function ensureInventoryItemIdBeforeAssert(array $input, Collection $configs): void
    {
        foreach ($configs as $config) {
            if (!$config->is_active) {
                continue;
            }

            $writePath = trim($config->ecom_field ?? $config->shopify_field ?? '');
            if ($writePath === '' || !str_contains($writePath, 'inventoryItemId')) {
                continue;
            }

            $current = $this->fields->get($input, $writePath);
            if ($current !== null && $current !== '') {
                continue;
            }

            throw new \RuntimeException(
                'Shopify inventory push aborted: missing '
                . $writePath
                . '. Add a custom inventory field config with this ecom_field path (leave fixed value blank) — it is filled from the product at push time.'
            );
        }
    }

    /**
     * Activate inventory at a location when not yet stocked (required for custom locations in admin UI).
     *
     * @param  array<string, mixed>  $input
     */
    private function activateInventoryAtLocationIfNeeded(array $input): void
    {
        $row = $input['quantities'][0] ?? null;
        if (!is_array($row)) {
            return;
        }

        $inventoryItemId = $row['inventoryItemId'] ?? null;
        $locationId      = $row['locationId'] ?? null;
        $quantity        = isset($row['quantity']) && is_numeric($row['quantity']) ? (int) $row['quantity'] : 0;

        if ($inventoryItemId === null || $inventoryItemId === '' || $locationId === null || $locationId === '') {
            return;
        }

        $itemGid = str_starts_with((string) $inventoryItemId, 'gid://')
            ? (string) $inventoryItemId
            : $this->toGid('InventoryItem', (string) $inventoryItemId);
        $locGid = str_starts_with((string) $locationId, 'gid://')
            ? (string) $locationId
            : $this->toGid('Location', (string) $locationId);

        if ($this->inventoryLevelExists($itemGid, $locGid)) {
            return;
        }

        $mutation = <<<'GQL'
        mutation inventoryActivate(
            $inventoryItemId: ID!
            $locationId: ID!
            $available: Int!
            $idempotencyKey: String!
        ) {
            inventoryActivate(
                inventoryItemId: $inventoryItemId
                locationId: $locationId
                available: $available
            ) @idempotent(key: $idempotencyKey) {
                inventoryLevel {
                    id
                    quantities(names: ["available"]) { name quantity }
                }
                userErrors { field message }
            }
        }
        GQL;

        $data = $this->gql('inventoryActivate', $mutation, [
            'inventoryItemId' => $itemGid,
            'locationId'      => $locGid,
            'available'       => max(0, $quantity),
            'idempotencyKey'  => (string) Str::uuid(),
        ]);

        $errors = $this->graphql->extractUserErrors($data, 'inventoryActivate');
        if ($errors !== []) {
            throw new ShopifyApiException(
                'Shopify inventoryActivate errors: ' . implode('; ', $errors),
                422,
                'inventoryActivate'
            );
        }
    }

    private function inventoryLevelExists(string $inventoryItemGid, string $locationGid): bool
    {
        $query = <<<'GQL'
        query inventoryLevelExists($id: ID!, $locationId: ID!) {
            inventoryItem(id: $id) {
                inventoryLevel(locationId: $locationId) {
                    id
                }
            }
        }
        GQL;

        try {
            $data = $this->graphql->query($query, [
                'id'         => $inventoryItemGid,
                'locationId' => $locationGid,
            ]);

            return !empty($data['inventoryItem']['inventoryLevel']['id']);
        } catch (\Throwable $e) {
            Log::warning('Shopify inventoryLevelExists check failed: ' . $e->getMessage());

            return false;
        }
    }

    private function applyAutomaticWireCast(mixed $value, string $writePath): mixed
    {
        if (is_string($value) && in_array(strtolower(trim($value)), ['empty', 'null', 'none'], true)) {
            $value = null;
        }

        $leaf = (string) last(explode('.', $writePath));

        return match ($leaf) {
            'inventoryItemId' => $value !== null && $value !== ''
                ? $this->toGid('InventoryItem', (string) $value)
                : $value,
            'locationId' => $value !== null && $value !== ''
                ? $this->toGid('Location', (string) $value)
                : $value,
            'quantity' => is_numeric($value) ? (int) $value : $value,
            'changeFromQuantity' => $value,
            default => $value,
        };
    }

    /**
     * Ensure quantities is a JSON list [{...}] for Shopify GraphQL.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalizeQuantitiesListShape(array $input): array
    {
        $quantities = $input['quantities'] ?? null;
        if (!is_array($quantities)) {
            return $input;
        }

        if (!array_is_list($quantities)) {
            ksort($quantities, SORT_NUMERIC);
            $input['quantities'] = array_values($quantities);
        }

        return $input;
    }

    /**
     * Merge wire-context values into nested payload at each config's write path.
     *
     * @param  array<string, mixed>  $payload
     * @param  Collection<int, ProductFieldConfig>  $configs
     * @param  array<string, mixed>  $wireContext
     * @return array<string, mixed>
     */
    private function enrichPayloadWithWireContext(array $payload, Collection $configs, array $wireContext): array
    {
        foreach ($configs as $config) {
            if (!$config->is_active) {
                continue;
            }

            $writePath = trim($config->ecom_field ?? $config->shopify_field ?? '');
            $ecomField = trim($config->ecom_field ?? '');
            if ($writePath === '' || $ecomField === '' || !array_key_exists($ecomField, $wireContext)) {
                continue;
            }

            if ($this->fields->get($payload, $writePath) === null) {
                $this->fields->set($payload, $writePath, $wireContext[$ecomField]);
            }
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function displayPayload(array $payload): array
    {
        return array_filter(
            $payload,
            fn ($key) => !str_starts_with((string) $key, '_'),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Get inventory item IDs from a Shopify product GID or numeric ID.
     * Enables tracking on untracked items so existing products can receive stock updates.
     *
     * @return list<string>
     */
    private function resolveInventoryItemIds(string|int $productGidOrId): array
    {
        $items = $this->fetchProductInventoryItems($productGidOrId);

        $tracked   = [];
        $untracked = [];

        foreach ($items as $item) {
            if ($item['tracked']) {
                $tracked[] = $item['id'];
            } else {
                $untracked[] = $item;
            }
        }

        if ($tracked !== []) {
            return $tracked;
        }

        if ($untracked === []) {
            return [];
        }

        foreach ($untracked as $item) {
            $this->enableInventoryTracking($item['gid']);
        }

        Log::info('ShopifyInventoryService: enabled inventory tracking for untracked items', [
            'product' => $productGidOrId,
            'count'   => count($untracked),
        ]);

        return array_map(fn (array $item) => $item['id'], $untracked);
    }

    /**
     * @return list<array{id: string, gid: string, tracked: bool}>
     */
    private function fetchProductInventoryItems(string|int $productGidOrId): array
    {
        $gid = str_starts_with((string) $productGidOrId, 'gid://')
            ? $productGidOrId
            : "gid://shopify/Product/{$productGidOrId}";

        $query = <<<'GQL'
        query getProductInventory($id: ID!) {
            product(id: $id) {
                variants(first: 50) {
                    edges {
                        node {
                            inventoryItem {
                                id
                                tracked
                            }
                        }
                    }
                }
            }
        }
        GQL;

        $data  = $this->gql('getProductInventory', $query, ['id' => $gid]);
        $items = [];

        foreach ($data['product']['variants']['edges'] ?? [] as $edge) {
            $item = $edge['node']['inventoryItem'] ?? null;
            if (!$item || empty($item['id'])) {
                continue;
            }

            $items[] = [
                'id'      => $this->fromGid($item['id']),
                'gid'     => (string) $item['id'],
                'tracked' => (bool) ($item['tracked'] ?? false),
            ];
        }

        return $items;
    }

    private function enableInventoryTracking(string $inventoryItemGid): void
    {
        $mutation = <<<'GQL'
        mutation inventoryItemUpdate($id: ID!, $input: InventoryItemInput!) {
            inventoryItemUpdate(id: $id, input: $input) {
                inventoryItem { id tracked }
                userErrors { field message }
            }
        }
        GQL;

        $data = $this->gql('inventoryItemUpdate', $mutation, [
            'id'    => $inventoryItemGid,
            'input' => ['tracked' => true],
        ]);

        $errors = $this->graphql->extractUserErrors($data, 'inventoryItemUpdate');
        if ($errors !== []) {
            throw new ShopifyApiException(
                'Shopify inventoryItemUpdate errors: ' . implode('; ', $errors),
                422,
                'inventoryItemUpdate'
            );
        }
    }

    /**
     * Get the first active Shopify location ID.
     */
    public function getFirstLocationId(): ?string
    {
        $locations = $this->getLocations();
        return !empty($locations) ? $this->fromGid($locations[0]['id']) : null;
    }

    /**
     * Get inventory levels for a list of inventory item IDs at a location.
     * Returns rows with _sync_entity_id and _ecom_graphql_raw for field-config-driven fetch storage.
     */
    public function getLevels(array $inventoryItemIds, string $shopifyLocationId): array
    {
        $gids = array_map(
            fn ($id) => $this->toGid('InventoryItem', $id),
            $inventoryItemIds
        );

        $locationGid = $this->toGid('Location', $shopifyLocationId);
        $selection   = $this->fieldMapping->buildShopifyInventoryGraphqlSelection(
            $this->settings->ecomDriver(),
            $this->settings->erpDriver()
        );

        $query = <<<GQL
        query getInventoryItems(\$ids: [ID!]!) {
            nodes(ids: \$ids) {
                ... on InventoryItem {
                    {$selection}
                }
            }
        }
        GQL;

        $data  = $this->gql('getInventoryItems', $query, ['ids' => $gids]);
        $nodes = $data['nodes'] ?? [];

        $result = [];

        foreach ($nodes as $node) {
            if (empty($node['id'])) {
                continue;
            }

            $itemId          = $this->fromGid($node['id']);
            $matchedLevelRaw = null;

            foreach ($node['inventoryLevels']['edges'] ?? [] as $edge) {
                $level = $edge['node'];

                $returnedLocationNumeric = $this->fromGid($level['location']['id'] ?? '');
                $targetLocationNumeric   = $this->fromGid($locationGid);

                if ($returnedLocationNumeric === $targetLocationNumeric) {
                    $matchedLevelRaw = $level;
                    break;
                }
            }

            $row = [
                '_sync_entity_id' => $itemId,
            ];

            if ($matchedLevelRaw !== null) {
                $itemData = $node;
                unset($itemData['inventoryLevels']);

                $row['_ecom_graphql_raw'] = [
                    'inventoryItem'  => $itemData,
                    'inventoryLevel' => $matchedLevelRaw,
                ];
            }

            $result[] = $row;
        }

        return $result;
    }

    /**
     * Get all active locations.
     */
    public function getLocations(): array
    {
        $query = <<<'GQL'
        query getLocations {
            locations(first: 50, includeLegacy: false) {
                edges {
                    node {
                        id
                        name
                        isActive
                        address {
                            city
                            countryCode
                        }
                    }
                }
            }
        }
        GQL;

        $data = $this->gql('getLocations', $query);
        $locations = [];

        foreach ($data['locations']['edges'] ?? [] as $edge) {
            $node        = $edge['node'];
            $locations[] = [
                'id'        => $this->fromGid($node['id']),
                'name'      => $node['name'],
                'is_active' => $node['isActive'],
                'city'      => $node['address']['city']        ?? '',
                'country'   => $node['address']['countryCode'] ?? '',
            ];
        }

        return $locations;
    }

    /** @return array<int, array<string, mixed>> */
    public function takeWireLog(): array
    {
        $log           = $this->wireLog;
        $this->wireLog = [];

        return $log;
    }

    private function gql(string $action, string $query, array $variables = [], ?array $wireInput = null): array
    {
        $this->recordWire($action, $query, $variables, $wireInput);
        $data = $this->graphql->query($query, $variables);
        $this->recordResponse($data);

        return $data;
    }

    private function recordWire(string $action, string $query, array $variables, ?array $wireInput = null): void
    {
        $entry = [
            'action'    => $action,
            'query'     => $query,
            'variables' => $variables,
            'endpoint'  => 'graphql.json',
            'mutation'  => $action,
        ];

        if ($wireInput !== null) {
            $entry['wire_input'] = $wireInput;
        }

        $this->wireLog[] = $entry;
    }

    private function recordResponse(mixed $response): void
    {
        if (!empty($this->wireLog)) {
            $this->wireLog[count($this->wireLog) - 1]['response'] = $response;
        }
    }
}