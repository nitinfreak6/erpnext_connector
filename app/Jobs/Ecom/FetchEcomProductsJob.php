<?php

namespace App\Jobs\Ecom;

use App\Services\Ecom\EcomInterface;
use App\Services\SettingsService;
use App\Services\Sync\ProductSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Fetch Products from E-commerce Platform
 * 
 * Manual pull of products from the e-commerce platform (Shopify, WooCommerce, etc.)
 * and sync them to the ERP.
 * 
 * Usage:
 *   php artisan sync:pull-products-from-ecom
 *   php artisan sync:pull-products-from-ecom --full
 *   php artisan sync:pull-products-from-ecom --limit=50
 * 
 * Direction enforcement:
 * - Only runs when product_sync_mode is 'ecom_to_erp' or 'bidirectional'
 * - Skipped when mode is 'erp_to_ecom' (products should come from ERP)
 */
class FetchEcomProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(
        private readonly bool $fullSync = false,
        private readonly ?int $limit = null,
        private readonly ?string $updatedSince = null,
    ) {
        $this->onQueue('sync');
    }

    public function handle(
        EcomInterface $ecom,
        ProductSyncService $syncService,
        SettingsService $settings
    ): void {
        // ── Master switch check ─────────────────────────────────────────
        if (!$settings->isProductSyncEnabled()) {
            Log::info('FetchEcomProductsJob: skipped — product sync is disabled in settings.');
            return;
        }

        // ── Direction check ─────────────────────────────────────────────
        $mode = $settings->productSyncMode();
        
        if ($mode === 'erp_to_ecom') {
            Log::info("FetchEcomProductsJob: skipped — sync mode is {$mode} (products should come from ERP, not ecommerce)");
            return;
        }

        $driver = $ecom->driverName();
        
        Log::info("FetchEcomProductsJob [{$driver}]: starting", [
            'mode' => $mode,
            'full_sync' => $this->fullSync,
            'limit' => $this->limit,
        ]);

        try {
            // ── Build filters ────────────────────────────────────────────
            $filters = [];
            
            if ($this->limit) {
                $filters['limit'] = $this->limit;
            }
            
            if (!$this->fullSync && $this->updatedSince) {
                $filters['updated_at_min'] = $this->updatedSince;
            }

            // ── Fetch products from e-commerce platform ─────────────────
            $products = $ecom->getProducts($filters);
            
            $total = count($products);
            Log::info("FetchEcomProductsJob [{$driver}]: fetched {$total} products");

            if ($total === 0) {
                Log::info("FetchEcomProductsJob [{$driver}]: no products to sync");
                return;
            }

            // ── Sync each product to ERP ────────────────────────────────
            $synced = 0;
            $failed = 0;

            foreach ($products as $ecomProduct) {
                try {
                    $erpId = $syncService->syncEcomProductToErp($ecomProduct);
                    $synced++;
                    
                    if ($synced % 10 === 0) {
                        Log::info("FetchEcomProductsJob [{$driver}]: progress {$synced}/{$total}");
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    Log::error("FetchEcomProductsJob [{$driver}]: failed to sync product", [
                        'ecom_id' => $ecomProduct['id'] ?? 'unknown',
                        'title' => $ecomProduct['title'] ?? 'N/A',
                        'error' => $e->getMessage(),
                    ]);
                    
                    // Continue with next product instead of failing entire job
                    continue;
                }
            }

            Log::info("FetchEcomProductsJob [{$driver}]: completed", [
                'total' => $total,
                'synced' => $synced,
                'failed' => $failed,
            ]);

        } catch (\Throwable $e) {
            Log::error("FetchEcomProductsJob [{$driver}]: job failed", [
                'error' => $e->getMessage(),
                'trace' => substr($e->getTraceAsString(), 0, 500),
            ]);
            
            throw $e;
        }
    }
}