<?php

namespace App\Jobs\Shopify;

use App\Services\ProductCacheService;
use App\Services\Sync\ProductSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PushProductToShopifyJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int   $tries   = 3;
    public array $backoff = [60, 300, 900];
    public int   $timeout = 300;

    public function __construct(private readonly int $odooId)
    {
        $this->onQueue('sync');
    }

    public function uniqueId(): string
    {
        return 'shopify_product_' . $this->odooId;
    }

    public function handle(ProductSyncService $syncService, ProductCacheService $cache): void
    {
        // ── Read everything from JSON cache — zero Odoo API calls ────────
        $data = $cache->readOrFail($this->odooId);

        $template          = $data['template']          ?? null;
        $variants          = $data['variants']          ?? null;
        $attributeValues   = $data['attribute_values']  ?? null;

        if (!$template) {
            Log::warning("PushProductToShopifyJob: no template in cache for #{$this->odooId}");
            $cache->markShopifyFailed($this->odooId, 'No template data in cache.');
            return;
        }

        try {
            // Pass cached variants + attributes → ProductSyncService will NOT call Odoo
            $shopifyProductId = $syncService->syncProduct(
                $template,
                $variants,          // ← from JSON file
                $attributeValues,   // ← from JSON file
            );

            $cache->markShopifySent($this->odooId, $shopifyProductId);

            Log::info("PushProductToShopifyJob: synced #{$this->odooId} → Shopify #{$shopifyProductId}");
        } catch (\Throwable $e) {
            $cache->markShopifyFailed($this->odooId, $e->getMessage());
            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        try {
            app(ProductCacheService::class)->markShopifyFailed($this->odooId, $e->getMessage());
        } catch (\Throwable) {}

        Log::error('PushProductToShopifyJob permanently failed', [
            'odoo_id' => $this->odooId,
            'error'   => $e->getMessage(),
        ]);
    }
}