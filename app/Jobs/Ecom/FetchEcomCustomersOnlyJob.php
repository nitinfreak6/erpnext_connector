<?php

namespace App\Jobs\Ecom;

use App\Models\SyncMapping;
use App\Models\SyncQueueState;
use App\Services\Ecom\EcomInterface;
use App\Services\Shopify\ShopifyCustomerService;
use App\Services\Sync\SyncEntityState;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * MANUAL: Fetch customers from Ecom → cache locally only.
 * Does NOT post to ERP. Use PostEcomCustomersToErpJob for that.
 */
class FetchEcomCustomersOnlyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('sync');
    }

    public function handle(EcomInterface $ecom): void
    {
        $driver = $ecom->driverName();
        $state  = SyncQueueState::forType('customers');
        $state->refresh();

        $since = $state->last_ecom_write_date
            ? \Carbon\Carbon::parse($state->last_ecom_write_date)->utc()->subSecond()->toIso8601String()
            : now()->utc()->subDays(30)->toIso8601String();

        Log::info("FetchEcomCustomersOnlyJob [{$driver}]: fetching since {$since}");

        $customers       = $this->fetchUpdatedCustomers($ecom, $driver, $since);
        $total           = count($customers);
        $fetched         = 0;
        $skipped         = 0;
        $latestUpdatedAt = null;
        $updatedAtReader = fn (array $d) => $d['updated_at'] ?? $d['updatedAt'] ?? null;

        foreach ($customers as $customer) {
            $ecomId    = (string) ($customer['id'] ?? '');
            $updatedAt = $updatedAtReader($customer);

            if (!$ecomId) {
                continue;
            }

            $existing = SyncMapping::where('entity_type', 'customer')
                ->where('ecom_id', $ecomId)
                ->where('ecom_driver', $driver)
                ->first();

            if ($existing && !SyncEntityState::changedSinceLastSync($existing, $customer, $updatedAtReader)) {
                $skipped++;
                continue;
            }

            SyncEntityState::markFetched(
                'customer',
                ['ecom_id' => $ecomId, 'ecom_driver' => $driver],
                $customer,
                $existing,
                'ecom_to_erp',
                $updatedAtReader
            );

            $displayName = trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''))
                ?: ($customer['email'] ?? null);

            SyncMapping::where('entity_type', 'customer')
                ->where('ecom_id', $ecomId)
                ->where('ecom_driver', $driver)
                ->update(['ecom_handle' => $displayName ?: ($customer['email'] ?? null)]);

            $fetched++;
            if ($updatedAt && (!$latestUpdatedAt || $updatedAt > $latestUpdatedAt)) {
                $latestUpdatedAt = $updatedAt;
            }
        }

        $notes = match (true) {
            $total === 0   => 'nothing_changed',
            $fetched === 0 => "checked:{$total}" . ($skipped > 0 ? ":skipped:{$skipped}" : ''),
            default        => "fetched:{$fetched}" . ($skipped > 0 ? ":skipped:{$skipped}" : ''),
        };

        $state->update([
            'last_ecom_write_date' => $latestUpdatedAt
                ? \Carbon\Carbon::parse($latestUpdatedAt)->utc()->addSecond()->toIso8601String()
                : $state->last_ecom_write_date,
            'last_poll_at'         => now(),
            'notes'                => $notes,
        ]);

        Log::info("FetchEcomCustomersOnlyJob [{$driver}]: done. Fetched: {$fetched}, Skipped: {$skipped}, Total from API: {$total}");
    }

    private function fetchUpdatedCustomers(EcomInterface $ecom, string $driver, string $since): array
    {
        if ($driver === 'shopify') {
            return $this->fetchShopifyCustomers($since);
        }

        if (!method_exists($ecom, 'getCustomers')) {
            Log::warning("FetchEcomCustomersOnlyJob [{$driver}]: getCustomers() not implemented.");
            return [];
        }

        try {
            return $ecom->getCustomers([
                'updated_at_min' => $since,
                'limit'          => 250,
            ]);
        } catch (\Throwable $e) {
            Log::error("FetchEcomCustomersOnlyJob [{$driver}]: fetch failed — " . $e->getMessage());
            return [];
        }
    }

    private function fetchShopifyCustomers(string $since): array
    {
        $service = app(ShopifyCustomerService::class);
        $all     = [];
        $cursor  = null;
        $limit   = 250;

        do {
            $result   = $service->list([
                'updated_at_min' => $since,
                'limit'          => $limit,
                'cursor'         => $cursor,
            ]);
            $batch    = $result['customers'] ?? [];
            $pageInfo = $result['pageInfo'] ?? [];

            $all = array_merge($all, $batch);

            if (count($all) >= 10000) {
                Log::warning('FetchEcomCustomersOnlyJob [shopify]: reached 10k cap.');
                break;
            }

            $cursor = ($pageInfo['hasNextPage'] ?? false) ? ($pageInfo['endCursor'] ?? null) : null;
        } while ($cursor);

        return $all;
    }
}
