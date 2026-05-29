<?php

namespace App\Jobs\Ecom;

use App\Models\SyncQueueState;
use App\Services\Ecom\EcomInterface;
use App\Services\SettingsService;
use App\Services\Sync\ProductSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Pull NEW and UPDATED products from ecom → ERP.
 *
 * Cursor: last_ecom_write_date in sync_queue_state (type = 'products').
 * Only products updated since last run are fetched — no repeated full pulls.
 */
class FetchEcomProductsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 600;
    public int $timeout   = 600;

    public function __construct(
        private readonly bool    $fullSync    = false,
        private readonly ?int    $limit       = null,
        private readonly ?string $updatedSince = null,  // manual override — bypasses cursor
    ) {
        $this->onQueue('sync');
    }

    public function handle(EcomInterface $ecom, ProductSyncService $syncService, SettingsService $settings): void
    {
        if (!$settings->isProductSyncEnabled()) {
            Log::info('FetchEcomProductsJob: skipped — product sync disabled.');
            return;
        }

        $mode = $settings->productSyncMode();

        if ($mode === 'erp_to_ecom') {
            Log::info("FetchEcomProductsJob: skipped — mode is {$mode}.");
            return;
        }

        $state  = SyncQueueState::forType('products');
        $driver = $ecom->driverName();

        if ($state->is_running && $state->run_started_at?->gt(now()->subMinutes(10))) {
            Log::warning('FetchEcomProductsJob: previous run still active, skipping.');
            return;
        }

        $state->markRunning();

        try {
            // Determine the updated_since value:
            // 1. Manual override (--since flag on command)
            // 2. Full sync = no filter
            // 3. Incremental = cursor from DB
            if ($this->updatedSince) {
                $since = $this->updatedSince;
            } elseif ($this->fullSync) {
                $since = null;
            } else {
                $since = $state->last_ecom_write_date ?? now()->subDays(30)->toIso8601String();
            }

            Log::info("FetchEcomProductsJob [{$driver}]: fetching products" . ($since ? " updated since {$since}" : ' (full)'));

            $filters = [];
            if ($since) {
                $filters['updated_at_min'] = $since;
            }
            if ($this->limit) {
                $filters['limit'] = $this->limit;
            }

            $products = $ecom->getProducts($filters);
            $total    = count($products);

            Log::info("FetchEcomProductsJob [{$driver}]: found {$total} products.");

            $synced = 0;
            $failed = 0;

            foreach ($products as $ecomProduct) {
                try {
                    $syncService->syncEcomProductToErp($ecomProduct);
                    $synced++;
                } catch (\Throwable $e) {
                    Log::error("FetchEcomProductsJob [{$driver}]: failed for product " . ($ecomProduct['id'] ?? '?') . ': ' . $e->getMessage());
                    $failed++;
                }
            }

            // Advance cursor — next run only fetches changes after this moment
            $state->update([
                'is_running'           => false,
                'last_poll_at'         => now(),
                'last_ecom_write_date' => now()->toIso8601String(),
                'run_started_at'       => null,
                'notes'                => "Synced: {$synced}, Failed: {$failed}",
            ]);

            Log::info("FetchEcomProductsJob [{$driver}]: done. Synced: {$synced}, Failed: {$failed}");

        } catch (\Throwable $e) {
            $state->update(['is_running' => false, 'notes' => $e->getMessage()]);
            Log::error("FetchEcomProductsJob [{$driver}]: job failed — " . $e->getMessage());
            throw $e;
        }
    }
}
