<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Dashboard\InventoryController;
use App\Http\Controllers\Dashboard\OrdersController;
use App\Http\Controllers\Dashboard\OverviewController;
use App\Http\Controllers\Dashboard\ProductsController;
use App\Http\Controllers\Dashboard\SettingsController;
use App\Http\Controllers\Dashboard\SyncLogsController;
use App\Http\Controllers\Dashboard\UsersController;
use App\Http\Controllers\Dashboard\WebhooksController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Dashboard\MappingController;
use App\Http\Controllers\Dashboard\ProductCacheController;
use App\Http\Controllers\Dashboard\ProductFieldConfigController;
use App\Http\Controllers\Dashboard\CustomersController;
use App\Http\Controllers\Dashboard\AlertsController;

/*
|--------------------------------------------------------------------------
| Auth
|--------------------------------------------------------------------------
*/
Route::get('/', fn () => redirect()->route('login'));

Route::middleware('guest')->group(function () {
    Route::get('/login',  [LoginController::class, 'showLogin'])->name('login');
    Route::post('/login', [LoginController::class, 'login']);
});

Route::post('/logout', [LoginController::class, 'logout'])->name('logout')->middleware('auth');

/*
|--------------------------------------------------------------------------
| Dashboard (auth required)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth'])->prefix('dashboard')->name('dashboard')->group(function () {

    // Overview
    Route::get('/', [OverviewController::class, 'index'])->name('');

    // ── Products (feature flag: products) ────────────────────────────────
    Route::middleware('feature:products')->prefix('products')->name('.products')->group(function () {
        Route::get('/',                 [ProductsController::class, 'index'])      ->name('');
        Route::get('/{odooId}',         [ProductsController::class, 'show'])       ->name('.show');
        Route::post('/fetch',           [ProductsController::class, 'fetch'])      ->name('.fetch');
        Route::post('/pull',            [ProductsController::class, 'pull'])       ->name('.pull');
        Route::post('/post-all',        [ProductsController::class, 'postAll'])    ->name('.post-all');
        Route::post('/{odooId}/fetch',  [ProductsController::class, 'fetchSingle'])->name('.fetch-single');
		Route::post('/{ecomId}/pull-single', [ProductsController::class, 'pullSingle'])      ->name('.pull-single');
        Route::post('/{ecomId}/push-to-erp', [ProductsController::class, 'pushSingleToErp']) ->name('.push-single-to-erp');
        Route::post('/{odooId}/post',   [ProductsController::class, 'postSingle']) ->name('.post-single');
        Route::patch('/{odooId}/refresh',[ProductsController::class, 'refresh'])   ->name('.refresh');
    });

    // ── Orders (feature flag: orders) ────────────────────────────────────
    Route::middleware('feature:orders')->group(function () {
        Route::get('/orders', [OrdersController::class, 'index'])->name('.orders');
        Route::prefix('orders')->name('.orders')->group(function () {
            Route::post('/fetch',                [OrdersController::class, 'fetch'])         ->name('.fetch');
            Route::post('/pull',                 [OrdersController::class, 'pull'])          ->name('.pull');
            Route::post('/post-sales',           [OrdersController::class, 'postSales'])     ->name('.post-sales');
            Route::post('/post-single/{ecomId}', [OrdersController::class, 'postSingle'])   ->name('.post-single');
            Route::post('/fetch-dispatch',       [OrdersController::class, 'fetchDispatch']) ->name('.fetch-dispatch');
            Route::post('/post-dispatch',        [OrdersController::class, 'postDispatch'])  ->name('.post-dispatch');
            Route::get('/{erpId}/sales-info',    [OrdersController::class, 'salesInfo'])     ->name('.sales-info');
            Route::get('/{erpId}/dispatch-info', [OrdersController::class, 'dispatchInfo'])  ->name('.dispatch-info');
            Route::get('/{erpId}',               [OrdersController::class, 'show'])          ->name('.show');
            Route::post('/{erpId}/push',         [OrdersController::class, 'push'])          ->name('.push');
            Route::post('/{ecomId}/sync-back',   [OrdersController::class, 'syncBack'])      ->name('.sync-back');
			Route::post('/{erpId}/fetch-dispatch',    [OrdersController::class, 'fetchDispatchSingle']) ->name('.fetch-dispatch-single');
            Route::post('/{erpId}/post-dispatch',     [OrdersController::class, 'postDispatchSingle'])  ->name('.post-dispatch-single');
        });
    });

    // ── Inventory (feature flag: inventory) ──────────────────────────────
    Route::middleware('feature:inventory')->group(function () {
        Route::get('/inventory',                     [InventoryController::class, 'index'])           ->name('.inventory');
        Route::post('/inventory/fetch-stock',        [InventoryController::class, 'fetchStock'])      ->name('.inventory.fetch-stock');
        Route::post('/inventory/post-stock',         [InventoryController::class, 'postStock'])       ->name('.inventory.post-stock');
        Route::post('/inventory/{id}/fetch-stock',[InventoryController::class, 'fetchStockSingle'])->name('.inventory.fetch-stock-single');
        Route::post('/inventory/{id}/post-stock', [InventoryController::class, 'postStockSingle']) ->name('.inventory.post-stock-single');
        Route::get('/inventory/{id}/stock-info',  [InventoryController::class, 'stockInfo'])       ->name('.inventory.stock-info');
    });

    // ── Product Cache (no feature flag — always available) ───────────────
    Route::prefix('product-cache')->name('.product-cache')->group(function () {
        Route::get('/',                  [ProductCacheController::class, 'index'])     ->name('.index');
        Route::get('/{odooId}',          [ProductCacheController::class, 'show'])      ->name('.show');
        Route::post('/fetch',            [ProductCacheController::class, 'fetchAll'])  ->name('.fetch');
        Route::post('/{odooId}/refresh', [ProductCacheController::class, 'refresh'])   ->name('.refresh');
        Route::post('/post-ecom',        [ProductCacheController::class, 'postEcom'])  ->name('.post-ecom');
        Route::post('/post-shopify',     [ProductCacheController::class, 'postShopify'])->name('.post-shopify');
        Route::post('/post-amazon',      [ProductCacheController::class, 'postAmazon'])->name('.post-amazon');
        Route::delete('/{odooId}/clear', [ProductCacheController::class, 'clear'])     ->name('.clear');
        Route::delete('/clear-all',      [ProductCacheController::class, 'clearAll'])  ->name('.clear-all');
    });

    // ── Field Config ─────────────────────────────────────────────────────
    Route::prefix('product-field-config')->name('.product-field-config')->middleware('role:manage-settings')->group(function () {
        Route::get('/',                   [ProductFieldConfigController::class, 'index'])          ->name('.index');
        Route::post('/',                  [ProductFieldConfigController::class, 'store'])          ->name('.store');
        Route::put('/{config}',           [ProductFieldConfigController::class, 'update'])         ->name('.update');
        Route::delete('/{config}',        [ProductFieldConfigController::class, 'destroy'])        ->name('.destroy');
        Route::patch('/{config}/toggle',  [ProductFieldConfigController::class, 'toggle'])         ->name('.toggle');
        Route::post('/fetch-ecom-fields', [ProductFieldConfigController::class, 'fetchEcomFields'])->name('.fetch-ecom-fields');
        Route::post('/fetch-erp-fields',  [ProductFieldConfigController::class, 'fetchErpFields']) ->name('.fetch-erp-fields');
    });

    // ── Customers (feature flag: customers) ──────────────────────────────
    Route::middleware('feature:customers')->prefix('customers')->name('.customers')->group(function () {
        Route::get('/',       [CustomersController::class, 'index'])->name('');
        Route::post('/fetch', [CustomersController::class, 'fetch'])->name('.fetch');
        Route::post('/pull',  [CustomersController::class, 'pull'])->name('.pull');
    });

    // ── Logs ─────────────────────────────────────────────────────────────
    Route::get('/logs',       [SyncLogsController::class, 'index'])->name('.logs');
    Route::get('/logs/{log}', [SyncLogsController::class, 'show'])->name('.logs.show');

    // ── Webhooks ─────────────────────────────────────────────────────────
    Route::get('/webhooks', [WebhooksController::class, 'index'])
        ->middleware('role:view-webhooks')
        ->name('.webhooks');

    // ── Sync trigger ─────────────────────────────────────────────────────
    Route::post('/sync/trigger', [SettingsController::class, 'triggerSync'])
        ->middleware('role:trigger-sync')
        ->name('.sync.trigger');

    // ── Settings (admin only) ─────────────────────────────────────────────
    Route::middleware('role:manage-settings')->group(function () {
        Route::get('/settings',                  [SettingsController::class, 'index']) ->name('.settings');
        Route::put('/settings',                  [SettingsController::class, 'update'])->name('.settings.update');
        Route::get('/settings/{setting}/reveal', [SettingsController::class, 'reveal'])->name('.settings.reveal');
    });

    // ── Users (admin only) ───────────────────────────────────────────────
    Route::middleware('role:manage-users')->prefix('users')->name('.users')->group(function () {
        Route::get('/',          [UsersController::class, 'index'])  ->name('.index');
        Route::get('/create',    [UsersController::class, 'create']) ->name('.create');
        Route::post('/',         [UsersController::class, 'store'])  ->name('.store');
        Route::get('/{user}',    [UsersController::class, 'edit'])   ->name('.edit');
        Route::put('/{user}',    [UsersController::class, 'update']) ->name('.update');
        Route::delete('/{user}', [UsersController::class, 'destroy'])->name('.destroy');
    });

    // ── Mappings ─────────────────────────────────────────────────────────
    Route::middleware('role:manage-settings')->prefix('mappings')->name('.mappings')->group(function () {
        Route::get('/{type}',                    [MappingController::class, 'index'])  ->name('.index');
        Route::post('/{type}',                   [MappingController::class, 'store'])  ->name('.store');
        Route::put('/{type}/{mapping}',          [MappingController::class, 'update']) ->name('.update');
        Route::delete('/{type}/{mapping}',       [MappingController::class, 'destroy'])->name('.destroy');
        Route::patch('/{type}/{mapping}/toggle', [MappingController::class, 'toggle']) ->name('.toggle');
        Route::post('/{type}/import',            [MappingController::class, 'import']) ->name('.import');
		Route::post('/{type}/fetch-erp-fields',  [MappingController::class, 'fetchErpFields']) ->name('.fetch-erp-fields');
		Route::post('/{type}/fetch-ecom-fields', [MappingController::class, 'fetchEcomFields'])->name('.fetch-ecom-fields');
    });
	
	 // ── Alerts & Notifications ────────────────────────────────────────────
    Route::prefix('alerts')->name('.alerts')->middleware('role:manage-settings')->group(function () {
        Route::get('/',              [AlertsController::class, 'index'])  ->name('.index');
        Route::get('/create',        [AlertsController::class, 'create']) ->name('.create');
        Route::post('/',             [AlertsController::class, 'store'])  ->name('.store');
        Route::get('/{alert}/edit',  [AlertsController::class, 'edit'])   ->name('.edit');
        Route::put('/{alert}',       [AlertsController::class, 'update']) ->name('.update');
        Route::patch('/{alert}/toggle', [AlertsController::class, 'toggle'])->name('.toggle');
        Route::delete('/',           [AlertsController::class, 'destroy'])->name('.destroy');
    });

});