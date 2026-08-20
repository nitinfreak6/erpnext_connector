<?php

namespace App\Jobs\Ecom;

use App\Models\ProductCache;
use App\Models\SyncLog;
use App\Services\Ecom\EcomInterface;
use App\Services\ProductCacheService;
use App\Services\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PushProductToEcomJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int   $tries   = 3;
    public array $backoff = [60, 300, 900];
    public int   $timeout = 300;

    public function __construct(private readonly int|string $erpId)
    {
        $this->onQueue('sync');
    }

    public function handle(
        EcomInterface       $ecom,
        ProductCacheService $cache,
        SettingsService     $settings
    ): void {
        $mode = $settings->productSyncMode();

        if ($mode === 'ecom_to_erp') {
            Log::info("PushProductToEcomJob: skipped #{$this->erpId} — mode is {$mode}");
            return;
        }

        $data = null;

        try {
            $data = $cache->readOrFail($this->erpId);
        } catch (\Throwable) {
            $cacheRecord = ProductCache::where('erp_id', $this->erpId)
                ->orWhere('odoo_id', $this->erpId)
                ->first();
            if ($cacheRecord) {
                $data = $cacheRecord->readCache();
            }
        }

        if (!$data) {
            Log::warning("PushProductToEcomJob [{$ecom->driverName()}]: no cache for #{$this->erpId}");
            $cache->markEcomFailed($this->erpId, 'No cached data found.');
            return;
        }

        $template        = $data['template']         ?? null;
        $variants        = $data['variants']         ?? [];
        $attributeValues = $data['attribute_values'] ?? [];

        if (!$template) {
            Log::warning("PushProductToEcomJob [{$ecom->driverName()}]: no template in cache for #{$this->erpId}");
            $cache->markEcomFailed($this->erpId, 'No template data in cache.');
            return;
        }

        $related = array_filter([
            'vendors' => $data['vendors'] ?? null,
        ], fn ($v) => $v !== null && $v !== []);

        $log = SyncLog::create([
            'direction'   => SyncLog::DIRECTION_ERP_TO_ECOM,
            'entity_type' => 'product',
            'entity_id'   => (string) $this->erpId,
            'action'      => 'sync',
            'status'      => SyncLog::STATUS_PROCESSING,
        ]);

        try {
            $ecomProductId = $ecom->syncProduct($template, $variants, $attributeValues, $related);

            $cache->markEcomSent($this->erpId, $ecomProductId);
			
			$wire = method_exists($ecom, 'takeWireLog') ? $ecom->takeWireLog() : [];

            // request_payload = the outgoing request only (action/query/variables).
            // response_payload = responses only. recordResponse() attaches the
            // response onto each wire entry, so split the two cleanly here.
            $requests = array_map(fn($w) => [
                'action'    => $w['action'] ?? null,
                'query'     => $w['query'] ?? null,
                'variables' => $w['variables'] ?? null,
            ], $wire);

            $responses = array_map(fn($w) => [
                'action'   => $w['action'] ?? null,
                'response' => $w['response'] ?? null,
            ], $wire);

            $log->update([
				'status'           => SyncLog::STATUS_SUCCESS,
				'request_payload'  => $wire ? json_encode($requests, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
				'response_payload' => json_encode(
					$wire
						? ['ecom_product_id' => $ecomProductId, 'driver' => $ecom->driverName(), 'mutations' => $responses]
						: ['ecom_product_id' => $ecomProductId, 'driver' => $ecom->driverName()],
					JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
				),
				'synced_at' => now(),
			]);

            Log::info("PushProductToEcomJob [{$ecom->driverName()}]: synced #{$this->erpId} → {$ecomProductId}");

        } catch (\Throwable $e) {
            // Capture whatever was recorded before the exception so the
            // product detail page shows the real GraphQL payload, not the
            // intermediate buildPayload() output.
            $wire = method_exists($ecom, 'takeWireLog') ? $ecom->takeWireLog() : [];

            $cache->markEcomFailed($this->erpId, $e->getMessage());
            $log->markFailed($e->getMessage());

            if ($wire) {
                $requests = array_map(fn($w) => [
                    'action'    => $w['action'] ?? null,
                    'query'     => $w['query'] ?? null,
                    'variables' => $w['variables'] ?? null,
                ], $wire);

                $responses = array_map(fn($w) => [
                    'action'   => $w['action'] ?? null,
                    'response' => $w['response'] ?? null,
                ], $wire);

                $log->update([
                    'request_payload'  => json_encode($requests, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'response_payload' => json_encode(
                        ['driver' => $ecom->driverName(), 'error' => $e->getMessage(), 'mutations' => $responses],
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    ),
                ]);
            }

            Log::error("PushProductToEcomJob [{$ecom->driverName()}]: failed #{$this->erpId} — " . $e->getMessage());
            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        try {
            app(ProductCacheService::class)->markEcomFailed($this->erpId, $e->getMessage());
        } catch (\Throwable) {}

        Log::error('PushProductToEcomJob permanently failed', [
            'erp_id' => $this->erpId,
            'error'  => $e->getMessage(),
        ]);
    }
}