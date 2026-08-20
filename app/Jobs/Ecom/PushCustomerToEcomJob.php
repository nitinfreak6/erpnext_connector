<?php

namespace App\Jobs\Ecom;

use App\Services\Sync\CustomerSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Push a single customer from ERP to Ecom platform
 * 
 * Driver-agnostic: Works with ANY Ecom platform (Shopify/WooCommerce/Magento)
 */
class PushCustomerToEcomJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        private readonly array $customerData
    ) {
        $this->onQueue('sync');
    }

    public function handle(CustomerSyncService $customerSync): void
    {
        try {
            $customerId = $this->customerData['id'] ?? null;
            
            if (!$customerId) {
                Log::error('PushCustomerToEcomJob: Customer data missing ID', $this->customerData);
                return;
            }

            // Skip customers with no email — Shopify requires it for customerCreate
            $email = $this->customerData['email'] ?? $this->customerData['email_id'] ?? null;
            if (empty($email) || $email === false) {
                Log::info("PushCustomerToEcomJob: skipping customer #{$customerId} — no email address.");
                return;
            }

            // Sync customer to ecom platform
            $customerSync->syncCustomerToEcom($this->customerData);
            
            Log::debug("PushCustomerToEcomJob: Customer #{$customerId} synced successfully");
            
        } catch (\Throwable $e) {
            Log::error("PushCustomerToEcomJob: Failed to sync customer: " . $e->getMessage(), [
                'customer_id' => $this->customerData['id'] ?? null,
                'exception' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }

    public function failed(\Throwable $exception)
    {
        Log::error('PushCustomerToEcomJob failed after retries', [
            'customer_id' => $this->customerData['id'] ?? null,
            'error' => $exception->getMessage(),
        ]);
    }
}