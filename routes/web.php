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

    // Sync data views (viewer+)
    Route::prefix('products')->name('.products')->group(function () {
		Route::get('/',                          [ProductsController::class, 'index'])      ->name('');
		Route::get('/{odooId}',                  [ProductsController::class, 'show'])       ->name('.show');
	 
		// Fetch all from ERP (Odoo → Shopify direction)
		Route::post('/fetch',                    [ProductsController::class, 'fetch'])      ->name('.fetch');
	 
		// Pull all from Ecom (Shopify → Odoo direction)
		Route::post('/pull',                     [ProductsController::class, 'pull'])       ->name('.pull');
	 
		// Post all cached products to Shopify/Amazon
		Route::post('/post-all',                 [ProductsController::class, 'postAll'])    ->name('.post-all');
	 
		// Fetch single product from ERP
		Route::post('/{odooId}/fetch',           [ProductsController::class, 'fetchSingle'])->name('.fetch-single');
	 
		// Post single product
		Route::post('/{odooId}/post',            [ProductsController::class, 'postSingle']) ->name('.post-single');
	 
		// Refresh (alias for fetch-single — kept for backward compat)
		Route::patch('/{odooId}/refresh',        [ProductsController::class, 'refresh'])    ->name('.refresh');
	});
 
  
	
	Route::get('/orders',    [OrdersController::class, 'index'])->name('.orders');
    Route::prefix('orders')->name('.orders')->group(function () {
        Route::post('/fetch',                    [OrdersController::class, 'fetch'])           ->name('.fetch');
        Route::post('/pull',                     [OrdersController::class, 'pull'])            ->name('.pull');
		Route::post('/post-sales',               [OrdersController::class, 'postSales'])       ->name('.post-sales'); 
		Route::post('/post-single/{ecomId}', [OrdersController::class, 'postSingle'])
    ->name('.post-single'); 
		Route::post('/fetch-dispatch',           [OrdersController::class, 'fetchDispatch'])   ->name('.fetch-dispatch');
        Route::post('/post-dispatch',            [OrdersController::class, 'postDispatch'])    ->name('.post-dispatch');
        Route::get('/{erpId}/sales-info',        [OrdersController::class, 'salesInfo'])       ->name('.sales-info');
        Route::get('/{erpId}/dispatch-info',     [OrdersController::class, 'dispatchInfo'])    ->name('.dispatch-info');
        Route::get('/{erpId}',                   [OrdersController::class, 'show'])            ->name('.show');
        Route::post('/{erpId}/push',             [OrdersController::class, 'push'])            ->name('.push');
        Route::post('/{ecomId}/sync-back',       [OrdersController::class, 'syncBack'])        ->name('.sync-back');
    });
	
    Route::get('/inventory', [InventoryController::class, 'index'])->name('.inventory');
	
	Route::prefix('product-cache')->name('.product-cache')->group(function () {
		Route::get('/',                              [ProductCacheController::class, 'index'])    ->name('.index');
		Route::get('/{odooId}',                      [ProductCacheController::class, 'show'])     ->name('.show');
		Route::post('/fetch',                        [ProductCacheController::class, 'fetchAll']) ->name('.fetch');
		Route::post('/{odooId}/refresh',             [ProductCacheController::class, 'refresh'])  ->name('.refresh');
		Route::post('/post-ecom',    [ProductCacheController::class, 'postEcom'])   ->name('.post-ecom');
		Route::post('/post-shopify', [ProductCacheController::class, 'postShopify'])->name('.post-shopify'); // compat alias
		Route::post('/post-amazon',                  [ProductCacheController::class, 'postAmazon']) ->name('.post-amazon');
		Route::delete('/{odooId}/clear',             [ProductCacheController::class, 'clear'])    ->name('.clear');
		Route::delete('/clear-all',                  [ProductCacheController::class, 'clearAll']) ->name('.clear-all');
	});
	
	Route::prefix('product-field-config')->name('.product-field-config')->middleware('role:manage-settings')->group(function () {
    Route::get('/',                                    [ProductFieldConfigController::class, 'index'])            ->name('.index');
    Route::post('/',                                   [ProductFieldConfigController::class, 'store'])            ->name('.store');
    Route::put('/{config}',                            [ProductFieldConfigController::class, 'update'])           ->name('.update');
    Route::delete('/{config}',                         [ProductFieldConfigController::class, 'destroy'])          ->name('.destroy');
    Route::patch('/{config}/toggle',                   [ProductFieldConfigController::class, 'toggle'])           ->name('.toggle');
    Route::post('/fetch-ecom-fields',   [ProductFieldConfigController::class, 'fetchEcomFields'])->name('.fetch-ecom-fields');
    Route::post('/fetch-erp-fields',    [ProductFieldConfigController::class, 'fetchErpFields']) ->name('.fetch-erp-fields');
});


    // Logs (viewer+)
    Route::get('/logs',        [SyncLogsController::class, 'index'])->name('.logs');
    Route::get('/logs/{log}',  [SyncLogsController::class, 'show'])->name('.logs.show');

    // Webhooks (manager+)
    Route::get('/webhooks', [WebhooksController::class, 'index'])
        ->middleware('role:view-webhooks')
        ->name('.webhooks');

    // Sync trigger (manager+)
    Route::post('/sync/trigger', [SettingsController::class, 'triggerSync'])
        ->middleware('role:trigger-sync')
        ->name('.sync.trigger');

    // Settings (admin only)
    Route::middleware('role:manage-settings')->group(function () {
        Route::get('/settings',          [SettingsController::class, 'index'])->name('.settings');
        Route::put('/settings',          [SettingsController::class, 'update'])->name('.settings.update');
        Route::get('/settings/{setting}/reveal', [SettingsController::class, 'reveal'])->name('.settings.reveal');
    });

    // User management (admin only)
    Route::middleware('role:manage-users')->prefix('users')->name('.users')->group(function () {
        Route::get('/',           [UsersController::class, 'index'])->name('.index');
        Route::get('/create',     [UsersController::class, 'create'])->name('.create');
        Route::post('/',          [UsersController::class, 'store'])->name('.store');
        Route::get('/{user}',     [UsersController::class, 'edit'])->name('.edit');
        Route::put('/{user}',     [UsersController::class, 'update'])->name('.update');
        Route::delete('/{user}',  [UsersController::class, 'destroy'])->name('.destroy');
    });
	
	Route::middleware('role:manage-settings')->prefix('mappings')->name('.mappings')->group(function () {
		Route::get('/{type}',                    [MappingController::class, 'index'])  ->name('.index');
		Route::post('/{type}',                   [MappingController::class, 'store'])  ->name('.store');
		Route::put('/{type}/{mapping}',          [MappingController::class, 'update']) ->name('.update');
		Route::delete('/{type}/{mapping}',       [MappingController::class, 'destroy'])->name('.destroy');
		Route::patch('/{type}/{mapping}/toggle', [MappingController::class, 'toggle']) ->name('.toggle');
		Route::post('/{type}/import',            [MappingController::class, 'import']) ->name('.import');
	});
	
	Route::prefix('customers')->name('.customers')->group(function () {
		Route::get('/',          [CustomersController::class, 'index'])->name('');
		Route::post('/fetch',    [CustomersController::class, 'fetch'])->name('.fetch');
		Route::post('/pull',     [CustomersController::class, 'pull'])->name('.pull');
	});
});