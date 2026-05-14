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
                'name'   => 'available',
                'reason' => 'correction',
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

                if ($level['location']['id'] === $locationGid) {
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