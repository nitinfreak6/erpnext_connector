<?php

/*
|--------------------------------------------------------------------------
| Connector Registry — single source of truth for ERP / Ecom drivers
|--------------------------------------------------------------------------
|
| THIS IS THE ONLY FILE YOU EDIT TO ADD A NEW ERP OR ECOMMERCE PLATFORM.
|
| Everything that used to be scattered across AppServiceProvider, the
| controllers (job maps, channel-type maps), the settings page (credential
| sections) and the webhook layer now reads from here via ConnectorRegistry.
|
| To add a driver:
|   1. Write the adapter implementing ErpInterface or EcomInterface.
|   2. Add ONE block below under 'erp' or 'ecom'.
|   3. Seed its field-config rows (see database/seeders) so mapping has
|      something to translate.
|
| No controller, provider, or route changes are required after that.
|
| Each driver block:
|   adapter        FQCN of the adapter class (bound to the interface).
|   label          Human label shown in the dashboard.
|   credentials    Setting keys this driver needs — used to render the
|                  Settings page section and to validate on save.
|   jobs           Logical job-name => queued Job FQCN. The jobs are
|                  driver-agnostic (they resolve the interface via DI);
|                  this map is the gate that says "this driver is wired".
|   entity_types   Which SyncMapping entity_type rows belong to this
|                  channel — used by the Orders / Inventory / Overview
|                  channel filters.
|   webhooks       (ecom only) verifier + signature header, consumed by
|                  the generic VerifyEcomWebhook middleware.
|
*/

return [

    // Default driver slugs. DB connector_settings (erp_driver / ecom_driver)
    // override these at runtime via SettingsService.
    'default_erp'  => env('ERP_DRIVER', 'odoo'),
    'default_ecom' => env('ECOM_DRIVER', 'shopify'),

    'erp' => [

        'odoo' => [
            'adapter'      => \App\Services\Erp\Odoo\OdooErpAdapter::class,
            'label'        => 'Odoo',
            'icon'         => '🔗',
            'color'        => 'purple',
            'credentials'  => ['odoo_url', 'odoo_db', 'odoo_username', 'odoo_api_key'],
            'jobs'         => [
                // ERP-side push jobs (ecom -> erp) go here as they are added.
            ],
            'entity_types' => [
                'product'   => ['product'],
                'order'     => ['order', 'sales_order'],
                'customer'  => ['customer'],
                'inventory' => ['inventory'],
            ],
        ],

        // Stub adapter — present to prove the binding resolves. NOT functional.
        // Remove from this list (or finish the adapter) before exposing in the UI.
        'sap' => [
            'adapter'      => \App\Services\Erp\Sap\SapErpAdapter::class,
            'label'        => 'SAP',
            'icon'         => '🏢',
            'color'        => 'blue',
            'credentials'  => ['sap_base_url', 'sap_client', 'sap_username', 'sap_password'],
            'jobs'         => [],
            'entity_types' => [
                'product'   => ['product'],
                'order'     => ['order', 'sales_order'],
                'customer'  => ['customer'],
                'inventory' => ['inventory'],
            ],
            'enabled'      => false, // hidden from driver selection until implemented
        ],

        // 'netsuite' => [
        //     'adapter'      => \App\Services\Erp\NetSuite\NetSuiteErpAdapter::class,
        //     'label'        => 'NetSuite',
        //     'credentials'  => ['netsuite_account', 'netsuite_token', 'netsuite_secret'],
        //     'jobs'         => [],
        //     'entity_types' => ['product', 'order', 'sales_order', 'customer', 'inventory'],
        // ],
    ],

    'ecom' => [

        'shopify' => [
            'adapter'      => \App\Services\Ecom\Shopify\ShopifyEcomAdapter::class,
            'label'        => 'Shopify',
            'icon'         => '🛍️',
            'color'        => 'green',
            'credentials'  => ['shopify_store_url', 'shopify_access_token', 'shopify_api_version', 'shopify_webhook_secret'],
            'jobs'         => [
                'push_product'   => \App\Jobs\Ecom\PushProductToEcomJob::class,
                'push_order'     => \App\Jobs\Ecom\PushOrderToEcomJob::class,
                'push_inventory' => \App\Jobs\Ecom\PushInventoryToEcomJob::class,
                'push_customer'  => \App\Jobs\Ecom\PushCustomerToEcomJob::class,
            ],
            'entity_types' => [
                'product'   => ['product'],
                'order'     => ['order', 'sales_order'],
                'customer'  => ['customer'],
                'inventory' => ['inventory'],
            ],
            'webhooks'     => [
                'signature_header' => 'X-Shopify-Hmac-Sha256',
                'id_header'        => 'X-Shopify-Webhook-Id',
            ],
        ],

        // Amazon is a sales channel that lives outside the clean ERP<->Ecom
        // model. Declared here so the channel filters can read it from one
        // place instead of hardcoding 'amazon' in five controllers.
        'amazon' => [
            'adapter'      => null, // not an EcomInterface driver — special channel
            'label'        => 'Amazon',
            'icon'         => '📦',
            'color'        => 'amber',
            'credentials'  => ['amazon_lwa_client_id', 'amazon_lwa_client_secret', 'amazon_refresh_token', 'amazon_marketplace_id'],
            'jobs'         => [
                'push_product'   => \App\Jobs\Amazon\PushProductToAmazonJob::class,
                'push_inventory' => \App\Jobs\Amazon\PushInventoryToAmazonJob::class,
            ],
            'entity_types' => [
                'product'   => ['amazon_product'],
                'order'     => ['amazon_order'],
                'inventory' => ['amazon_inventory'],
            ],
            'is_channel'   => true,  // not selectable as the primary ecom driver
        ],

        // 'woocommerce' => [
        //     'adapter'      => \App\Services\Ecom\WooCommerce\WooCommerceEcomAdapter::class,
        //     'label'        => 'WooCommerce',
        //     'credentials'  => ['woo_store_url', 'woo_consumer_key', 'woo_consumer_secret'],
        //     'jobs'         => ['push_product' => \App\Jobs\Ecom\PushProductToEcomJob::class],
        //     'entity_types' => ['product', 'order', 'customer', 'inventory'],
        //     'webhooks'     => ['signature_header' => 'X-WC-Webhook-Signature'],
        // ],
    ],
];
