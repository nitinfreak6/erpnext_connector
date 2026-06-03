<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncMapping extends Model
{
    protected $fillable = [
        'entity_type',
        'ecom_driver',
        'erp_id',
        'ecom_id',
        'ecom_secondary_id',
        'erp_reference',
        'ecom_handle',
        'metadata',
        'last_synced_at',
        'erp_updated_at',
        'ecom_updated_at',
        'last_sync_direction',
		'ecom_status',
        'odoo_id',
        'shopify_id',
        'shopify_secondary_id',
        'odoo_reference',
        'shopify_handle',
    ];

    protected $casts = [
        'metadata'       => 'array',
        'last_synced_at' => 'datetime',
        'erp_updated_at' => 'datetime',
        'ecom_updated_at' => 'datetime',
    ];

    // Entity type constants
    const TYPE_PRODUCT          = 'product';
    const TYPE_PRODUCT_VARIANT  = 'product_variant';
    const TYPE_CUSTOMER         = 'customer';
    const TYPE_ORDER            = 'order';
    const TYPE_INVENTORY_ITEM   = 'inventory_item';

    public function scopeOfType($query, string $type)
    {
        return $query->where('entity_type', $type);
    }

    // ══════════════════════════════════════════════════════════════════════
    // BACKWARDS COMPATIBILITY ACCESSORS & MUTATORS
    // ══════════════════════════════════════════════════════════════════════
    
    /**
     * Backwards compatibility: shopify_id → ecom_id (GET)
     */
    public function getShopifyIdAttribute()
    {
        return $this->attributes['ecom_id'] ?? null;
    }

    /**
     * Backwards compatibility: shopify_id → ecom_id (SET)
     */
    public function setShopifyIdAttribute($value)
    {
        $this->attributes['ecom_id'] = $value;
    }

    /**
     * Backwards compatibility: odoo_id → erp_id (GET)
     */
    public function getOdooIdAttribute()
    {
        return $this->attributes['erp_id'] ?? null;
    }

    /**
     * Backwards compatibility: odoo_id → erp_id (SET)
     */
    public function setOdooIdAttribute($value)
    {
        $this->attributes['erp_id'] = $value;
    }

    /**
     * Backwards compatibility: shopify_secondary_id → ecom_secondary_id (GET)
     */
    public function getShopifySecondaryIdAttribute()
    {
        return $this->attributes['ecom_secondary_id'] ?? null;
    }

    /**
     * Backwards compatibility: shopify_secondary_id → ecom_secondary_id (SET)
     */
    public function setShopifySecondaryIdAttribute($value)
    {
        $this->attributes['ecom_secondary_id'] = $value;
    }

    /**
     * Backwards compatibility: odoo_reference → erp_reference (GET)
     */
    public function getOdooReferenceAttribute()
    {
        return $this->attributes['erp_reference'] ?? null;
    }

    /**
     * Backwards compatibility: odoo_reference → erp_reference (SET)
     */
    public function setOdooReferenceAttribute($value)
    {
        $this->attributes['erp_reference'] = $value;
    }

    /**
     * Backwards compatibility: shopify_handle → ecom_handle (GET)
     */
    public function getShopifyHandleAttribute()
    {
        return $this->attributes['ecom_handle'] ?? null;
    }

    /**
     * Backwards compatibility: shopify_handle → ecom_handle (SET)
     */
    public function setShopifyHandleAttribute($value)
    {
        $this->attributes['ecom_handle'] = $value;
    }
}