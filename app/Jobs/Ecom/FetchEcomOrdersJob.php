<?php

namespace App\Jobs\Ecom;

use App\Models\SyncMapping;
use App\Services\Ecom\EcomInterface;
use App\Services\Erp\ErpInterface;
use App\Services\Sync\OrderSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * FetchEcomOrdersJob
 * 
 * Pulls new/updated orders FROM ecom platform TO ERP.
 * Direction: ecom_to_erp
 */
class FetchEcomOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('sync');
    }

    public function handle(
        EcomInterface $ecom,
        ErpInterface $erp,
        OrderSyncService $orderSync
    ): void {
        $settings = app(\App\Services\SettingsService::class);
        $syncMode = $settings->salesOrderSyncMode();
        
        // Only run if mode includes Ecom → ERP direction
        if ($syncMode === 'erp_to_ecom') {
            Log::info('FetchEcomOrdersJob: Skipped (mode is erp_to_ecom)');
            return;
        }
        
        $driver = $ecom->driverName();
        Log::info("FetchEcomOrdersJob [{$driver}]: Starting order import, mode={$syncMode}");

        try {
            // Fetch orders from ecom platform (last 30 days for initial run)
            $orders = $ecom->getOrders([
                'status' => 'any',
                'created_at_min' => now()->subDays(30)->toIso8601String(),
                'limit' => 50,
            ]);

            Log::info("FetchEcomOrdersJob [{$driver}]: Found " . count($orders) . " orders");

            // Debug: Log orders structure
            if (!empty($orders)) {
                $firstOrder = reset($orders); // Get first element safely
                if (is_array($firstOrder)) {
                    Log::debug("FetchEcomOrdersJob [{$driver}]: First order keys", ['keys' => array_keys($firstOrder)]);
                } else {
                    Log::debug("FetchEcomOrdersJob [{$driver}]: Orders structure", ['type' => gettype($orders), 'orders' => $orders]);
                }
            }

            $synced = 0;
            $skipped = 0;
            $failed = 0;

            foreach ($orders as $order) {
                try {
                    $ecomId = (string) ($order['id'] ?? 'unknown');
                    
                    // Check if already mapped
                    $mapping = SyncMapping::where('entity_type', 'order')
                        ->where('ecom_id', $ecomId)
                        ->first();

                    if ($mapping) {
                        Log::debug("FetchEcomOrdersJob [{$driver}]: Order {$ecomId} already mapped to ERP #{$mapping->erp_id}, skipping");
                        $skipped++;
                        continue;
                    }

                    // Import to ERP
                    $orderSync->importOrderToErp($order);
                    $synced++;

                } catch (\Throwable $e) {
                    $orderId = $order['id'] ?? $order['name'] ?? 'unknown';
                    Log::error("FetchEcomOrdersJob [{$driver}]: Failed to import order {$orderId}: " . $e->getMessage());
                    $failed++;
                }
            }

            Log::info("FetchEcomOrdersJob [{$driver}]: Completed. Imported: {$synced}, Skipped: {$skipped}, Failed: {$failed}");

        } catch (\Throwable $e) {
            Log::error("FetchEcomOrdersJob [{$driver}]: Job failed: " . $e->getMessage());
            throw $e;
        }
    }
}