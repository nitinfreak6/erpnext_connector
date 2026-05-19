<?php

namespace App\Providers;

use App\Services\Ecom\EcomInterface;
use App\Services\Ecom\Shopify\ShopifyEcomAdapter;
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

        // Bind EcomInterface to the active e-commerce adapter.
        // Driver is read from connector_settings (DB) first, then .env fallback.
        $this->app->bind(EcomInterface::class, function ($app) {
            try {
                $driver = $app->make(SettingsService::class)->ecomDriver();
            } catch (\Throwable) {
                $driver = config('sync.ecom_driver', env('ECOM_DRIVER', 'shopify'));
            }

            return match ($driver) {
                'shopify'     => $app->make(ShopifyEcomAdapter::class),
                // 'woocommerce' => $app->make(\App\Services\Ecom\WooCommerce\WooCommerceEcomAdapter::class),
                // 'magento'     => $app->make(\App\Services\Ecom\Magento\MagentoEcomAdapter::class),
                default       => throw new \InvalidArgumentException("Unknown ecommerce driver [{$driver}]"),
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
				$view->with('appName',             $settings->appName());
				$view->with('erpDisplayName',      $settings->erpDisplayName());
				$view->with('ecomDisplayName',  $settings->ecomDisplayName()); // ← add
				$view->with('amazonDisplayName',   $settings->amazonDisplayName());  // ← add
			} catch (\Throwable) {
				$view->with('appName',             config('app.name', 'Connector'));
				$view->with('erpDisplayName',      'Odoo');
				$view->with('ecomDisplayName',  'Shopify');                        // ← add
				$view->with('amazonDisplayName',   'Amazon');                         // ← add
			}
		});
    }
}