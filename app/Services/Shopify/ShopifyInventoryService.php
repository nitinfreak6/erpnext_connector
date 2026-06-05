<?php

namespace App\Services\Shopify;

use App\Exceptions\ShopifyApiException;
use Illuminate\Support\Facades\Log;

class ShopifyInventoryService
{
    public function __construct(
        private readonly ShopifyGraphQLService $graphql,
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
     * Set inventory level for a specific item at a location.
     * Uses inventorySetQuantities mutation (supported from 2023-10+).
     */
    public function setLevel(string $inventoryItemId, string $shopifyLocationId, int $available): array
    {
        $mutation = <<<'GQL'
        mutation inventorySetQuantities($input: InventorySetQuantitiesInput!) {
            inventorySetQuantities(input: $input) {
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

        $data   = $this->graphql->query($mutation, [
            'input' => [
                'name'                 => 'available',
                'reason'               => 'correction',
                'ignoreCompareQuantity' => true,
                'quantities' => [[
                    'inventoryItemId' => $this->toGid('InventoryItem', $inventoryItemId),
                    'locationId'      => $this->toGid('Location', $shopifyLocationId),
                    'quantity'        => $available,
                ]],
            ],
        ]);

        $errors = $this->graphql->extractUserErrors($data, 'inventorySetQuantities');

        if (!empty($errors)) {
            throw new ShopifyApiException(
                'Shopify inventorySetQuantities errors: ' . implode('; ', $errors),
                422,
                'inventorySetQuantities'
            );
        }

        Log::info("Shopify inventory set via GraphQL: item={$inventoryItemId} location={$shopifyLocationId} qty={$available}");

        return $data['inventorySetQuantities']['inventoryAdjustmentGroup'] ?? [];
    }

    /**
     * Set inventory for multiple items at once (batch).
     */
    public function setLevelBatch(array $quantities, string $shopifyLocationId): array
    {
        $mutation = <<<'GQL'
        mutation inventorySetQuantities($input: InventorySetQuantitiesInput!) {
            inventorySetQuantities(input: $input) {
                inventoryAdjustmentGroup {
                    createdAt
                    changes { name delta quantityAfterChange }
                }
                userErrors { field message code }
            }
        }
        GQL;

        $quantityInputs = array_map(fn($q) => [
            'inventoryItemId' => $this->toGid('InventoryItem', $q['inventory_item_id']),
            'locationId'      => $this->toGid('Location', $shopifyLocationId),
            'quantity'        => (int) $q['quantity'],
        ], $quantities);

        $data   = $this->graphql->query($mutation, [
            'input' => [
                'name'      => 'available',
                'reason'    => 'correction',
                'quantities' => $quantityInputs,
            ],
        ]);

        $errors = $this->graphql->extractUserErrors($data, 'inventorySetQuantities');

        if (!empty($errors)) {
            throw new ShopifyApiException(
                'Shopify batch inventorySetQuantities errors: ' . implode('; ', $errors),
                422,
                'inventorySetQuantities'
            );
        }

        return $data['inventorySetQuantities']['inventoryAdjustmentGroup'] ?? [];
    }

    
    /**
     * Update inventory for a product (by product GID or inventory item GID).
     * Resolves inventory item ID and location automatically.
     * Called by ShopifyEcomAdapter::updateInventory().
     */
    public function update(string|int $productGidOrId, int $quantity, ?string $locationId = null): void
    {
        // locationId should already be resolved by PushInventoryToEcomJob from the location mapping
        // Fall back to settings or first active location if not provided
        $resolvedLocationId = $locationId
            ?? app(\App\Services\SettingsService::class)->get('shopify_location_id')
            ?? $this->getFirstLocationId();

        if (!$resolvedLocationId) {
            throw new \RuntimeException('ShopifyInventoryService: no location ID available. Add location mapping in Settings.');
        }

        // Get inventory item IDs from the product's variants
        $inventoryItemIds = $this->resolveInventoryItemIds($productGidOrId);

        if (empty($inventoryItemIds)) {
            throw new \RuntimeException("ShopifyInventoryService: no tracked inventory items found for product {$productGidOrId}. Enable inventory tracking in field config.");
        }

        foreach ($inventoryItemIds as $inventoryItemId) {
            $this->setLevel((string) $inventoryItemId, (string) $resolvedLocationId, $quantity);
        }
    }

    /**
     * Get inventory item IDs from a Shopify product GID or numeric ID.
     */
    private function resolveInventoryItemIds(string|int $productGidOrId): array
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

        $data = $this->graphql->query($query, ['id' => $gid]);
        $ids  = [];

        foreach ($data['product']['variants']['edges'] ?? [] as $edge) {
            $item = $edge['node']['inventoryItem'] ?? null;
            if ($item && ($item['tracked'] ?? false)) {
                $ids[] = $this->fromGid($item['id']);
            }
        }

        return $ids;
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
     * Returns array of [inventory_item_id => available] pairs.
     */
    public function getLevels(array $inventoryItemIds, string $shopifyLocationId): array
    {
        $gids = array_map(
            fn($id) => $this->toGid('InventoryItem', $id),
            $inventoryItemIds
        );

        $locationGid = $this->toGid('Location', $shopifyLocationId);

        $query = <<<'GQL'
        query getInventoryItems($ids: [ID!]!) {
            nodes(ids: $ids) {
                ... on InventoryItem {
                    id
                    sku
                    inventoryLevels(first: 20) {
                        edges {
                            node {
                                location { id name }
                                quantities(names: ["available"]) {
                                    name
                                    quantity
                                }
                            }
                        }
                    }
                }
            }
        }
        GQL;

        $data  = $this->graphql->query($query, ['ids' => $gids]);
        $nodes = $data['nodes'] ?? [];

        $result = [];

        foreach ($nodes as $node) {
            if (empty($node['id'])) continue;

            $itemId    = $this->fromGid($node['id']);
            $available = 0;

            foreach ($node['inventoryLevels']['edges'] ?? [] as $edge) {
                $level = $edge['node'];

                // Normalize both GIDs before comparing — stored locationId may or may
                // not already have the gid:// prefix, so compare numeric IDs only.
                $returnedLocationNumeric = $this->fromGid($level['location']['id'] ?? '');
                $targetLocationNumeric   = $this->fromGid($locationGid);

                if ($returnedLocationNumeric === $targetLocationNumeric) {
                    foreach ($level['quantities'] as $q) {
                        if ($q['name'] === 'available') {
                            $available = $q['quantity'];
                        }
                    }
                }
            }

            $result[] = [
                'inventory_item_id' => $itemId,
                'available'         => $available,
            ];
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

        $data      = $this->graphql->query($query);
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
}