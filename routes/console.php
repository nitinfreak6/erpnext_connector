<?php

use App\Services\SettingsService;
use Illuminate\Support\Facades\Schedule;

// ── Lazy settings helpers ─────────────────────────────────────────────────────
// Resolved lazily so the schedule bootstrap doesn't fail before migrations run.

$isEnabled = fn(string $method) => function () use ($method) {
    try {
        return app(SettingsService::class)->{$method}();
    } catch (\Throwable) {
        return false;
    }
};

$productEnabled   = $isEnabled('isProductSyncEnabled');
$inventoryEnabled = $isEnabled('isInventorySyncEnabled');
$ordersEnabled    = $isEnabled('isSalesOrderSyncEnabled');
$customersEnabled = $isEnabled('isCustomerSyncEnabled');
$amazonEnabled    = fn() => app(SettingsService::class)->isAmazonChannelEnabled()
                         && app(SettingsService::class)->isProductSyncEnabled();

// ── Products ──────────────────────────────────────────────────────────────────
// Incremental: only new/updated products fetched. Cursor advances each run.
Schedule::command('sync:products')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->skip(fn() => !$productEnabled())
    ->onFailure(fn() => \Illuminate\Support\Facades\Log::error('Cron failed: sync:products'));

// ── Inventory ─────────────────────────────────────────────────────────────────
// Incremental: write_date cursor ensures only changed stock quants are fetched.
Schedule::command('sync:inventory')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->skip(fn() => !$inventoryEnabled())
    ->onFailure(fn() => \Illuminate\Support\Facades\Log::error('Cron failed: sync:inventory'));

// ── Orders ────────────────────────────────────────────────────────────────────
// Incremental: last_ecom_write_date / last_erp_write_date cursors.
// Orders are time-sensitive — run every 5 minutes.
Schedule::command('sync:orders')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->skip(fn() => !$ordersEnabled())
    ->onFailure(fn() => \Illuminate\Support\Facades\Log::error('Cron failed: sync:orders'));

// ── Customers ─────────────────────────────────────────────────────────────────
// Incremental: last_ecom_write_date cursor. Less frequent than products/orders.
Schedule::command('sync:customers')
    ->everyFifteenMinutes()
    ->withoutOverlapping(20)
    ->runInBackground()
    ->skip(fn() => !$customersEnabled())
    ->onFailure(fn() => \Illuminate\Support\Facades\Log::error('Cron failed: sync:customers'));

// ── Amazon — Products ─────────────────────────────────────────────────────────
// Runs after products are fetched. Uses same ERP cursor.
Schedule::command('sync:amazon-products')
    ->hourly()
    ->withoutOverlapping(30)
    ->runInBackground()
    ->skip(fn() => !$amazonEnabled())
    ->onFailure(fn() => \Illuminate\Support\Facades\Log::error('Cron failed: sync:amazon-products'));

// ── Amazon — Inventory ────────────────────────────────────────────────────────
Schedule::command('sync:amazon-inventory')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->skip(fn() => !$amazonEnabled())
    ->onFailure(fn() => \Illuminate\Support\Facades\Log::error('Cron failed: sync:amazon-inventory'));

// ── Amazon — Orders ───────────────────────────────────────────────────────────
Schedule::command('sync:amazon-orders')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->skip(fn() => !$ordersEnabled() || !app(SettingsService::class)->isAmazonChannelEnabled())
    ->onFailure(fn() => \Illuminate\Support\Facades\Log::error('Cron failed: sync:amazon-orders'));

// ── Maintenance ───────────────────────────────────────────────────────────────
Schedule::command('logs:prune --days=30')
    ->weekly()
    ->runInBackground();
	
	// Runs every hour: checks for items pending > 8 hours and PHP errors in the last hour.
Schedule::command('alerts:send-pending')
    ->hourly()
    ->runInBackground()
    ->onFailure(fn() => \Illuminate\Support\Facades\Log::error('Cron failed: alerts:send-pending'));
