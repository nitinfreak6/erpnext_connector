<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\WebhookController;
use App\Http\Middleware\VerifyShopifyWebhook;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Shopify Webhook Routes
|--------------------------------------------------------------------------
| HMAC signature is verified in VerifyShopifyWebhook middleware.
| Always return 200 fast — heavy work is dispatched to the queue.
*/
Route::prefix('webhooks/shopify')
    ->middleware(VerifyShopifyWebhook::class)
    ->group(function () {
        Route::post('orders/create',           [WebhookController::class, 'ordersCreate']);
        Route::post('orders/updated',          [WebhookController::class, 'ordersUpdated']);
        Route::post('products/create',         [WebhookController::class, 'productsCreate']);
        Route::post('products/update',         [WebhookController::class, 'productsUpdate']);
        Route::post('inventory_levels/update', [WebhookController::class, 'inventoryLevelsUpdate']);
    });

/*
|--------------------------------------------------------------------------
| Health / Monitoring
|--------------------------------------------------------------------------
*/
Route::get('health', [HealthController::class, 'index']);