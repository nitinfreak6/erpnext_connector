<?php

namespace App\Providers;

use App\Services\Ecom\EcomInterface;
use App\Services\Ecom\Shopify\ShopifyEcomAdapter;
use App\Services\Erp\ErpInterface;
use App\Services\Erp\Odoo\OdooErpAdapter;
use App\Services\Erp\Sap\SapErpAdapter;
use App\Services\SettingsService;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // ── ERP driver binding ──────────────────────────────────────────
        // Driver slug is read from connector_settings (DB) first, then .env fallback.
        // To add a new ERP: add one 'driver_slug' => AdapterClass::class line below.
        $this->app->bind(ErpInterface::class, function ($app) {
            try {
                $driver = $app->make(SettingsService::class)->erpDriver();
            } catch (\Throwable) {
                $driver = config('sync.erp_driver', env('ERP_DRIVER', 'odoo'));
            }

            // FIX #24: SAP binding uncommented. Add new ERP adapters here as needed.
            $map = [
                'odoo' => OdooErpAdapter::class,
                'sap'  => SapErpAdapter::class,
                // 'netsuite' => \App\Services\Erp\NetSuite\NetSuiteErpAdapter::class,
            ];

            if (!isset($map[$driver])) {
                throw new \InvalidArgumentException(
                    "ERP driver [{$driver}] is not registered. Add it to AppServiceProvider::\$erpMap."
                );
            }

            return $app->make($map[$driver]);
        });

        // ── Ecom driver binding ─────────────────────────────────────────
        // To add a new ecom platform: add one line to the map below.
        $this->app->bind(EcomInterface::class, function ($app) {
            try {
                $driver = $app->make(SettingsService::class)->ecomDriver();
            } catch (\Throwable) {
                $driver = config('sync.ecom_driver', env('ECOM_DRIVER', 'shopify'));
            }

            $map = [
                'shopify'     => ShopifyEcomAdapter::class,
                // 'woocommerce' => \App\Services\Ecom\WooCommerce\WooCommerceEcomAdapter::class,
                // 'magento'     => \App\Services\Ecom\Magento\MagentoEcomAdapter::class,
            ];

            if (!isset($map[$driver])) {
                throw new \InvalidArgumentException(
                    "Ecom driver [{$driver}] is not registered. Add it to AppServiceProvider::\$ecomMap."
                );
            }

            return $app->make($map[$driver]);
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