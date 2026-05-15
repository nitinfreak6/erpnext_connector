<?php

namespace App\Services;

use App\Models\SyncMapping;
use Illuminate\Support\Facades\DB;

class MappingService
{
    public function findByOdooId(string $entityType, string $odooId): ?SyncMapping
    {
        return SyncMapping::where('entity_type', $entityType)
            ->where('odoo_id', $odooId)
            ->first();
    }

    public function findByShopifyId(string $entityType, string $shopifyId): ?SyncMapping
    {
        return SyncMapping::where('entity_type', $entityType)
            ->where('shopify_id', $shopifyId)
            ->first();
    }

    public function findByOdooReference(string $entityType, string $reference): ?SyncMapping
    {
        return SyncMapping::where('entity_type', $entityType)
            ->where('odoo_reference', $reference)
            ->first();
    }

    /**
     * Create or update a mapping record.
     */
    public function upsert(
        string $entityType,
        string $odooId,
        string $shopifyId,
        array $extra = []
    ): SyncMapping {
        return SyncMapping::updateOrCreate(
            ['entity_type' => $entityType, 'odoo_id' => $odooId],
            array_merge(['shopify_id' => $shopifyId , 'last_synced_at' => now(), ], $extra)
        );
    }

    public function touchSyncTime(SyncMapping $mapping): void
    {
        $mapping->update(['last_synced_at' => now()]);
    }

    /**
     * Resolve multiple Odoo IDs to Shopify IDs in one query.
     * Returns [odoo_id => shopify_id]
     */
    public function bulkResolveOdooToShopify(string $entityType, array $odooIds): array
    {
        return SyncMapping::where('entity_type', $entityType)
            ->whereIn('odoo_id', $odooIds)
            ->pluck('shopify_id', 'odoo_id')
            ->toArray();
    }
}
