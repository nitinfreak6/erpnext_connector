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

// Cron 1: Fetch — runs at :00 and :30 of every hour
Schedule::command('sync:products')
    ->cron('0,30 * * * *')
    ->withoutOverlapping(10)
    ->runInBackground()
    ->skip(fn () => ! $productEnabled())             // ← respects toggle
    ->onFailure(fn () => \Illuminate\Support\Facades\Log::error('sync:products failed'));

// Cron 2: Push — runs at :10 and :40 of every hour (10 min after fetch)
Schedule::command('sync:push-products --channel=both')
    ->cron('10,40 * * * *')
    ->withoutOverlapping(15)
    ->runInBackground()
    ->skip(fn () => ! $productEnabled())             // ← respects toggle
    ->onFailure(fn () => \Illuminate\Support\Facades\Log::error('sync:push-products failed'));

Schedule::command('sync:orders')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground()
    ->skip(fn () => ! $ordersEnabled())              // ← respects toggle
    ->onFailure(fn () => \Illuminate\Support\Facades\Log::error('sync:orders failed'));

Schedule::command('sync:customers')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->skip(fn () => ! $customersEnabled());          // ← respects toggle

// ── Amazon Sync ───────────────────────────────────────────────────────────────

$amazonEnabled = fn () => app(SettingsService::class)->isAmazonChannelEnabled();

Schedule::command('sync:amazon-products')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground()
    ->skip(fn () => ! $amazonEnabled() || ! $productEnabled())
    ->onFailure(fn () => \Illuminate\Support\Facades\Log::error('sync:amazon-products failed'));

Schedule::command('sync:amazon-inventory')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->skip(fn () => ! $amazonEnabled() || ! $inventoryEnabled())
    ->onFailure(fn () => \Illuminate\Support\Facades\Log::error('sync:amazon-inventory failed'));

Schedule::command('sync:amazon-orders')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->skip(fn () => ! $amazonEnabled() || ! $ordersEnabled())
    ->onFailure(fn () => \Illuminate\Support\Facades\Log::error('sync:amazon-orders failed'));

// ── Maintenance ───────────────────────────────────────────────────────────────

Schedule::command('logs:prune --days=30')->weekly();