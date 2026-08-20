<?php

namespace App\Services\Sync;

use App\Models\ProductCache;
use App\Models\SyncMapping;

/**
 * Product-specific ecom→ERP state (delegates to SyncEntityState).
 *
 * @deprecated Prefer SyncEntityState directly for new code.
 */
class EcomToErpProductState
{
    public const STATUS_PENDING = SyncEntityState::STATUS_PENDING;
    public const STATUS_UPDATED = SyncEntityState::STATUS_UPDATED;
    public const STATUS_SYNCED  = SyncEntityState::STATUS_SYNCED;
    public const STATUS_FAILED  = SyncEntityState::STATUS_FAILED;
    public const SYNCED_STATUSES = SyncEntityState::SYNCED_ALIASES;

    public static function productUpdatedAt(array $product): ?string
    {
        $value = $product['updatedAt'] ?? $product['updated_at'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function normalizeEcomUpdatedAt(?string $value): ?string
    {
        return SyncEntityState::normalizeTimestamp($value);
    }

    public static function productChangedSinceLastSync(?SyncMapping $existing, array $product): bool
    {
        return SyncEntityState::changedSinceLastSync(
            $existing,
            $product,
            [self::class, 'productUpdatedAt']
        );
    }

    public static function needsPush(?SyncMapping $mapping): bool
    {
        return SyncEntityState::needsPush($mapping);
    }

    public static function fetchStatus(?SyncMapping $existing, array $product): ?string
    {
        return SyncEntityState::fetchStatus($existing, $product, [self::class, 'productUpdatedAt']);
    }

    public static function markFetched(
        string $ecomId,
        array $product,
        ?SyncMapping $existing,
        string $filePath,
        array $cacheData,
        string $driver
    ): bool {
        $changed = SyncEntityState::markFetched(
            'product',
            ['ecom_id' => $ecomId, 'ecom_driver' => $driver],
            $product,
            $existing,
            'ecom_to_erp',
            [self::class, 'productUpdatedAt']
        );

        $fetchStatus = self::fetchStatus($existing, $product);

        $display = app(\App\Services\FieldMappingService::class)->extractEcomToErpProductCacheDisplay($product);

        $cacheFields = [
            'name'         => $display['name'],
            'default_code' => $display['default_code'],
            'file_path'    => $filePath,
            'raw_data'     => $cacheData,
            'fetched_at'   => now(),
        ];

        if ($fetchStatus !== null) {
            $cacheFields['ecom_status']     = $fetchStatus;
            $cacheFields['shopify_status']  = $fetchStatus;
            $cacheFields['ecom_message']    = null;
            $cacheFields['shopify_message'] = null;
        }

        ProductCache::updateOrCreate(['ecom_product_id' => $ecomId], $cacheFields);

        SyncMapping::where('entity_type', 'product')
            ->where('ecom_id', $ecomId)
            ->where('ecom_driver', $driver)
            ->update([
                'ecom_handle' => $product['handle'] ?? null,
            ]);

        return $changed;
    }

    public static function markSynced(string $ecomId, ?string $ecomUpdatedAt = null): void
    {
        SyncEntityState::markSynced('product', ['ecom_id' => $ecomId], $ecomUpdatedAt);
    }

    public static function markFailed(string $ecomId, ?string $message = null): void
    {
        SyncEntityState::markFailed('product', ['ecom_id' => $ecomId], $message);
    }

    public static function displayStatus(?SyncMapping $mapping): string
    {
        return SyncEntityState::displayStatus($mapping);
    }
}
