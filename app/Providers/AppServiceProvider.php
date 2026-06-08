<?php

namespace App\Providers;

use App\Services\ConnectorRegistry;
use App\Services\Ecom\EcomInterface;
use App\Services\Erp\ErpInterface;
use App\Services\SettingsService;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Driver maps now live in config/connectors.php and are read through
        // ConnectorRegistry. To add an ERP/Ecom you edit that one config file —
        // no change here. See config/connectors.php for the contract.
        $this->app->singleton(ConnectorRegistry::class);

        // ── ERP driver binding ──────────────────────────────────────────
        $this->app->bind(ErpInterface::class, function ($app) {
            $registry = $app->make(ConnectorRegistry::class);

            try {
                $driver = $app->make(SettingsService::class)->erpDriver();
            } catch (\Throwable) {
                $driver = config('connectors.default_erp', env('ERP_DRIVER', 'odoo'));
            }

            $adapter = $registry->adapterClass($driver);

            if (!$adapter) {
                throw new \InvalidArgumentException(
                    "ERP driver [{$driver}] is not registered. Add it to config/connectors.php."
                );
            }

            return $app->make($adapter);
        });

        // ── Ecom driver binding ─────────────────────────────────────────
        $this->app->bind(EcomInterface::class, function ($app) {
            $registry = $app->make(ConnectorRegistry::class);

            try {
                $driver = $app->make(SettingsService::class)->ecomDriver();
            } catch (\Throwable) {
                $driver = config('connectors.default_ecom', env('ECOM_DRIVER', 'shopify'));
            }

            $adapter = $registry->adapterClass($driver);

            if (!$adapter) {
                throw new \InvalidArgumentException(
                    "Ecom driver [{$driver}] is not registered. Add it to config/connectors.php."
                );
            }

            return $app->make($adapter);
        });
    }

    public function boot(): void
    {
        // Share app identity with every view.
        // FIX #5: fallback labels are generic ('ERP', 'Ecommerce') — not 'Odoo'/'Shopify'.
        // FIX #2: amazonDisplayName removed from shared vars — not needed globally.
        View::composer('*', function ($view) {
            try {
                $settings = app(SettingsService::class);
                $view->with('appName',                $settings->appName());
                $view->with('erpDisplayName',         $settings->erpDisplayName());
                $view->with('ecomDisplayName',        $settings->ecomDisplayName());
                // Feature flags — used by sidebar to show/hide sections
                $view->with('featureProducts',        $settings->isProductSyncEnabled());
                $view->with('featureOrders',          $settings->isSalesOrderSyncEnabled());
                $view->with('featureInventory',       $settings->isInventorySyncEnabled());
                $view->with('featureCustomers',       $settings->isCustomerSyncEnabled());
            } catch (\Throwable) {
                $view->with('appName',                config('app.name', 'Connector'));
                $view->with('erpDisplayName',         'ERP');
                $view->with('ecomDisplayName',        'Ecommerce');
                $view->with('featureProducts',        true);
                $view->with('featureOrders',          true);
                $view->with('featureInventory',       true);
                $view->with('featureCustomers',       true);
            }
        });
    }
}