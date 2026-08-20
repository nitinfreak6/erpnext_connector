<?php

use App\Services\SettingsService;
use Illuminate\Support\Facades\Schedule;

// ── Lazy settings helpers ─────────────────────────────────────────────────────

$isEnabled = fn (string $method) => function () use ($method) {
    try {
        return app(SettingsService::class)->{$method}();
    } catch (\Throwable) {
        return false;
    }
};

$anySyncEnabled = function () {
    try {
        $s = app(SettingsService::class);

        return $s->isProductSyncEnabled()
            || $s->isInventorySyncEnabled()
            || $s->isCustomerSyncEnabled()
            || $s->isSalesOrderSyncEnabled();
    } catch (\Throwable) {
        return false;
    }
};

$amazonEnabled = fn () => app(SettingsService::class)->isAmazonChannelEnabled()
    && app(SettingsService::class)->isProductSyncEnabled();

// ── Full sync pipeline (UI-equivalent: fetch + post per entity) ───────────────
// Order: products → inventory → customers → orders → dispatch
// Respects *_sync_enabled toggles and *_sync_mode direction in Global Settings.
Schedule::command('sync:all')
    ->everyFiveMinutes()
    ->withoutOverlapping(30)
    ->runInBackground()
    ->skip(fn () => !$anySyncEnabled())
    ->onFailure(fn () => \Illuminate\Support\Facades\Log::error('Cron failed: sync:all'));

// ── Amazon (separate channel — unchanged) ─────────────────────────────────────
Schedule::command('sync:amazon-products')
    ->hourly()
    ->withoutOverlapping(30)
    ->runInBackground()
    ->skip(fn () => !$amazonEnabled())
    ->onFailure(fn () => \Illuminate\Support\Facades\Log::error('Cron failed: sync:amazon-products'));

Schedule::command('sync:amazon-inventory')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->skip(fn () => !$amazonEnabled())
    ->onFailure(fn () => \Illuminate\Support\Facades\Log::error('Cron failed: sync:amazon-inventory'));

Schedule::command('sync:amazon-orders')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->skip(fn () => !app(SettingsService::class)->isSalesOrderSyncEnabled()
        || !app(SettingsService::class)->isAmazonChannelEnabled())
    ->onFailure(fn () => \Illuminate\Support\Facades\Log::error('Cron failed: sync:amazon-orders'));

// ── Maintenance ───────────────────────────────────────────────────────────────
Schedule::command('logs:prune --days=30')
    ->weekly()
    ->runInBackground();

Schedule::command('alerts:send-pending')
    ->hourly()
    ->runInBackground()
    ->onFailure(fn () => \Illuminate\Support\Facades\Log::error('Cron failed: alerts:send-pending'));
