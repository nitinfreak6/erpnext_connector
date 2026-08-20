<?php

namespace App\Services\Sync;

/**
 * Resolve Shopify variant → inventory item IDs from cached/live product payloads.
 */
final class InventoryItemCatalog
{
    /**
     * @return array{0: list<string>, 1: array<string, array{ecom_id: string, sku: string|null}>}
     */
    public static function collectForDriver(string $driver, ?\App\Services\Ecom\EcomInterface $ecom = null): array
    {
        $inventoryItemIds = [];
        $itemToProduct    = [];

        $caches = \App\Models\ProductCache::query()
            ->where(function ($q) {
                $q->whereNotNull('ecom_product_id')
                    ->orWhereNotNull('shopify_product_id');
            })
            ->get();

        foreach ($caches as $cache) {
            $ecomId = (string) ($cache->ecom_product_id ?? '');
            if ($ecomId === '') {
                continue;
            }

            self::mergeVariantsFromPayload(
                $cache->readCache(),
                $ecomId,
                $inventoryItemIds,
                $itemToProduct
            );
        }

        $productMappings = \App\Models\SyncMapping::query()
            ->where('entity_type', 'product')
            ->where('ecom_driver', $driver)
            ->whereNotNull('ecom_id')
            ->get();

        foreach ($productMappings as $mapping) {
            $ecomId  = (string) $mapping->ecom_id;
            $payload = $mapping->payload();

            if (!$payload) {
                $cache = \App\Models\ProductCache::query()
                    ->where(function ($q) use ($ecomId) {
                        $q->where('ecom_product_id', $ecomId)
                            ->orWhere('shopify_product_id', $ecomId);
                    })
                    ->first();
                $payload = $cache !== null ? $cache->readCache() : null;
            }

            self::mergeVariantsFromPayload($payload, $ecomId, $inventoryItemIds, $itemToProduct);
        }

        if ($inventoryItemIds === [] && $ecom !== null && $productMappings->isNotEmpty()) {
            foreach ($productMappings as $mapping) {
                $ecomId  = (string) $mapping->ecom_id;
                $product = $ecom->getProduct($ecomId);

                if ($product) {
                    self::mergeVariantsFromPayload($product, $ecomId, $inventoryItemIds, $itemToProduct);
                }
            }
        }

        return [$inventoryItemIds, $itemToProduct];
    }

    public static function variantInventoryItemId(array $variant): ?string
    {
        $raw = $variant['inventory_item_id']
            ?? $variant['inventoryItemId']
            ?? null;

        if (($raw === null || $raw === '') && isset($variant['inventoryItem']) && is_array($variant['inventoryItem'])) {
            $raw = $variant['inventoryItem']['id'] ?? null;
        }

        if ($raw === null || $raw === '') {
            return null;
        }

        return (string) $raw;
    }

    /**
     * @param  list<string>  $inventoryItemIds
     * @param  array<string, array{ecom_id: string, sku: string|null}>  $itemToProduct
     */
    public static function mergeVariantsFromPayload(
        ?array $meta,
        string $ecomProductId,
        array &$inventoryItemIds,
        array &$itemToProduct
    ): void {
        if (!$meta) {
            return;
        }

        $product  = $meta['product'] ?? $meta;
        $variants = $product['variants'] ?? $meta['variants'] ?? [];

        if (!is_array($variants)) {
            return;
        }

        foreach ($variants as $variant) {
            if (!is_array($variant)) {
                continue;
            }

            $itemId = self::variantInventoryItemId($variant);
            if ($itemId === null) {
                continue;
            }

            $inventoryItemIds[] = $itemId;
            $itemToProduct[$itemId] = [
                'ecom_id' => $ecomProductId,
                'sku'     => $variant['sku'] ?? null,
            ];
        }
    }
}
