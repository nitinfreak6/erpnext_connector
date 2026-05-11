<?php

namespace App\Jobs\Amazon;

use App\Services\ProductCacheService;
use App\Services\Sync\AmazonProductSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PushProductToAmazonJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int   $tries   = 3;
    public array $backoff = [120, 600, 1800];
    public int   $timeout = 120;

    public function __construct(private readonly int $odooId)
    {
        $this->onQueue('sync');
    }

    public function uniqueId(): string
    {
        return 'amazon_product_' . $this->odooId;
    }

    public function handle(AmazonProductSyncService $syncService, ProductCacheService $cache): void
    {
        // ── Read everything from JSON cache — zero Odoo API calls ────────
        $data = $cache->readOrFail($this->odooId);

        $template           = $data['template']            ?? null;
        $variants           = $data['variants']            ?? null;
        $productAttributes  = $data['product_attributes']  ?? null;

        if (!$template) {
            Log::warning("PushProductToAmazonJob: no template in cache for #{$this->odooId}");
            $cache->markAmazonFailed($this->odooId, 'No template data in cache.');
            return;
        }

        try {
            // Pass cached variants + product attributes → AmazonProductSyncService will NOT call Odoo
            $result = $syncService->syncProduct(
                $template,
                $variants,           // ← from JSON file
                $productAttributes,  // ← from JSON file
            );

            $failed  = $result['failed'] ?? [];
            $synced  = $result['synced'] ?? [];

            if (!empty($failed)) {
                $cache->markAmazonFailed($this->odooId, 'Failed SKUs: ' . implode(', ', $failed));

                Log::warning("PushProductToAmazonJob: partial failure for #{$this->odooId}", [
                    'synced' => $synced,
                    'failed' => $failed,
                ]);
            } else {
                $cache->markAmazonSent($this->odooId, 'SKUs: ' . implode(', ', $synced));

                Log::info("PushProductToAmazonJob: synced #{$this->odooId} → Amazon", [
                    'synced' => $synced,
                ]);
            }
        } catch (\Throwable $e) {
            $cache->markAmazonFailed($this->odooId, $e->getMessage());
            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        try {
            app(ProductCacheService::class)->markAmazonFailed($this->odooId, $e->getMessage());
        } catch (\Throwable) {}

        Log::error('PushProductToAmazonJob permanently failed', [
            'odoo_id' => $this->odooId,
            'error'   => $e->getMessage(),
        ]);
    }

    public function retryUntil(): \DateTime
    {
        return now()->addHours(2);
    }
}