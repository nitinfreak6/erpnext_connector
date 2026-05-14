<?php

namespace App\Providers;

use App\Services\Erp\ErpInterface;
use App\Services\Erp\Odoo\OdooErpAdapter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind ErpInterface to the active adapter.
        // Driver is read from connector_settings (DB) first, then .env fallback.
        // Change ERP driver from the Global Settings UI — no .env edit needed.
        $this->app->bind(ErpInterface::class, function ($app) {
            // Use SettingsService to read from DB — supports runtime switching
            try {
                $driver = $app->make(\App\Services\SettingsService::class)->erpDriver();
            } catch (\Throwable) {
                // DB not ready yet (fresh install, migrations not run)
                $driver = config('sync.erp_driver', env('ERP_DRIVER', 'odoo'));
            }

            return match ($driver) {
                'odoo'     => $app->make(OdooErpAdapter::class),
                // 'sap'   => $app->make(\App\Services\Erp\Sap\SapErpAdapter::class),
                // 'netsuite' => $app->make(\App\Services\Erp\NetSuite\NetSuiteErpAdapter::class),
                default    => throw new \InvalidArgumentException("Unknown ERP driver [{$driver}]"),
            };
        });
    }

    public function boot(): void
    {
        //
    }
}