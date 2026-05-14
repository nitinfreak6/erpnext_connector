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
     * Sync a single ERP partner/customer to Shopify.
     */
    public function syncCustomer(array $erpPartner): string
    {
        $erpId   = (string) $erpPartner['id'];
        $mapping = $this->mappings->findByOdooId(SyncMapping::TYPE_CUSTOMER, $erpId);

        $payload = $this->shopifyCustomers->buildPayload($erpPartner);

        $log = SyncLog::create([
            'direction'       => SyncLog::DIRECTION_ODOO_TO_SHOPIFY,
            'entity_type'     => SyncMapping::TYPE_CUSTOMER,
            'entity_id'       => $erpId,
            'action'          => $mapping ? 'update' : 'create',
            'status'          => SyncLog::STATUS_PROCESSING,
            'request_payload' => json_encode($payload),
        ]);

        try {
            if ($mapping) {
                $shopifyCustomer = $this->shopifyCustomers->update($mapping->shopify_id, $payload);
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
            ]);

            $log->markSuccess(json_encode(['shopify_customer_id' => $shopifyCustomerId]));
            Log::info("Customer synced: ERP #{$erpId} → Shopify #{$shopifyCustomerId} [{$this->erp->driverName()}]");

            return $shopifyCustomerId;
        } catch (\Throwable $e) {
            $log->markFailed($e->getMessage());
            throw $e;
        }
    }
}
