<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Active ERP Driver
    |--------------------------------------------------------------------------
    | The short name of the ERP adapter that the sync core will use.
    | Must match a case in AppServiceProvider::bindErpDriver().
    |
    | Supported: "odoo", "sap", "netsuite", "dynamics"
    */
    'erp_driver' => env('ERP_DRIVER', 'odoo'),

    /*
    |--------------------------------------------------------------------------
    | Sync Behaviour
    |--------------------------------------------------------------------------
    */
    'product_page_size'   => (int) env('SYNC_PRODUCT_PAGE_SIZE', 100),
    'inventory_page_size' => (int) env('SYNC_INVENTORY_PAGE_SIZE', 1000),
    'order_page_size'     => (int) env('SYNC_ORDER_PAGE_SIZE', 200),
    'customer_page_size'  => (int) env('SYNC_CUSTOMER_PAGE_SIZE', 500),

];
