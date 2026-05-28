<?php

namespace App\Jobs\Ecom;

use App\Services\Ecom\EcomInterface;
use App\Services\MappingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * FIX #16: Replaces PushCancellationToShopifyJob.
 * Uses EcomInterface — works with any ecom driver.
 */
class PushCancellationToEcomJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly string $erpOrderId)
    {
        $this->onQueue('sync');
    }

    public function handle(EcomInterface $ecom, MappingService $mappings): void
    {
        $mapping = $mappings->findByErpId('order', $this->erpOrderId);

        if (!$mapping) {
            Log::debug("PushCancellationToEcomJob: no ecom mapping for ERP order #{$this->erpOrderId}, skipping.");
            return;
        }

        try {
            $ecom->cancelOrder($mapping->ecom_id, 'Cancelled in ERP');
            Log::info("PushCancellationToEcomJob [{$ecom->driverName()}]: ERP #{$this->erpOrderId} cancelled in ecom #{$mapping->ecom_id}");
        } catch (\Throwable $e) {
            Log::error("PushCancellationToEcomJob: failed for ERP #{$this->erpOrderId}: " . $e->getMessage());
            throw $e;
        }
    }
}
