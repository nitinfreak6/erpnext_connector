<?php

namespace App\Models;

use App\Services\Sync\SyncPayloadStore;
use Illuminate\Database\Eloquent\Model;

class SyncMapping extends Model
{
    protected $fillable = [
        'entity_type',
        'erp_driver',
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
        'sync_message',
        'odoo_id',
        'shopify_id',
        'shopify_secondary_id',
        'odoo_reference',
        'shopify_handle',
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
        'erp_updated_at' => 'datetime',
        'ecom_updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::deleting(function (SyncMapping $mapping) {
            $mapping->deletePayloadFile();
        });
    }

    /**
     * Fetched source payload from disk (or legacy inline JSON in metadata column).
     */
    public function payload(): ?array
    {
        $side = $this->payloadSide();
        $id   = $this->payloadId($side);

        if ($side && $id) {
            foreach ($this->payloadEntityTypes() as $entityType) {
                $fromFile = SyncPayloadStore::get($entityType, $side, $id);
                if ($fromFile !== null) {
                    return $fromFile;
                }
            }
        }

        $raw = $this->getAttributes()['metadata'] ?? null;
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '' && !str_starts_with($raw, SyncPayloadStore::BASE . '/')) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    public function hasPayload(): bool
    {
        return $this->payload() !== null;
    }

    public function storePayload(array $data): void
    {
        $side = $this->payloadSide();
        $id   = $this->payloadId($side);

        if (!$side || !$id) {
            return;
        }

        SyncPayloadStore::put($this->canonicalEntityType(), $side, $id, $data);
    }

    /** @return list<string> */
    private function payloadEntityTypes(): array
    {
        $type = (string) ($this->entity_type ?? '');

        if (in_array($type, ['order', 'sales_order'], true)) {
            return array_values(array_unique([$type, 'sales_order', 'order']));
        }

        return $type !== '' ? [$type] : [];
    }

    private function canonicalEntityType(): string
    {
        $type = (string) ($this->entity_type ?? '');

        return in_array($type, ['order', 'sales_order'], true) ? 'sales_order' : $type;
    }

    public function deletePayloadFile(): void
    {
        $side = $this->payloadSide();
        $id   = $this->payloadId($side);

        if ($this->entity_type && $side && $id) {
            SyncPayloadStore::delete($this->entity_type, $side, $id);
        }
    }

    /** @deprecated Use payload() — kept so existing call sites keep working. */
    public function getMetadataAttribute($value): ?array
    {
        return $this->payload();
    }

    public function payloadSide(): ?string
    {
        $direction = $this->attributes['last_sync_direction'] ?? null;

        if ($direction) {
            return SyncPayloadStore::sideForDirection($direction);
        }

        if (!empty($this->attributes['ecom_id']) && empty($this->attributes['erp_id'])) {
            return 'ecom';
        }

        if (!empty($this->attributes['erp_id'])) {
            return 'erp';
        }

        return !empty($this->attributes['ecom_id']) ? 'ecom' : null;
    }

    public function payloadId(?string $side = null): ?string
    {
        $side ??= $this->payloadSide();

        return match ($side) {
            'ecom'  => !empty($this->attributes['ecom_id']) ? (string) $this->attributes['ecom_id'] : null,
            'erp'   => !empty($this->attributes['erp_id']) ? (string) $this->attributes['erp_id'] : null,
            default => null,
        };
    }

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
