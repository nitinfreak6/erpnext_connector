<?php

use Illuminate\Support\Facades\Schedule;

// ── Shopify Sync ──────────────────────────────────────────
Schedule::command('sync:inventory')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->onFailure(fn() => \Illuminate\Support\Facades\Log::error('sync:inventory failed'));

Schedule::command('sync:products')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->onFailure(fn() => \Illuminate\Support\Facades\Log::error('sync:products failed'));

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
