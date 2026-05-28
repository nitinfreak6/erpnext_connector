<?php

namespace App\Services\Sync;

use App\Models\ProductFieldConfig;
use App\Services\ChannelMappingService;
use App\Services\Ecom\EcomInterface;
use App\Services\Erp\ErpInterface;
use App\Services\MappingService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Log;

/**
 * CustomerSyncService - Orchestrates customer sync using UniversalSyncService
 */
class CustomerSyncService
{
    public function __construct(
        private readonly ErpInterface           $erp,
        private readonly EcomInterface          $ecom,
        private readonly UniversalSyncService   $universalSync,
        private readonly MappingService         $mappings,
        private readonly ChannelMappingService  $channelMappings,
        private readonly SettingsService        $settings,
    ) {}

    public function isEnabled(): bool
    {
        return $this->settings->get('customer_sync_enabled', false);
    }

    /**
     * Sync ERP customer to ecommerce
     */
    public function syncCustomerToEcom(array $erpCustomer): string
    {
        if (!$this->isEnabled()) {
            Log::info("CustomerSyncService: customer sync disabled");
            return '';
        }

        $erpId = (string) $erpCustomer['id'];

        try {
            $result = $this->universalSync->syncFromErpToEcom(
                entityType: 'customer',
                erpData: $erpCustomer,
                scope: null
            );

            $ecomId = $result['id'] ?? $result['ecom_id'] ?? null;
            Log::info("CustomerSyncService: synced ERP customer #{$erpId} → ecommerce #{$ecomId}");
            return (string) $ecomId;
        } catch (\Throwable $e) {
            Log::error("CustomerSyncService: ERP→Ecom sync failed for #{$erpId}", [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Sync ecommerce customer to ERP
     */
    public function syncCustomerToErp(array $ecomCustomer): int
    {
        if (!$this->isEnabled()) {
            Log::info("CustomerSyncService: customer sync disabled");
            return 0;
        }

        try {
            $result = $this->universalSync->syncFromEcomToErp(
                entityType: 'customer',
                ecomData: $ecomCustomer,
                scope: null
            );

            $erpId = $result['id'] ?? $result['erp_id'] ?? null;
            Log::info("CustomerSyncService: synced ecommerce customer → ERP #{$erpId}");
            return (int) $erpId;
        } catch (\Throwable $e) {
            Log::error("CustomerSyncService: Ecom→ERP sync failed", [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Sync customer addresses
     */
    public function syncAddresses(array $customer, string $direction = 'erp_to_ecom'): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $syncedAddresses = [];

        foreach ($customer['addresses'] ?? [] as $address) {
            try {
                if ($direction === 'erp_to_ecom') {
                    $result = $this->universalSync->syncFromErpToEcom(
                        entityType: 'customer_address',
                        erpData: $address,
                        scope: null
                    );
                } else {
                    $result = $this->universalSync->syncFromEcomToErp(
                        entityType: 'customer_address',
                        ecomData: $address,
                        scope: null
                    );
                }

                $syncedAddresses[] = $result;
            } catch (\Throwable $e) {
                Log::warning("CustomerSyncService: failed to sync address", [
                    'error' => $e->getMessage(),
                    'address_id' => $address['id'] ?? null,
                ]);
            }
        }

        return $syncedAddresses;
    }

    /**
     * Sync batch of customers
     */
    public function syncBatch(array $customers, string $direction = 'erp_to_ecom'): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $results = [];

        foreach ($customers as $customer) {
            try {
                if ($direction === 'erp_to_ecom') {
                    $result = $this->syncCustomerToEcom($customer);
                } else {
                    $result = $this->syncCustomerToErp($customer);
                }

                $results[] = [
                    'id' => $result,
                    'customer_id' => $customer['id'] ?? null,
                ];
            } catch (\Throwable $e) {
                Log::warning("CustomerSyncService: batch sync failed for customer", [
                    'error' => $e->getMessage(),
                    'customer_id' => $customer['id'] ?? null,
                ]);
            }
        }

        Log::info("CustomerSyncService: batch sync completed", [
            'total' => count($customers),
            'synced' => count($results),
        ]);

        return $results;
    }

    public function getFieldConfigs(string $entityType, string $ecomDriver, string $erpDriver)
    {
        return ProductFieldConfig::query()
            ->where('entity_type', $entityType)
            ->where('ecom_driver', $ecomDriver)
            ->where('erp_driver', $erpDriver)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
    }
}