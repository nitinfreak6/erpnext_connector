<?php

namespace App\Jobs\Inbound;

use App\Models\WebhookLog;
use App\Services\SettingsService;
use App\Services\Sync\ProductSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Process Shopify Product Webhook (Create/Update)
 * 
 * Handles product webhooks from Shopify when sync direction is ecom → erp.
 * When a product is created or updated in Shopify, this job syncs it to the ERP.
 * 
 * Direction enforcement:
 * - If product_sync_mode = 'erp_to_ecom': Ignores webhook (products should come FROM erp)
 * - If product_sync_mode = 'ecom_to_erp': Processes webhook and syncs to ERP
 * - If product_sync_mode = 'bidirectional': Processes webhook
 */
class ProcessShopifyProductJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 300, 900];
    public int $timeout = 300;

    public function __construct(
        private readonly int $webhookLogId
    ) {
        $this->onQueue('webhooks');
    }

    public function handle(ProductSyncService $syncService, SettingsService $settings): void
    {
        $webhookLog = WebhookLog::findOrFail($this->webhookLogId);

        if ($webhookLog->processed) {
            return;
        }

        // ── Check sync direction ─────────────────────────────────────────
        $mode = $settings->productSyncMode();
        
        if ($mode === 'erp_to_ecom') {
            // Products should flow FROM ERP TO Ecom, not the other way
            $shopifyProduct = $webhookLog->getDecodedPayload();
            $productId = $shopifyProduct['id'] ?? 'unknown';
            
            Log::info("Product webhook ignored: sync mode is {$mode} (ERP → Ecom)", [
                'webhook_id' => $this->webhookLogId,
                'shopify_product_id' => $productId,
                'product_title' => $shopifyProduct['title'] ?? 'N/A',
            ]);
            
            $webhookLog->update([
                'processed' => true,
                'notes' => "Ignored: sync direction is {$mode} (products should not come from ecommerce)"
            ]);
            
            return;
        }

        // ── Process webhook: Sync Shopify product to ERP ────────────────
        try {
            $shopifyProduct = $webhookLog->getDecodedPayload();
            
            // Validate payload
            if (!isset($shopifyProduct['id'])) {
                throw new \InvalidArgumentException('Webhook payload missing product ID');
            }

            Log::info('Processing Shopify product webhook', [
                'webhook_id' => $this->webhookLogId,
                'shopify_id' => $shopifyProduct['id'],
                'title' => $shopifyProduct['title'] ?? 'N/A',
                'sync_mode' => $mode,
            ]);

            // Sync to ERP (creates or updates based on existing mapping)
            $erpId = $syncService->syncEcomProductToErp($shopifyProduct);

            $webhookLog->markProcessed([
                'erp_id' => $erpId,
                'shopify_id' => $shopifyProduct['id'],
                'action' => 'synced_to_erp',
            ]);

            Log::info('Product webhook processed successfully', [
                'webhook_id' => $this->webhookLogId,
                'shopify_id' => $shopifyProduct['id'],
                'erp_id' => $erpId,
            ]);

        } catch (\Throwable $e) {
            $webhookLog->markFailed($e->getMessage());
            
            Log::error('Product webhook processing failed', [
                'webhook_id' => $this->webhookLogId,
                'error' => $e->getMessage(),
                'trace' => substr($e->getTraceAsString(), 0, 500),
            ]);
            
            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        try {
            $webhookLog = WebhookLog::find($this->webhookLogId);
            if ($webhookLog) {
                $webhookLog->markFailed($e->getMessage());
            }
        } catch (\Throwable) {}

        Log::error('ProcessShopifyProductJob permanently failed', [
            'webhook_id' => $this->webhookLogId,
            'error' => $e->getMessage(),
        ]);
    }
}