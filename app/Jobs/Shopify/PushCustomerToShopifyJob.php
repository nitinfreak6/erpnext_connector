<?php

namespace App\Jobs\Shopify;

use App\Services\Sync\CustomerSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PushCustomerToShopifyJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;
    public array $backoff = [60, 300, 900];

    public function __construct(private readonly array $odooPartner)
    {
        $this->onQueue('sync');
    }

    public function uniqueId(): string
    {
        return 'customer_' . $this->odooPartner['id'];
    }

    public function handle(CustomerSyncService $syncService): void
    {
        $syncService->syncCustomer($this->odooPartner);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('PushCustomerToShopifyJob failed', [
            'odoo_id' => $this->odooPartner['id'],
            'error'   => $e->getMessage(),
        ]);
    }
}
