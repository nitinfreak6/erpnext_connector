<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductFieldConfig extends Model
{
    // Note: This model is named ProductFieldConfig for backwards compatibility
    // but actually handles ALL entity types (products, orders, customers, etc.)
    // Consider renaming to FieldConfig in future
    
    protected $table = 'product_field_configs';

    protected $fillable = [
        'entity_type',          // product, sales_order, customer, inventory, dispatch
        'ecom_driver',          // shopify, woocommerce, magento
        'ecom_field',           // title, regular_price, order_number
        'ecom_field_label',
        'ecom_api_path',        // GraphQL: productCreate.product.title, REST: name
        'ecom_cast',            // GraphQL: String!, REST: null
        'field_type',           // default, custom, combine
        'erp_driver',           // odoo, netsuite, sap
        'erp_field',            // name, list_price, so_number
        'erp_field_label',
        'scope',                // template, variant, header, line, default
        'default_value',
        'transform',            // ERP → Ecom transform
        'reverse_transform',    // Ecom → ERP transform
        'min_length',
        'max_length',
        'is_active',
        'sort_order',
        
        // DEPRECATED (backwards compatibility)
        'odoo_field',
        'odoo_field_label',
        'odoo_field_2',
        'combine_separator',
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

    // Available REVERSE transform options (Shopify → Odoo)
    public static function reverseTransformOptions(): array
    {
        return [
            ''                      => 'None',
            'strip_tags'            => 'Strip HTML Tags',
            'parse_float'           => 'Parse as Float',
            'parse_float_nullable'  => 'Parse as Float (nullable)',
            'status_to_boolean'     => 'Status String → Boolean',
            'pass_through'          => 'Pass Through (no transform)',
            'skip'                  => 'Skip (don\'t sync this field)',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForDriverPair($query, string $ecomDriver, string $erpDriver)
    {
        return $query->where('ecom_driver', $ecomDriver)
                    ->where('erp_driver', $erpDriver);
    }

    public function scopeForEntity($query, string $entityType)
    {
        return $query->where('entity_type', $entityType);
    }

    public function scopeTemplateLevel($query)
    {
        return $query->where('scope', 'template');
    }

    public function scopeVariantLevel($query)
    {
        return $query->where('scope', 'variant');
    }
    
    // Relationships
    public function entityDefinition()
    {
        return $this->belongsTo(EntityDefinition::class, 'entity_type', 'entity_type');
    }

    // ── Auto-clear payload cache on ANY change ───────────────────────────
    // This fires on save/update/delete regardless of which controller triggered it.

    protected static function booted(): void
    {
        $clear = function () {
            // Clear all driver pair caches
            \Illuminate\Support\Facades\Cache::flush(); // Or use tags if available
            
            // Legacy cache key
            \Illuminate\Support\Facades\Cache::forget('product_field_configs_shopify');
        };

        static::saved($clear);    // create + update
        static::deleted($clear);  // delete
    }
    
    /**
     * Get cache key for specific driver pair
     */
    public static function cacheKey(string $entityType, string $ecomDriver, string $erpDriver): string
    {
        return "field_configs_{$entityType}_{$ecomDriver}_{$erpDriver}";
    }
}