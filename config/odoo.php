<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Odoo Connection
    |--------------------------------------------------------------------------
    */
    'url'      => env('ODOO_URL'),
    'db'       => env('ODOO_DB'),
    'username' => env('ODOO_USERNAME'),
    'api_key'  => env('ODOO_API_KEY'),
    'timeout'  => (int) env('ODOO_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Rate-limit retry delays (seconds)
    |--------------------------------------------------------------------------
    | Comma-separated wait times between successive retries on HTTP 429.
    | The number of values here = number of retries (3 values = 3 retries).
    |
    | Default: wait 10s, then 30s, then 90s, then give up.
    |
    | If your Odoo instance is on a shared cloud plan (Odoo.sh / SaaS),
    | increase these significantly: e.g. ODOO_RETRY_DELAYS=30,90,300
    |
    | Set ODOO_RETRY_DELAYS=0 to disable retries entirely (not recommended).
    */
    'retry_delays' => env('ODOO_RETRY_DELAYS', '10,30,90'),

    /*
    |--------------------------------------------------------------------------
    | Location → Shopify location ID map
    |--------------------------------------------------------------------------
    */
    'location_map' => json_decode(env('ODOO_LOCATION_SHOPIFY_LOCATION_MAP', '{}'), true) ?? [],

];
