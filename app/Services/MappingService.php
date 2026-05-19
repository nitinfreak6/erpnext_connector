<?php

namespace App\Services;

use App\Models\SyncMapping;
use Illuminate\Support\Facades\DB;

class MappingService
{
    // ── ERP lookups ──────────────────────────────────────────────────────

    public function findByErpId(string $entityType, string $erpId): ?SyncMapping
    {
        return SyncMapping::where('entity_type', $entityType)
            ->where('erp_id', $erpId)
            ->first();
    }

    /** @deprecated Use findByErpId() */
    public function findByOdooId(string $entityType, string $odooId): ?SyncMapping
    {
        return $this->findByErpId($entityType, $odooId);
    }

    public function findByErpReference(string $entityType, string $reference): ?SyncMapping
    {
        return SyncMapping::where('entity_type', $entityType)
            ->where('erp_reference', $reference)
            ->first();
    }

    /** @deprecated Use findByErpReference() */
    public function findByOdooReference(string $entityType, string $reference): ?SyncMapping
    {
        return $this->findByErpReference($entityType, $reference);
    }

    // ── E-commerce lookups ───────────────────────────────────────────────

    public function findByEcomId(string $entityType, string $ecomId, ?string $ecomDriver = null): ?SyncMapping
    {
        $query = SyncMapping::where('entity_type', $entityType)
            ->where('ecom_id', $ecomId);
        
        if ($ecomDriver) {
            $query->where('ecom_driver', $ecomDriver);
        }
        
        return $query->first();
    }

    /** @deprecated Use findByEcomId() */
    public function findByShopifyId(string $entityType, string $shopifyId): ?SyncMapping
    {
        return $this->findByEcomId($entityType, $shopifyId, 'shopify');
    }

    // ── Create/Update ────────────────────────────────────────────────────

    /**
     * Create or update a mapping record.
     * 
     * @param string $entityType Entity type (product, order, customer, etc.)
     * @param string $erpId ERP system's ID
     * @param string $ecomId E-commerce platform's ID
     * @param array $extra Additional fields (ecom_driver, last_sync_direction, etc.)
     */
    public function upsert(
        string $entityType,
        string $erpId,
        string $ecomId,
        array $extra = []
    ): SyncMapping {
        // Set ecom_driver from SettingsService if not provided
        if (!isset($extra['ecom_driver'])) {
            $extra['ecom_driver'] = app(\App\Services\SettingsService::class)->ecomDriver();
        }

        return SyncMapping::updateOrCreate(
            [
                'entity_type' => $entityType,
                'ecom_driver' => $extra['ecom_driver'],
                'erp_id' => $erpId
            ],
            array_merge([
                'ecom_id' => $ecomId,
                'last_synced_at' => now(),
            ], $extra)
        );
    }

    public function touchSyncTime(SyncMapping $mapping): void
    {
        $mapping->update(['last_synced_at' => now()]);
    }

    // ── Bulk operations ──────────────────────────────────────────────────

    /**
     * Resolve multiple ERP IDs to Ecom IDs in one query.
     * Returns [erp_id => ecom_id]
     */
    public function bulkResolveErpToEcom(string $entityType, array $erpIds, ?string $ecomDriver = null): array
    {
        $query = SyncMapping::where('entity_type', $entityType)
            ->whereIn('erp_id', $erpIds);
        
        if ($ecomDriver) {
            $query->where('ecom_driver', $ecomDriver);
        }
        
        return $query->pluck('ecom_id', 'erp_id')->toArray();
    }

    /** @deprecated Use bulkResolveErpToEcom() */
    public function bulkResolveOdooToShopify(string $entityType, array $odooIds): array
    {
        return $this->bulkResolveErpToEcom($entityType, $odooIds, 'shopify');
    }

    /**
     * Resolve multiple Ecom IDs to ERP IDs in one query.
     * Returns [ecom_id => erp_id]
     */
    public function bulkResolveEcomToErp(string $entityType, array $ecomIds, ?string $ecomDriver = null): array
    {
        $query = SyncMapping::where('entity_type', $entityType)
            ->whereIn('ecom_id', $ecomIds);
        
        if ($ecomDriver) {
            $query->where('ecom_driver', $ecomDriver);
        }
        
        return $query->pluck('erp_id', 'ecom_id')->toArray();
    }
}