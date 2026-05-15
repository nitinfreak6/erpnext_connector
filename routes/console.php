<?php

use Illuminate\Support\Facades\Schedule;

// ── Shopify Sync ──────────────────────────────────────────
Schedule::command('sync:inventory')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->onFailure(fn() => \Illuminate\Support\Facades\Log::error('sync:inventory failed'));

// routes/console.php

// Cron 1: Fetch — runs at :00 and :30 of every hour
Schedule::command('sync:products')
    ->cron('0,30 * * * *')
    ->withoutOverlapping(10)
    ->runInBackground()
    ->onFailure(fn() => \Illuminate\Support\Facades\Log::error('sync:products failed'));

// Cron 2: Push — runs at :10 and :40 of every hour (10 min after fetch)
Schedule::command('sync:push-products --channel=both')
    ->cron('10,40 * * * *')
    ->withoutOverlapping(15)
    ->runInBackground()
    ->onFailure(fn() => \Illuminate\Support\Facades\Log::error('sync:push-products failed'));

Schedule::command('sync:orders')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground()
    ->onFailure(fn() => \Illuminate\Support\Facades\Log::error('sync:orders failed'));

Schedule::command('sync:customers')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->runInBackground();

// ── Amazon Sync ───────────────────────────────────────────
Schedule::command('sync:amazon-products')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground()
    ->onFailure(fn() => \Illuminate\Support\Facades\Log::error('sync:amazon-products failed'));

Schedule::command('sync:amazon-inventory')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->onFailure(fn() => \Illuminate\Support\Facades\Log::error('sync:amazon-inventory failed'));

Schedule::command('sync:amazon-orders')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->onFailure(fn() => \Illuminate\Support\Facades\Log::error('sync:amazon-orders failed'));

// ── Maintenance ───────────────────────────────────────────
Schedule::command('logs:prune --days=30')->weekly();
