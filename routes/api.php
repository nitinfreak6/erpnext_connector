<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\WebhookController;
use App\Http\Middleware\VerifyEcomWebhook;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Ecommerce Webhook Routes
|--------------------------------------------------------------------------
| Signature is verified in VerifyEcomWebhook, which delegates to the active
| ecom driver's adapter (EcomInterface::verifyWebhook). The {driver} prefix
| keeps the URL namespaced per platform.
| Always return 200 fast — heavy work is dispatched to the queue.
|
| NOTE: WebhookController still dispatches Shopify-shaped inbound jobs
| (ProcessShopify*Job) and reads X-Shopify-* headers. Generalising the
| inbound handler/jobs per driver is the remaining step — see notes.
*/
Route::prefix('webhooks/{driver}')
    ->middleware(VerifyEcomWebhook::class)
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