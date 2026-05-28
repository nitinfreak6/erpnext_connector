<?php

use App\Services\SettingsService;
use Illuminate\Support\Facades\Schedule;

// ── Helper: resolve SettingsService safely ────────────────────────────────────
// app() may not be fully booted when the schedule is first registered during
// tests, so we wrap each skip() call in a closure that resolves lazily.
$productEnabled  = fn () => app(SettingsService::class)->isProductSyncEnabled();
$inventoryEnabled = fn () => app(SettingsService::class)->isInventorySyncEnabled();
$ordersEnabled   = fn () => app(SettingsService::class)->isOrderSyncEnabled();
$customersEnabled = fn () => app(SettingsService::class)->isCustomerSyncEnabled();

// ── Shopify / ERP Sync ────────────────────────────────────────────────────────

Schedule::command('sync:inventory')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->skip(fn () => ! $inventoryEnabled())           // ← respects toggle
    ->onFailure(fn () => \Illuminate\Support\Facades\Log::error('sync:inventory failed'));

// Products sync - dispatches jobs based on mode (erp_to_ecom, ecom_to_erp, bidirectional)
Schedule::command('sync:products')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->skip(fn () => ! $productEnabled())             // ← respects toggle
    ->onFailure(fn () => \Illuminate\Support\Facades\Log::error('sync:products failed'));

// Push products (ERP → Ecom only) - ONLY runs if mode allows pushing TO ecom
$productMode = fn() => app(SettingsService::class)->productSyncMode();
Schedule::command('sync:push-products --channel=both')
    ->everyTenMinutes()
    ->withoutOverlapping(15)
    ->runInBackground()
    ->skip(fn () => ! $productEnabled() || $productMode() === 'ecom_to_erp')  // Skip if pulling FROM ecom
    ->onFailure(fn () => \Illuminate\Support\Facades\Log::error('sync:push-products failed'));

// Orders sync - respects bidirectional mode
// Schedule::command('sync:orders')
    // ->everyFiveMinutes()  // Orders are time-sensitive
    // ->withoutOverlapping()
    // ->runInBackground()
    // ->skip(fn () => ! $ordersEnabled())
    // ->onFailure(fn () => \Illuminate\Support\Facades\Log::error('sync:orders failed'));

//Customers sync - respects bidirectional mode  
Schedule::command('sync:customers')
    ->everyFifteenMinutes()  // Changed from daily to 15 minutes
    ->withoutOverlapping()
    ->runInBackground()
    ->skip(fn () => ! $customersEnabled())
    ->onFailure(fn () => \Illuminate\Support\Facades\Log::error('sync:customers failed'));

// ── Amazon Sync ───────────────────────────────────────────────────────────────

// $amazonEnabled = fn () => app(SettingsService::class)->isAmazonChannelEnabled();

// Schedule::command('sync:amazon-products')
    // ->hourly()
    // ->withoutOverlapping()
    // ->runInBackground()
    // ->skip(fn () => ! $amazonEnabled() || ! $productEnabled())
    // ->onFailure(fn () => \Illuminate\Support\Facades\Log::error('sync:amazon-products failed'));

// Schedule::command('sync:amazon-inventory')
    // ->everyFifteenMinutes()
    // ->withoutOverlapping()
    // ->runInBackground()
    // ->skip(fn () => ! $amazonEnabled() || ! $inventoryEnabled())
    // ->onFailure(fn () => \Illuminate\Support\Facades\Log::error('sync:amazon-inventory failed'));

// Schedule::command('sync:amazon-orders')
    // ->everyFifteenMinutes()
    // ->withoutOverlapping()
    // ->runInBackground()
    // ->skip(fn () => ! $amazonEnabled() || ! $ordersEnabled())
    // ->onFailure(fn () => \Illuminate\Support\Facades\Log::error('sync:amazon-orders failed'));

// ── Maintenance ───────────────────────────────────────────────────────────────

Schedule::command('logs:prune --days=30')->weekly();