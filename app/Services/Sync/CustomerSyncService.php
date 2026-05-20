<?php

namespace App\Services\Sync;

use App\Models\SyncLog;
use App\Models\SyncMapping;
use App\Services\Erp\ErpInterface;
use App\Services\MappingService;
use App\Services\Shopify\ShopifyCustomerService;
use Illuminate\Support\Facades\Log;

class CustomerSyncService
{
    public function __construct(
        private readonly ErpInterface           $erp,              // ← was OdooCustomerService
        private readonly ShopifyCustomerService $shopifyCustomers,
        private readonly MappingService         $mappings,
    ) {}

    /**
     * Sync a single customer (detects direction automatically).
     * If data has 'write_date' → ERP data (sync TO ecom)
     * If data has Shopify structure → Ecom data (sync TO ERP)
     */
    public function syncCustomer(array $customerData): string
    {
        // Detect direction based on data structure
        $isFromErp = isset($customerData['write_date']) || isset($customerData['customer_rank']);
        
        if ($isFromErp) {
            return $this->syncErpToEcom($customerData);
        } else {
            return $this->syncEcomToErp($customerData);
        }
    }

    /**
     * Sync ERP customer TO ecom platform
     */
    private function syncErpToEcom(array $erpPartner): string
    {
        $erpId   = (string) $erpPartner['id'];
        $mapping = $this->mappings->findByErpId(SyncMapping::TYPE_CUSTOMER, $erpId);

        $payload = $this->shopifyCustomers->buildPayload($erpPartner);

        $log = SyncLog::create([
            'direction'       => 'erp_to_ecom',
            'entity_type'     => SyncMapping::TYPE_CUSTOMER,
            'entity_id'       => $erpId,
            'action'          => $mapping ? 'update' : 'create',
            'status'          => SyncLog::STATUS_PROCESSING,
            'request_payload' => json_encode($payload),
        ]);

        try {
            if ($mapping) {
                $shopifyCustomer = $this->shopifyCustomers->update($mapping->ecom_id, $payload);
            } else {
                $email    = $erpPartner['email'] ?? '';
                $existing = $email ? $this->shopifyCustomers->findByEmail($email) : null;

                if ($existing) {
                    $shopifyCustomer = $this->shopifyCustomers->update((string) $existing['id'], $payload);
                } else {
                    $shopifyCustomer = $this->shopifyCustomers->create($payload);
                }
            }

            $shopifyCustomerId = (string) $shopifyCustomer['id'];

            $this->mappings->upsert(SyncMapping::TYPE_CUSTOMER, $erpId, $shopifyCustomerId, [
                'last_synced_at' => now(),
                'last_sync_direction' => 'erp_to_ecom',
            ]);

            $log->markSuccess(json_encode(['shopify_customer_id' => $shopifyCustomerId]));
            Log::info("Customer synced: ERP #{$erpId} → Ecom #{$shopifyCustomerId} [{$this->erp->driverName()}]");

            return $shopifyCustomerId;
        } catch (\Throwable $e) {
            $log->markFailed($e->getMessage());
            throw $e;
        }
    }

    /**
     * Sync Ecom customer TO ERP
     */
    private function syncEcomToErp(array $ecomCustomer): string
    {
        $ecomId  = (string) $ecomCustomer['id'];
        $mapping = $this->mappings->findByEcomId(SyncMapping::TYPE_CUSTOMER, $ecomId);

        // Build ERP payload from ecom data
        $erpPayload = [
            'name'  => $ecomCustomer['name'] ?? 'Ecom Customer',
            'email' => $ecomCustomer['email'] ?? '',
            'phone' => $ecomCustomer['phone'] ?? '',
        ];

        $log = SyncLog::create([
            'direction'       => 'ecom_to_erp',
            'entity_type'     => SyncMapping::TYPE_CUSTOMER,
            'entity_id'       => $ecomId,
            'action'          => $mapping ? 'update' : 'create',
            'status'          => SyncLog::STATUS_PROCESSING,
            'request_payload' => json_encode($erpPayload),
        ]);

        try {
            if ($mapping) {
                // Update existing ERP customer
                $this->erp->updateCustomer((int)$mapping->erp_id, $erpPayload);
                $erpId = $mapping->erp_id;
            } else {
                // Check if customer exists by email
                $email = $ecomCustomer['email'] ?? '';
                $existing = $email ? $this->erp->findCustomerByEmail($email) : null;

                if ($existing) {
                    $erpId = (string) $existing['id'];
                    $this->erp->updateCustomer((int)$erpId, $erpPayload);
                } else {
                    // Create new customer in ERP
                    $erpId = (string) $this->erp->createCustomer($erpPayload);
                }
            }

            $this->mappings->upsert(SyncMapping::TYPE_CUSTOMER, $erpId, $ecomId, [
                'last_synced_at' => now(),
                'last_sync_direction' => 'ecom_to_erp',
            ]);

            $log->markSuccess(json_encode(['erp_customer_id' => $erpId]));
            Log::info("Customer synced: Ecom #{$ecomId} → ERP #{$erpId} [{$this->erp->driverName()}]");

            return $erpId;
        } catch (\Throwable $e) {
            $log->markFailed($e->getMessage());
            throw $e;
        }
    }
}