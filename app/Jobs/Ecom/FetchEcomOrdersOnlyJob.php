<?php

namespace App\Jobs\Ecom;

use App\Models\SyncMapping;
use App\Models\SyncQueueState;
use App\Services\Ecom\EcomInterface;
use App\Services\Sync\SyncEntityState;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * MANUAL: Fetch orders from Ecom → cache locally only.
 * Does NOT post to ERP. Use PostEcomOrdersToErpJob for that.
 */
class FetchEcomOrdersOnlyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('sync');
    }

    public function handle(EcomInterface $ecom): void
    {
        $driver = $ecom->driverName();
        $state  = SyncQueueState::forType('orders');
        $state->refresh();

        $since = $state->last_ecom_write_date
            ? \Carbon\Carbon::parse($state->last_ecom_write_date)->utc()->toIso8601String()
            : now()->utc()->subDays(30)->toIso8601String();

        Log::info("FetchEcomOrdersOnlyJob [{$driver}]: fetching since {$since}");

        $orders          = $ecom->getOrders(['status' => 'any', 'updated_at_min' => $since]);
        $fetched         = 0;
        $skipped         = 0;
        $latestUpdatedAt = null;
        $updatedAtReader = fn (array $d) => $d['updated_at'] ?? $d['updatedAt'] ?? null;

        foreach ($orders as $order) {
            $ecomId    = (string) ($order['id'] ?? '');
            $updatedAt = $updatedAtReader($order);
            if (!$ecomId) {
                continue;
            }

            if ($updatedAt && (!$latestUpdatedAt || $updatedAt > $latestUpdatedAt)) {
                $latestUpdatedAt = $updatedAt;
            }

            $existing = SyncMapping::whereIn('entity_type', ['order', 'sales_order'])
                ->where('ecom_id', $ecomId)
                ->first();

            if ($existing && !SyncEntityState::changedSinceLastSync($existing, $order, $updatedAtReader)) {
                $skipped++;
                continue;
            }

            if ($existing && SyncEntityState::isShopifyFulfillmentOnlyRefresh($existing, $order)) {
                \App\Services\Sync\SyncPayloadStore::put(
                    'sales_order',
                    'ecom',
                    $ecomId,
                    $order
                );
                SyncMapping::whereIn('entity_type', ['order', 'sales_order'])
                    ->where('ecom_id', $ecomId)
                    ->where('ecom_driver', $driver)
                    ->update([
                        'last_synced_at'  => now(),
                        'ecom_updated_at' => SyncEntityState::normalizeTimestamp($updatedAtReader($order)),
                        'ecom_handle'     => $order['name'] ?? null,
                    ]);
                $skipped++;
                continue;
            }

            SyncEntityState::markFetched(
                'sales_order',
                ['ecom_id' => $ecomId, 'ecom_driver' => $driver],
                $order,
                $existing,
                'ecom_to_erp',
                $updatedAtReader
            );

            SyncMapping::where('entity_type', 'sales_order')
                ->where('ecom_id', $ecomId)
                ->where('ecom_driver', $driver)
                ->update(['ecom_handle' => $order['name'] ?? null]);

            $fetched++;
        }

        $nextCursor = $latestUpdatedAt
            ? \Carbon\Carbon::parse($latestUpdatedAt)->utc()->addSecond()->toIso8601String()
            : now()->utc()->toIso8601String();

        $state->update([
            'last_ecom_write_date' => $nextCursor,
            'last_poll_at'         => now(),
            'notes'                => $fetched === 0 ? 'nothing_changed' : "Fetched: {$fetched}, Skipped: {$skipped}",
        ]);

        Log::info("FetchEcomOrdersOnlyJob [{$driver}]: done. Fetched: {$fetched}, Skipped: {$skipped}");
    }
}
