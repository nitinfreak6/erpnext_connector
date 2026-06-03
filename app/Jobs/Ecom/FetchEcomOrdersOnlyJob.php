<?php

namespace App\Jobs\Ecom;

use App\Models\SyncMapping;
use App\Models\SyncQueueState;
use App\Services\Ecom\EcomInterface;
use App\Services\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * MANUAL: Fetch orders from Ecom → cache locally only.
 * Does NOT post to ERP. Use PostEcomOrdersToErpJob for that.
 *
 * Cron uses FetchEcomOrdersJob which fetches + posts in one step.
 * Manual flow: Fetch Sales → review → Post Sales.
 */
class FetchEcomOrdersOnlyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('sync');
    }

    public function handle(EcomInterface $ecom, SettingsService $settings): void
    {
        $driver = $ecom->driverName();
        $state  = SyncQueueState::forType('orders');
        $since  = $state->last_ecom_write_date ?? now()->subDays(30)->toIso8601String();

        Log::info("FetchEcomOrdersOnlyJob [{$driver}]: fetching since {$since}");

        $orders = $ecom->getOrders([
            'status'         => 'any',
            'updated_at_min' => $since,
        ]);

        $fetched  = 0;
        $skipped  = 0;

        foreach ($orders as $order) {
            $ecomId = (string) ($order['id'] ?? '');
            if (!$ecomId) continue;

            // Check if already fully posted to ERP
            $existing = SyncMapping::whereIn('entity_type', ['order', 'sales_order'])
                ->where('ecom_id', $ecomId)
                ->first();

            if ($existing && $existing->erp_id && $existing->ecom_status === 'posted') {
                $skipped++;
                continue;
            }
			
			

            // Cache raw order data in metadata — ready for Post Sales
            SyncMapping::updateOrCreate(
                [
                    'entity_type' => 'sales_order',
                    'ecom_id'     => $ecomId,
                    'ecom_driver' => $driver,
                ],
                [
                    'ecom_handle'          => $order['name'] ?? null,
                    'last_sync_direction'  => 'ecom_to_erp',
                    'ecom_status'          => 'pending',
                    'metadata'             => $order,   // cast='array' handles serialisation; do NOT json_encode
                    'last_synced_at'       => now(),
                ]
            );

            $fetched++;
        }

        // Advance cursor
        $state->update([
            'last_ecom_write_date' => now()->toIso8601String(),
            'last_poll_at'         => now(),
            'notes'                => "Fetched: {$fetched}, Skipped: {$skipped}",
        ]);

        Log::info("FetchEcomOrdersOnlyJob [{$driver}]: done. Fetched: {$fetched}, Skipped: {$skipped}");
    }
}