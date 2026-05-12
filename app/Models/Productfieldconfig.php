<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductFieldConfig extends Model
{
    protected $table = 'product_field_configs';

    protected $fillable = [
        'channel',
        'shopify_field',
        'shopify_field_label',
        'field_type',
        'odoo_field',
        'odoo_field_label',
        'scope',
        'default_value',
        'transform',
        'min_length',
        'max_length',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'min_length' => 'integer',
        'max_length' => 'integer',
        'sort_order' => 'integer',
    ];

    // Available transform options (shown in UI)
    public static function transformOptions(): array
    {
        return [
            ''                     => 'None',
            'number_format'        => 'Number Format (e.g. 500.00)',
            'number_format_nullable' => 'Number Format or Null if 0',
            'boolean_status'       => 'Boolean → active/draft',
            'array_second'         => 'Array Second Value (e.g. [id, name] → name)',
            'base64_image'         => 'Base64 Image → Shopify images array',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForChannel($query, string $channel)
    {
        return $query->where('channel', $channel);
    }

    public function scopeTemplateLevel($query)
    {
        return $query->where('scope', 'template');
    }

    public function scopeVariantLevel($query)
    {
        return $query->where('scope', 'variant');
    }

    // ── Auto-clear payload cache on ANY change ───────────────────────────
    // This fires on save/update/delete regardless of which controller triggered it.

    protected static function booted(): void
    {
        $clear = function () {
            \Illuminate\Support\Facades\Cache::forget('product_field_configs_shopify');
        };

        static::saved($clear);    // create + update
        static::deleted($clear);  // delete
    }
}