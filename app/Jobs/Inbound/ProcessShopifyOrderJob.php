<?php

namespace App\Jobs\Inbound;

use App\Models\WebhookLog;
use App\Services\SettingsService;
use App\Services\Sync\OrderSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessShopifyOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 300, 900];
    public int $timeout = 60;

    public function __construct(private readonly int $webhookLogId)
    {
        $this->onQueue('webhooks');
    }

    public function handle(OrderSyncService $orderSync, SettingsService $settings): void
    {
        $webhookLog = WebhookLog::findOrFail($this->webhookLogId);

        if ($webhookLog->processed) {
            return;
        }

        // ── Check sync direction ─────────────────────────────────────────
        $mode = $settings->salesOrderSyncMode();
        
        if ($mode === 'erp_to_ecom') {
            // Orders should flow FROM ERP TO Ecom, not the other way
            Log::info("Order webhook ignored: sync mode is {$mode} (ERP → Ecom)", [
                'webhook_id' => $this->webhookLogId,
                'order_id' => $webhookLog->getDecodedPayload()['id'] ?? 'unknown',
            ]);
            
            $webhookLog->update([
                'processed' => true,
                'notes' => "Ignored: sync direction is {$mode} (orders should not come from ecommerce)"
            ]);
            
            return;
        }

        try {
            $shopifyOrder = $webhookLog->getDecodedPayload();

            $orderSync->createInOdoo($shopifyOrder);

            $webhookLog->markProcessed();
        } catch (\Throwable $e) {
            $webhookLog->markFailed($e->getMessage());
            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('ProcessShopifyOrderJob failed', [
            'webhook_log_id' => $this->webhookLogId,
            'error'          => $e->getMessage(),
        ]);
    }
}