<?php

namespace App\Providers;

use App\Services\Erp\ErpInterface;
use App\Services\Erp\Odoo\OdooErpAdapter;
use App\Services\SettingsService;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind ErpInterface to the active adapter.
        // Driver is read from connector_settings (DB) first, then .env fallback.
        $this->app->bind(ErpInterface::class, function ($app) {
            try {
                $driver = $app->make(SettingsService::class)->erpDriver();
            } catch (\Throwable) {
                $driver = config('sync.erp_driver', env('ERP_DRIVER', 'odoo'));
            }

            return match ($driver) {
                'odoo'     => $app->make(OdooErpAdapter::class),
                // 'sap'      => $app->make(\App\Services\Erp\Sap\SapErpAdapter::class),
                // 'netsuite' => $app->make(\App\Services\Erp\NetSuite\NetSuiteErpAdapter::class),
                default    => throw new \InvalidArgumentException("Unknown ERP driver [{$driver}]"),
            };
        });
    }

    public function boot(): void
    {
        // Share app identity values with every view so templates can use
        // $appName and $erpDisplayName without injecting SettingsService manually.
        //
        // Usage in any blade:
        //   {{ $appName }}           → "My Connector" (from Global Settings)
        //   {{ $erpDisplayName }}    → "Odoo" / "SAP" / whatever is set
        //
        // Wrapped in try/catch so asset compilation and fresh installs
        // (before migrations run) don't break.
        View::composer('*', function ($view) {
            try {
                $settings = app(SettingsService::class);
                $view->with('appName',        $settings->appName());
                $view->with('erpDisplayName', $settings->erpDisplayName());
            } catch (\Throwable) {
                $view->with('appName',        config('app.name', 'Connector'));
                $view->with('erpDisplayName', 'Odoo');
            }
        });
    }
}