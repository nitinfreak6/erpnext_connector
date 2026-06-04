<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AlertNotification extends Model
{
    protected $fillable = [
        'alert_type',
        'status',
        'send_to',
        'cc',
        'bcc',
        'subject',
        'body',
    ];

    // ── Custom notification types — managed via UI ────────────────────────
    const TYPE_SALES_ORDER_CANCELLATION   = 'sales_order_cancellation';
    const TYPE_STOCK_SYNC_ZERO_COST       = 'stock_sync_zero_cost';
    const TYPE_UNABLE_TO_FETCH_STOCK      = 'unable_to_fetch_stock';
    const TYPE_SALES_ORDER_UNDER_DISPATCH = 'sales_order_under_dispatch';

    const STATUS_ACTIVE   = 'active';
    const STATUS_INACTIVE = 'inactive';

    /** Labels shown in the Add/Edit dropdown */
    public static function typeLabels(): array
    {
        return [
            self::TYPE_SALES_ORDER_CANCELLATION   => 'Sales Order Cancellation Alert',
            self::TYPE_STOCK_SYNC_ZERO_COST        => 'Stock Sync Zero Cost Adjustment Report',
            self::TYPE_UNABLE_TO_FETCH_STOCK       => 'Unable to Fetch Stock Data',
            self::TYPE_SALES_ORDER_UNDER_DISPATCH  => 'Sales Order Under-Dispatch Alert',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** Replace {body} placeholder with the generated rows HTML */
    public function buildBody(string $rowsHtml): string
    {
        return str_replace('{body}', $rowsHtml, $this->body);
    }
}