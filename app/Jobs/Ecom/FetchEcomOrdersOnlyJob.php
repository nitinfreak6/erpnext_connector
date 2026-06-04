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

        // Always read fresh from DB — avoids stale cached cursor
        $state = SyncQueueState::forType('orders');
        $state->refresh();

        // Use UTC ISO format — Shopify requires UTC or timezone-aware timestamp
        $since = $state->last_ecom_write_date
            ? \Carbon\Carbon::parse($state->last_ecom_write_date)->utc()->toIso8601String()
            : now()->utc()->subDays(30)->toIso8601String();

        Log::info("FetchEcomOrdersOnlyJob [{$driver}]: fetching since {$since}");

        $orders = $ecom->getOrders([
            'status'         => 'any',
            'updated_at_min' => $since,
        ]);

        $fetched         = 0;
        $skipped         = 0;
        $latestUpdatedAt = null;

        foreach ($orders as $order) {
            $ecomId    = (string) ($order['id'] ?? '');
            $updatedAt = $order['updated_at'] ?? null;
            if (!$ecomId) continue;

            // Track latest updated_at for cursor
            if ($updatedAt && (!$latestUpdatedAt || $updatedAt > $latestUpdatedAt)) {
                $latestUpdatedAt = $updatedAt;
            }

            // Skip if updated_at matches stored — order hasn't changed regardless of status
            $existing = SyncMapping::whereIn('entity_type', ['order', 'sales_order'])
                ->where('ecom_id', $ecomId)
                ->first();

            if ($existing) {
                $prevMeta      = is_array($existing->metadata) ? $existing->metadata : json_decode($existing->metadata ?? '{}', true);
                $prevUpdatedAt = $prevMeta['updated_at'] ?? null;

                if ($prevUpdatedAt && $updatedAt && $prevUpdatedAt === $updatedAt) {
                    $skipped++;
                    continue;
                }
            }

            // New or updated — store as pending for Post Sales
            SyncMapping::updateOrCreate(
                ['entity_type' => 'sales_order', 'ecom_id' => $ecomId, 'ecom_driver' => $driver],
                [
                    'ecom_handle'         => $order['name'] ?? null,
                    'last_sync_direction' => 'ecom_to_erp',
                    'ecom_status'         => 'pending',
                    'metadata'            => $order,
                    'last_synced_at'      => now(),
                ]
            );
            $fetched++;
        }

        // Advance cursor to latest order updated_at + 1 second
        // Using order timestamps (not now()) so unchanged orders are excluded next run
        $nextCursor = $latestUpdatedAt
            ? \Carbon\Carbon::parse($latestUpdatedAt)->utc()->addSecond()->toIso8601String()
            : now()->utc()->toIso8601String();

        $state->update([
            'last_ecom_write_date' => $nextCursor,
            'last_poll_at'         => now(),
            'notes'                => $fetched === 0 ? 'nothing_changed' : "Fetched: {$fetched}, Skipped: {$skipped}",
        ]);

        Log::info("FetchEcomOrdersOnlyJob [{$driver}]: fetching since {$since}");

        $orders = $ecom->getOrders([
            'status'         => 'any',
            'updated_at_min' => $since,
        ]);

        Log::info("FetchEcomOrdersOnlyJob [{$driver}]: done. Fetched: {$fetched}, Skipped: {$skipped}");
    }
}