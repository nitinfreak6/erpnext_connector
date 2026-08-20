<?php



namespace App\Services\Sync;



use App\Models\ProductCache;

use App\Models\SyncMapping;

use Carbon\Carbon;



/**

 * Unified sync status rules for all entity types (product, sales_order, inventory).

 *

 * Product sync vocabulary (UI):

 *   pending  — fetched from source, not yet pushed (or last push failed)

 *   updated  — source changed since last successful push

 *   sent     — successfully pushed to target

 */

class SyncEntityState

{

    public const STATUS_PENDING = 'pending';

    public const STATUS_UPDATED = 'updated';

    public const STATUS_SENT    = 'sent';

    /** @deprecated Stored as pending + sync_message for products. */

    public const STATUS_SYNCED  = 'synced';

    /** @deprecated Stored as pending + sync_message for products. */

    public const STATUS_FAILED  = 'failed';



    /** Legacy values treated as sent. */

    public const SYNCED_ALIASES = ['synced', 'posted', 'sent'];



    /** Statuses that should be included in push-all queries. */

    public const PUSHABLE_STATUSES = [

        self::STATUS_PENDING,

        self::STATUS_UPDATED,

    ];



    /** Stored on dispatch SyncMapping rows (stock.picking fulfillments). */

    public const DISPATCH_PENDING = 'pending_dispatch';

    public const DISPATCH_SENT    = 'dispatched';

    public const DISPATCH_UPDATED = 'updated';



    /** @var list<string> */

    public const DISPATCH_PUSHABLE = [

        self::DISPATCH_PENDING,

        self::DISPATCH_UPDATED,

    ];



    public const DISPATCH_MSG_NO_UPDATE = 'No fulfillment updated';



    /** Map dispatch mapping ecom_status → UI display bucket (pending / sent / updated). */

    public static function normalizeDispatchDisplayStatus(?string $status): ?string

    {

        if ($status === null || $status === '') {

            return null;

        }



        return match ($status) {

            self::DISPATCH_PENDING => self::STATUS_PENDING,

            self::DISPATCH_SENT    => self::STATUS_SENT,

            self::DISPATCH_UPDATED => self::STATUS_UPDATED,

            default                => null,

        };

    }



    public static function dispatchDisplayLabel(?string $dispatchStatus): string

    {

        $normalized = self::normalizeDispatchDisplayStatus($dispatchStatus);

        if ($normalized === null) {

            return 'Not dispatched';

        }



        return self::displayLabel($normalized);

    }



    public static function dispatchBadgeClass(?string $dispatchStatus): string

    {

        $normalized = self::normalizeDispatchDisplayStatus($dispatchStatus);

        if ($normalized === null) {

            return 'bg-gray-100 text-gray-400';

        }



        return self::badgeClass($normalized);

    }



    /**

     * Pick the most actionable dispatch status when an order has multiple pickings.

     *

     * @param  iterable<string|SyncMapping>  $statuses

     */

    public static function aggregateDispatchStatus(iterable $statuses): ?string

    {

        $hasPending = false;

        $hasUpdated = false;

        $hasSent    = false;



        foreach ($statuses as $item) {

            $status = $item instanceof SyncMapping

                ? (string) ($item->ecom_status ?? '')

                : (string) $item;



            match ($status) {

                self::DISPATCH_PENDING => $hasPending = true,

                self::DISPATCH_UPDATED => $hasUpdated = true,

                self::DISPATCH_SENT    => $hasSent = true,

                default                => null,

            };

        }



        if ($hasPending) {

            return self::DISPATCH_PENDING;

        }

        if ($hasUpdated) {

            return self::DISPATCH_UPDATED;

        }

        if ($hasSent) {

            return self::DISPATCH_SENT;

        }



        return null;

    }



    public static function dispatchNeedsPush(?string $dispatchStatus): bool

    {

        return in_array($dispatchStatus, self::DISPATCH_PUSHABLE, true);

    }



    public static function normalizeStatus(?string $status): string

    {

        if ($status === null || $status === '') {

            return self::STATUS_PENDING;

        }



        if (in_array($status, self::SYNCED_ALIASES, true)) {

            return self::STATUS_SENT;

        }



        if (in_array($status, [self::STATUS_PENDING, self::STATUS_UPDATED, self::STATUS_FAILED], true)) {

            return $status === self::STATUS_FAILED ? self::STATUS_PENDING : $status;

        }



        return self::STATUS_PENDING;

    }



    public static function displayLabel(?string $status): string

    {

        return match (self::normalizeDisplayStatus($status)) {

            self::STATUS_PENDING => 'Pending',

            self::STATUS_UPDATED => 'Updated',

            self::STATUS_SENT    => 'Sent',

            default              => 'Pending',

        };

    }



    public static function badgeClass(?string $status): string

    {

        return match (self::normalizeDisplayStatus($status)) {

            self::STATUS_SENT    => 'bg-emerald-100 text-emerald-700',

            self::STATUS_UPDATED => 'bg-blue-100 text-blue-700',

            default              => 'bg-amber-100 text-amber-700',

        };

    }



    public static function normalizeDisplayStatus(?string $status): string

    {

        if ($status === null || $status === '') {

            return self::STATUS_PENDING;

        }



        if (in_array($status, self::SYNCED_ALIASES, true)) {

            return self::STATUS_SENT;

        }

 

        if ($status === self::STATUS_UPDATED) {

            return self::STATUS_UPDATED;

        }



        if (in_array($status, [self::STATUS_PENDING, self::STATUS_FAILED], true)) {

            return self::STATUS_PENDING;

        }



        return self::STATUS_PENDING;

    }



    public static function needsPush(?SyncMapping $mapping): bool

    {

        if (!$mapping) {

            return true;

        }



        if (!self::hasTargetId($mapping)) {

            return true;

        }



        return in_array(

            self::normalizeDisplayStatus($mapping->ecom_status),

            [self::STATUS_PENDING, self::STATUS_UPDATED],

            true

        );

    }



    public static function hasTargetId(?SyncMapping $mapping): bool

    {

        if (!$mapping) {

            return false;

        }



        $direction = $mapping->last_sync_direction ?? 'ecom_to_erp';



        if (in_array($direction, ['ecom_to_erp', 'shopify_to_erp', 'shopify_to_odoo'], true)) {

            return (bool) ($mapping->erp_id && $mapping->erp_id !== '0');

        }



        return (bool) ($mapping->ecom_id && $mapping->ecom_id !== '0');

    }

    /** Reject empty / zero ERP ids (would violate sync_mappings_entity_ecom_erp_unique). */
    public static function requireErpId(mixed $id, string $context = 'ERP id'): string
    {
        $normalized = trim((string) $id);

        if ($normalized === '' || $normalized === '0') {
            $erpName = app(\App\Services\SettingsService::class)->erpDisplayName();

            throw new \RuntimeException(
                "{$context} was missing or invalid after fulfillment push. Check sync logs for {$erpName} errors."
            );
        }

        return $normalized;
    }

    /** Qty-based change reader for Shopify inventory (no updated_at on levels). */
    public static function inventoryQtyReader(): callable
    {
        return fn (array $d): string => (string) app(InventorySyncService::class)->qtyFromStoredPayload($d);
    }

    /** Qty reader for Odoo stock.quant payloads (available = quantity − reserved). */
    public static function erpInventoryQtyReader(): callable
    {
        return function (array $d): string {
            if (array_key_exists('available_quantity', $d)) {
                return (string) (int) $d['available_quantity'];
            }

            if (array_key_exists('available', $d) && ! array_key_exists('quantity', $d)) {
                return (string) (int) $d['available'];
            }

            $qty      = (float) ($d['quantity'] ?? 0);
            $reserved = (float) ($d['reserved_quantity'] ?? 0);

            return (string) (int) max(0, $qty - $reserved);
        };
    }

    public static function inventoryQtyReaderForDirection(string $direction): callable
    {
        return in_array($direction, ['ecom_to_erp', 'shopify_to_erp', 'shopify_to_odoo'], true)
            ? self::inventoryQtyReader()
            : self::erpInventoryQtyReader();
    }

    /** Display qty for inventory list rows. */
    public static function displayInventoryQty(array $meta, string $syncMode): ?int
    {
        if ($syncMode === 'erp_to_ecom') {
            if (array_key_exists('available_quantity', $meta)) {
                return (int) $meta['available_quantity'];
            }

            if (array_key_exists('available', $meta)) {
                return (int) $meta['available'];
            }

            $qty      = (float) ($meta['quantity'] ?? 0);
            $reserved = (float) ($meta['reserved_quantity'] ?? 0);

            if ($qty > 0 || $reserved > 0 || array_key_exists('quantity', $meta)) {
                return (int) max(0, $qty - $reserved);
            }

            return isset($meta['qty']) ? (int) $meta['qty'] : null;
        }

        return (int) app(InventorySyncService::class)->qtyFromStoredPayload($meta);
    }

    public static function inventorySourceChanged(?SyncMapping $existing, array $source, string $direction): bool
    {
        if (! $existing) {
            return true;
        }

        $meta   = $existing->payload() ?? [];
        $reader = self::inventoryQtyReaderForDirection($direction);

        if ($reader($meta) !== $reader($source)) {
            return true;
        }

        if (! in_array($direction, ['ecom_to_erp', 'shopify_to_erp', 'shopify_to_odoo'], true)) {
            $prevWrite = self::normalizeTimestamp($meta['write_date'] ?? null);
            $newWrite  = self::normalizeTimestamp($source['write_date'] ?? null);

            if ($prevWrite && $newWrite && $prevWrite !== $newWrite) {
                return true;
            }
        }

        return false;
    }

    public static function normalizeTimestamp(?string $value): ?string

    {

        if ($value === null || $value === '') {

            return null;

        }



        try {

            return Carbon::parse($value)->format('Y-m-d H:i:s');

        } catch (\Throwable) {

            return null;

        }

    }



    /**

     * @param  callable(array): ?string  $updatedAtReader  Extract updated-at from source payload

     */

    public static function changedSinceLastSync(

        ?SyncMapping $existing,

        array $sourceData,

        callable $updatedAtReader

    ): bool {

        $updatedAt = $updatedAtReader($sourceData);



        if (!$existing) {

            return true;

        }



        if (!self::hasTargetId($existing)) {

            $meta = $existing?->payload() ?? [];

            $prev = $updatedAtReader($meta);

            if ($prev && $updatedAt) {

                return Carbon::parse($updatedAt)->gt($prev);

            }

            if ($updatedAt) {

                return true;

            }

            return false;

        }



        if (!$updatedAt) {

            return false;

        }



        if (!$existing->ecom_updated_at) {

            $meta = $existing->payload() ?? [];

            $prev = $updatedAtReader($meta);

            if ($prev && $updatedAt) {

                return Carbon::parse($updatedAt)->gt(Carbon::parse($prev));

            }

            if ($updatedAt) {

                return true;

            }

            return !self::hasTargetId($existing);

        }



        return Carbon::parse($updatedAt)->gt(Carbon::parse($existing->ecom_updated_at));

    }

    /**
     * Shopify bumps updated_at when fulfillments ship — skip re-queueing ERP push
     * for orders already linked and successfully synced to Odoo.
     */
    public static function isShopifyFulfillmentOnlyRefresh(?SyncMapping $existing, array $shopifyOrder): bool
    {
        if (!$existing || !$existing->erp_id) {
            return false;
        }

        $display = self::normalizeDisplayStatus($existing->ecom_status ?? '');
        if ($display !== self::STATUS_SENT && !in_array($existing->ecom_status, self::SYNCED_ALIASES, true)) {
            return false;
        }

        $fulfillment = strtolower((string) ($shopifyOrder['fulfillment_status'] ?? ''));

        return in_array($fulfillment, ['fulfilled', 'partial', 'shipped'], true);
    }

    /**
     * Odoo bumps write_date when a delivery is validated — skip re-queueing Shopify push
     * for orders already sent to e-commerce.
     */
    public static function isOdooDeliveryOnlyRefresh(?SyncMapping $existing, array $odooOrder): bool
    {
        if (!$existing || !$existing->ecom_id) {
            return false;
        }

        $display = self::normalizeDisplayStatus($existing->ecom_status ?? '');
        if ($display !== self::STATUS_SENT && !in_array($existing->ecom_status, self::SYNCED_ALIASES, true)) {
            return false;
        }

        $state    = (string) ($odooOrder['state'] ?? '');
        $delivery = (string) ($odooOrder['delivery_status'] ?? '');

        return in_array($state, ['sale', 'done'], true)
            && in_array($delivery, ['full', 'partial'], true);
    }

    /**
     * @param  callable(array): ?string  $updatedAtReader
     */
    public static function fetchStatus(

        ?SyncMapping $existing,

        array $sourceData,

        callable $updatedAtReader

    ): ?string {

        if (!self::changedSinceLastSync($existing, $sourceData, $updatedAtReader)) {

            return null;

        }



        return $existing ? self::STATUS_UPDATED : self::STATUS_PENDING;

    }



    public static function markFetched(

        string $entityType,

        array $keys,

        array $sourceData,

        ?SyncMapping $existing,

        string $direction,

        ?callable $updatedAtReader = null

    ): bool {

        if ($entityType === 'inventory') {

            return self::markInventoryFetched($entityType, $keys, $sourceData, $existing, $direction);

        }

        $reader      = $updatedAtReader ?? fn (array $d) => $d['updatedAt'] ?? $d['updated_at'] ?? $d['write_date'] ?? null;

        $changed     = self::changedSinceLastSync($existing, $sourceData, $reader);

        $fetchStatus = self::fetchStatus($existing, $sourceData, $reader);

        $updatedAt   = $reader($sourceData);

        if ($entityType !== 'product') {
            $side      = SyncPayloadStore::sideForDirection($direction);
            $payloadId = SyncPayloadStore::idFromKeys($side, $keys);
            if ($payloadId) {
                SyncPayloadStore::put($entityType, $side, $payloadId, $sourceData);
            }
        }

        $mappingFields = array_merge($keys, [

            'metadata'            => null,

            'last_synced_at'      => now(),

            'last_sync_direction' => $direction,

            'ecom_updated_at'     => self::normalizeTimestamp($updatedAt),

        ]);



        if ($fetchStatus !== null) {

            $mappingFields['ecom_status']  = $fetchStatus;

            $mappingFields['sync_message'] = null;

        }



        SyncMapping::updateOrCreate(

            array_merge(['entity_type' => $entityType], $keys),

            $mappingFields

        );



        return $changed;

    }



    public static function markInventoryFetched(

        string $entityType,

        array $keys,

        array $sourceData,

        ?SyncMapping $existing,

        string $direction

    ): bool {

        $changed = self::inventorySourceChanged($existing, $sourceData, $direction);

        $side      = SyncPayloadStore::sideForDirection($direction);
        $payloadId = SyncPayloadStore::idFromKeys($side, $keys);

        if ($payloadId) {
            SyncPayloadStore::put($entityType, $side, $payloadId, $sourceData);
        }

        if (! $changed) {
            SyncMapping::updateOrCreate(
                array_merge(['entity_type' => $entityType], $keys),
                array_merge($keys, [
                    'last_synced_at'      => now(),
                    'last_sync_direction' => $direction,
                    'sync_message'        => null,
                ])
            );

            return false;
        }

        // Same rule as products: first fetch → Pending, re-fetch with changes → Updated.
        $fetchStatus = $existing ? self::STATUS_UPDATED : self::STATUS_PENDING;

        $updatedAt = in_array($direction, ['ecom_to_erp', 'shopify_to_erp', 'shopify_to_odoo'], true)

            ? null

            : ($sourceData['write_date'] ?? null);

        $mappingFields = array_merge($keys, [

            'metadata'            => null,

            'last_synced_at'      => now(),

            'last_sync_direction' => $direction,

            'ecom_status'         => $fetchStatus,

            'sync_message'        => null,

        ]);

        if ($updatedAt) {

            $mappingFields['ecom_updated_at'] = self::normalizeTimestamp($updatedAt);

        }

        SyncMapping::updateOrCreate(

            array_merge(['entity_type' => $entityType], $keys),

            $mappingFields

        );

        return true;

    }



    public static function markSynced(

        string $entityType,

        array $keys,

        ?string $updatedAt = null

    ): void {

        $updates = [

            'ecom_status'  => self::STATUS_SENT,

            'sync_message' => null,

        ];

        $normalized = self::normalizeTimestamp($updatedAt);

        if ($normalized) {

            $updates['ecom_updated_at'] = $normalized;

        }



        self::mappingQuery($entityType, $keys)->update($updates);



        if ($entityType === 'product') {

            self::updateProductCacheStatus($keys, self::STATUS_SENT, null);

        }

    }



    public static function markFailed(string $entityType, array $keys, ?string $message = null): void

    {

        $truncated = self::truncateMessage(
            is_string($message) ? (SyncErrorFormatter::short($message) ?? $message) : null
        );



        self::mappingQuery($entityType, $keys)->update([

            'ecom_status'  => self::STATUS_PENDING,

            'sync_message' => $truncated,

        ]);



        if ($entityType === 'product') {

            self::updateProductCacheStatus($keys, self::STATUS_PENDING, $truncated);

        }

    }



    public static function displayStatus(?SyncMapping $mapping): string
    {
        if (!$mapping) {
            return self::STATUS_PENDING;
        }

        if ($mapping->ecom_status !== null && $mapping->ecom_status !== '') {
            return self::normalizeDisplayStatus($mapping->ecom_status);
        }

        return self::hasTargetId($mapping) && !filled($mapping->sync_message ?? null)
            ? self::STATUS_SENT
            : self::STATUS_PENDING;
    }



    private static function updateProductCacheStatus(array $keys, string $status, ?string $message): void

    {

        $payload = [

            'ecom_status'    => $status,

            'shopify_status' => $status,

            'ecom_message'   => $message,

            'shopify_message'=> $message,

        ];



        if ($status === self::STATUS_SENT) {

            $payload['ecom_synced_at']    = now();

            $payload['shopify_synced_at'] = now();

        }



        if (isset($keys['ecom_id'])) {

            ProductCache::where('ecom_product_id', $keys['ecom_id'])->update($payload);

        }



        if (isset($keys['erp_id'])) {

            $col = ProductCache::erpIdColumn();

            ProductCache::where($col, $keys['erp_id'])->update($payload);

        }

    }



    private static function truncateMessage(?string $message): ?string

    {

        if ($message === null || $message === '') {

            return null;

        }



        return strlen($message) > 2000 ? substr($message, 0, 2000) . '…' : $message;

    }



    private static function mappingQuery(string $entityType, array $keys): \Illuminate\Database\Eloquent\Builder

    {

        $types = in_array($entityType, ['order', 'sales_order'], true)
            ? ['order', 'sales_order']
            : [$entityType];

        $query = SyncMapping::whereIn('entity_type', $types);

        foreach ($keys as $column => $value) {

            if ($value === null || $value === '') {
                continue;
            }

            $query->where($column, $value);

        }



        return $query;

    }

}


