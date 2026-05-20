<?php

namespace App\Jobs\Ecom;

use App\Models\SyncQueueState;
use App\Services\Ecom\EcomInterface;
use App\Services\Sync\CustomerSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Fetch customers from Ecom platform (Shopify/WooCommerce/Magento) and sync to ERP
 * 
 * Driver-agnostic: Works with ANY Ecom platform via EcomInterface
 */
class FetchEcomCustomersJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 3600;
    public int $timeout = 600;

    public function __construct()
    {
        $this->onQueue('sync');
    }

    public function handle(EcomInterface $ecom, CustomerSyncService $customerSync): void
    {
        // Use sync_type column instead of type
        $state = SyncQueueState::where('sync_type', 'customers')->first();
        
        if (!$state) {
            Log::error('FetchEcomCustomersJob: No sync_queue_state record found for customers');
            return;
        }
        
        if ($state->is_running) {
            Log::warning('FetchEcomCustomersJob: previous run still active, skipping.');
            return;
        }

        $state->update(['is_running' => true, 'run_started_at' => now()]);

        try {
            $driverName = $ecom->driverName();
            
            Log::info("FetchEcomCustomersJob [{$driverName}]: Starting customer pull...");
            
            // Fetch customers from ecom platform
            // Note: Different platforms have different approaches:
            // - Shopify: GraphQL bulk query or REST API pagination
            // - WooCommerce: REST API with per_page parameter
            // - Magento: SearchCriteria API
            
            $customers = $this->fetchAllCustomers($ecom, $driverName);
            
            Log::info("FetchEcomCustomersJob [{$driverName}]: Found " . count($customers) . " customers");
            
            if (count($customers) === 0) {
                Log::warning("FetchEcomCustomersJob [{$driverName}]: No customers found.");
            }

            $synced = 0;
            $failed = 0;
            $skipped = 0;

            foreach ($customers as $customer) {
                try {
                    // Check if this ecom customer is already mapped
                    $existingMapping = \App\Models\SyncMapping::where('entity_type', 'customer')
                        ->where('ecom_id', $customer['id'])
                        ->first();
                    
                    if ($existingMapping) {
                        $skipped++;
                        Log::debug("FetchEcomCustomersJob [{$driverName}]: Customer {$customer['id']} already mapped to ERP #{$existingMapping->erp_id}, skipping");
                        continue;
                    }
                    
                    // Sync customer to ERP
                    // CustomerSyncService has syncCustomer() not syncFromEcom()
                    $customerSync->syncCustomer($customer);
                    $synced++;
                } catch (\Exception $e) {
                    $failed++;
                    Log::error("FetchEcomCustomersJob [{$driverName}]: Failed to sync customer {$customer['id']}: " . $e->getMessage());
                }
            }

            $state->update([
                'is_running' => false,
                'completed_at' => now(),
                'notes' => "Synced: {$synced}, Failed: {$failed}, Skipped: {$skipped}",
            ]);
            
            Log::info("FetchEcomCustomersJob [{$driverName}]: Completed. Synced: {$synced}, Failed: {$failed}, Skipped: {$skipped}");
            
        } catch (\Throwable $e) {
            $state->update([
                'is_running' => false, 
                'notes' => $e->getMessage()
            ]);
            
            Log::error("FetchEcomCustomersJob: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Fetch all customers from ecom platform with pagination
     */
    private function fetchAllCustomers(EcomInterface $ecom, string $driverName): array
    {
        $allCustomers = [];
        $page = 1;
        $limit = 250; // Fetch in batches
        
        do {
            try {
                // Check if ecom driver has getCustomers method
                if (method_exists($ecom, 'getCustomers')) {
                    // Call with correct parameters (filters array)
                    $customers = $ecom->getCustomers([
                        'limit' => $limit,
                        'page' => $page,
                    ]);
                } else {
                    // Fallback: If method doesn't exist, log and break
                    Log::warning("FetchEcomCustomersJob [{$driverName}]: getCustomers() method not implemented in driver");
                    break;
                }
                
                if (empty($customers)) {
                    break;
                }
                
                $allCustomers = array_merge($allCustomers, $customers);
                $page++;
                
                Log::info("FetchEcomCustomersJob [{$driverName}]: Fetched page {$page}, total: " . count($allCustomers));
                
                // Safety limit: Don't fetch more than 10,000 customers in one job
                if (count($allCustomers) >= 10000) {
                    Log::warning("FetchEcomCustomersJob [{$driverName}]: Reached 10k customer limit, stopping.");
                    break;
                }
                
            } catch (\Exception $e) {
                Log::error("FetchEcomCustomersJob [{$driverName}]: Error fetching page {$page}: " . $e->getMessage());
                break;
            }
            
            // Continue if we got a full page (likely more exist)
        } while (count($customers) === $limit);
        
        return $allCustomers;
    }

    public function failed(\Throwable $exception)
    {
        // Reset the flag if job fails - FIX: use plural table name
        SyncQueueState::where('sync_type', 'customers_pull')->update(['is_running' => false]);
    }
}