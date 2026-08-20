<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class ChannelMapping extends Model
{
    protected $fillable = [
        'type',
        'channel',
        'odoo_id',
        'odoo_label',
        'external_id',
        'external_label',
        'meta',
        'is_active',
    ];

    protected $casts = [
        'meta'      => 'array',
        'is_active' => 'boolean',
    ];

    // ── Type constants ──────────────────────────────────────────────────
    const TYPE_WAREHOUSE        = 'warehouse';
    const TYPE_SHIPPING         = 'shipping';
    const TYPE_CATEGORY         = 'category';
    const TYPE_PRICELIST        = 'pricelist';
    const TYPE_PAYMENT          = 'payment';
    const TYPE_CHANNEL          = 'channel';
    const TYPE_SALES_ORDER_TYPE = 'sales_order_type';
    const TYPE_SALES_REP        = 'sales_rep';
    const TYPE_PRODUCT_SIZE     = 'product_size';
    const TYPE_TAX              = 'tax';

    // ── Channel constants ───────────────────────────────────────────────
    const CHANNEL_SHOPIFY = 'shopify';
    const CHANNEL_AMAZON  = 'amazon';
    const CHANNEL_BOTH    = 'both';

    // ── Human-readable labels for the UI ───────────────────────────────
    public static function typeLabels(): array
    {
        return [
            self::TYPE_WAREHOUSE        => 'Warehouse Mapping',
            self::TYPE_SHIPPING         => 'Shipping Mapping',
            self::TYPE_CATEGORY         => 'Category / Department Mapping',
            self::TYPE_PRICELIST        => 'Pricelist Mapping',
            self::TYPE_PAYMENT          => 'Payment Mapping',
            self::TYPE_CHANNEL          => 'Channel Mapping',
            self::TYPE_SALES_ORDER_TYPE => 'Sales Order Type Mapping',
            self::TYPE_SALES_REP        => 'Sales Rep Mapping',
            self::TYPE_PRODUCT_SIZE     => 'Product Size List Mapping',
            self::TYPE_TAX              => 'Tax Mapping',
		    'product_field' => 'Product Field Mapping (Shopify)',
        ];
    }

    public static function typeIcons(): array
    {
        return [
            self::TYPE_WAREHOUSE        => 'M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z',
            self::TYPE_SHIPPING         => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
            self::TYPE_CATEGORY         => 'M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10',
            self::TYPE_PRICELIST        => 'M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z',
            self::TYPE_PAYMENT          => 'M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z',
            self::TYPE_CHANNEL          => 'M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z',
            self::TYPE_SALES_ORDER_TYPE => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2',
            self::TYPE_SALES_REP        => 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z',
            self::TYPE_PRODUCT_SIZE     => 'M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4',
            self::TYPE_TAX              => 'M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z',
			'product_field' => '🔗',
        ];
    }

    // ── Scopes ──────────────────────────────────────────────────────────
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeForChannel(Builder $query, string $channel): Builder
    {
        return $query->where(function ($q) use ($channel) {
            $q->where('channel', $channel)->orWhere('channel', self::CHANNEL_BOTH);
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /**
     * Get active mappings as odoo_id => external_id map for a given type/channel.
     */
    public static function asMap(string $type, string $channel): array
    {
        return static::ofType($type)
            ->forChannel($channel)
            ->active()
            ->pluck('external_id', 'odoo_id')
            ->toArray();
    }

    /** ERP-side ID column in channel_mappings (odoo_id until renamed). */
    public static function erpIdColumn(): string
    {
        return 'odoo_id';
    }
}