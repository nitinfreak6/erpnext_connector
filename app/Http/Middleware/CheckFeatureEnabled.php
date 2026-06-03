<?php

namespace App\Http\Middleware;

use App\Services\SettingsService;
use Closure;
use Illuminate\Http\Request;

class CheckFeatureEnabled
{
    public function __construct(private readonly SettingsService $settings) {}

    public function handle(Request $request, Closure $next, string $feature): mixed
    {
        $enabled = match ($feature) {
            'products'  => $this->settings->isProductSyncEnabled(),
            'orders'    => $this->settings->isSalesOrderSyncEnabled(),
            'inventory' => $this->settings->isInventorySyncEnabled(),
            'customers' => $this->settings->isCustomerSyncEnabled(),
            default     => true,
        };

        if (!$enabled) {
            if ($request->expectsJson()) {
                return response()->json(['error' => ucfirst($feature) . ' sync is disabled in settings.'], 403);
            }
            return redirect()->route('dashboard')
                ->with('error', ucfirst($feature) . ' sync is disabled in Global Settings.');
        }

        return $next($request);
    }
}