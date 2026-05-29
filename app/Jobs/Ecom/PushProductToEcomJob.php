<?php

namespace App\Jobs\Ecom;

use App\Models\ProductCache;
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

    public function __construct(private readonly int $erpId)
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

        try {
            $ecomProductId = $ecom->syncProduct($template, $variants, $attributeValues);

            $cache->markEcomSent($this->erpId, $ecomProductId);

            Log::info("PushProductToEcomJob [{$ecom->driverName()}]: synced #{$this->erpId} → {$ecomProductId}");

        } catch (\Throwable $e) {
            $cache->markEcomFailed($this->erpId, $e->getMessage());
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