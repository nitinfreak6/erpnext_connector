<?php

namespace App\Jobs\Ecom;

use App\Models\SyncMapping;
use App\Models\SyncQueueState;
use App\Services\Ecom\EcomInterface;
use App\Services\SettingsService;
use App\Services\Sync\OrderSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Pull NEW and UPDATED orders from ecom → ERP.
 *
 * Cursor: last_ecom_write_date in sync_queue_state (type = 'orders').
 * On first run the cursor is null → fetches last 30 days as a safe bootstrap.
 * After each run the cursor is advanced to now() so next run only gets changes.
 */
class FetchEcomOrdersJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor  = 300;
    public int $timeout    = 600;

    public function __construct()
    {
        $this->onQueue('sync');
    }

    public function handle(EcomInterface $ecom, SettingsService $settings, OrderSyncService $orderSync): void
    {
        if (!$settings->isSalesOrderSyncEnabled()) {
            Log::info('FetchEcomOrdersJob: skipped — order sync disabled.');
            return;
        }

        $syncMode = $settings->salesOrderSyncMode();

        if ($syncMode === 'erp_to_ecom') {
            Log::info('FetchEcomOrdersJob: skipped — mode is erp_to_ecom.');
            return;
        }

        $state = SyncQueueState::forType('orders');

        if ($state->is_running && $state->run_started_at?->gt(now()->subMinutes(10))) {
            Log::warning('FetchEcomOrdersJob: previous run still active, skipping.');
            return;
        }

        $state->markRunning();
        $driver = $ecom->driverName();

        try {
            // Cursor: use last_ecom_write_date so only NEW/UPDATED records are fetched
            $since = $state->last_ecom_write_date ?? now()->subDays(30)->toIso8601String();

            Log::info("FetchEcomOrdersJob [{$driver}]: fetching orders updated since {$since}");

            $orders = $ecom->getOrders([
                'status'           => 'any',
                'updated_at_min'   => $since,   // only changed records — reduces API load
            ]);

            Log::info("FetchEcomOrdersJob [{$driver}]: found " . count($orders) . ' orders.');

            $synced  = 0;
            $skipped = 0;
            $failed  = 0;

            foreach ($orders as $order) {
                try {
                    $ecomId = (string) ($order['id'] ?? '');

                    if (!$ecomId) {
                        continue;
                    }

                    $mapping = SyncMapping::where('entity_type', 'order')
                        ->where('ecom_id', $ecomId)
                        ->first();

                    if ($mapping) {
                        // Already mapped — check if it needs a status update
                        $orderSync->updateOrderInErp($order, $mapping);
                        $skipped++;
                    } else {
                        // New order — create in ERP
                        $orderSync->importOrderToErp($order);
                        $synced++;
                    }
                } catch (\Throwable $e) {
                    Log::error("FetchEcomOrdersJob [{$driver}]: failed for order " . ($order['id'] ?? '?') . ': ' . $e->getMessage());
                    $failed++;
                }
            }

            // Advance cursor to now so next run only fetches changes after this point
            $state->update([
                'is_running'           => false,
                'last_poll_at'         => now(),
                'last_ecom_write_date' => now()->toIso8601String(),
                'run_started_at'       => null,
                'notes'                => "Synced: {$synced}, Updated: {$skipped}, Failed: {$failed}",
            ]);

            Log::info("FetchEcomOrdersJob [{$driver}]: done. Synced: {$synced}, Updated: {$skipped}, Failed: {$failed}");

        } catch (\Throwable $e) {
            $state->update(['is_running' => false, 'notes' => $e->getMessage()]);
            Log::error("FetchEcomOrdersJob [{$driver}]: job failed — " . $e->getMessage());
            throw $e;
        }
    }
}
