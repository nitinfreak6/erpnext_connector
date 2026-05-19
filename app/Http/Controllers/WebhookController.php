<?php

namespace App\Http\Controllers;

use App\Jobs\Inbound\ProcessShopifyInventoryUpdateJob;
use App\Jobs\Inbound\ProcessShopifyOrderJob;
use App\Jobs\Inbound\ProcessShopifyOrderUpdateJob;
use App\Jobs\Inbound\ProcessShopifyProductJob;
use App\Models\WebhookLog;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function ordersCreate(Request $request): Response
    {
        return $this->handleWebhook($request, 'orders/create', ProcessShopifyOrderJob::class);
    }

    public function ordersUpdated(Request $request): Response
    {
        return $this->handleWebhook($request, 'orders/updated', ProcessShopifyOrderUpdateJob::class);
    }

    public function productsCreate(Request $request): Response
    {
        return $this->handleWebhook($request, 'products/create', ProcessShopifyProductJob::class);
    }

    public function productsUpdate(Request $request): Response
    {
        return $this->handleWebhook($request, 'products/update', ProcessShopifyProductJob::class);
    }

    public function inventoryLevelsUpdate(Request $request): Response
    {
        return $this->handleWebhook($request, 'inventory_levels/update', ProcessShopifyInventoryUpdateJob::class);
    }

    private function handleWebhook(Request $request, string $topic, string $jobClass): Response
    {
        $webhookId  = $request->header('X-Shopify-Webhook-Id');
        $shopDomain = $request->header('X-Shopify-Shop-Domain');
        $rawBody    = $request->attributes->get('raw_body', $request->getContent());

        // Idempotency: return early if already processed
        if ($webhookId) {
            $existing = WebhookLog::where('shopify_webhook_id', $webhookId)->first();

            if ($existing?->processed) {
                return response('Already processed.', 200);
            }

            if ($existing) {
                // Re-dispatch if not yet processed
                $jobClass::dispatch($existing->id);
                return response('Re-dispatched.', 200);
            }
        }

        // Record webhook
        $log = WebhookLog::create([
            'source'             => 'shopify',
            'topic'              => $topic,
            'shopify_webhook_id' => $webhookId,
            'shop_domain'        => $shopDomain,
            'payload'            => $rawBody,
            'hmac_valid'         => true,
            'processed'          => false,
        ]);

        // Dispatch job
        $jobClass::dispatch($log->id);

        $log->update(['job_dispatched_at' => now()]);

        Log::info("Shopify webhook received: {$topic}", ['webhook_id' => $webhookId]);

        // Always return 200 within 5 seconds to prevent Shopify retry
        return response('OK', 200);
    }
}