<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ProductCache extends Model
{
    protected $table = 'product_cache';

    protected $fillable = [
        'odoo_id',
        'erp_id',              // generic — added by migration
        'name',
        'default_code',
        'price',
        'cost',
        'weight',
        'barcode',
        'category',
        'variant_count',
        'is_active',
        'product_type',
        'shopify_product_id',
        'ecom_product_id',     // generic
        'shopify_handle',
        'amazon_asin',
        'shopify_status',
        'ecom_status',         // generic
        'amazon_status',
        'shopify_message',
        'ecom_message',        // generic
        'amazon_message',
        'raw_data',
        'file_path',
        'fetched_at',
        'shopify_synced_at',
        'ecom_synced_at',      // generic
        'amazon_synced_at',
    ];

    protected $casts = [
        'raw_data'          => 'array',
        'is_active'         => 'boolean',
        'price'             => 'float',
        'cost'              => 'float',
        'weight'            => 'float',
        'variant_count'     => 'integer',
        'fetched_at'        => 'datetime',
        'shopify_synced_at' => 'datetime',
        'ecom_synced_at'    => 'datetime',
        'amazon_synced_at'  => 'datetime',
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_SENT    = 'sent';
    const STATUS_FAILED  = 'failed';
    const STATUS_SKIPPED = 'skipped';

    // ── Column existence cache ────────────────────────────────────────────
    // Checked once per request, avoids repeated SHOW COLUMNS calls.
    private static ?bool $hasEcomColumns = null;

    /**
     * Returns true once the 2026_06_01_000003 migration has run.
     * Safe to call before and after migration.
     */
    public static function hasEcomColumns(): bool
    {
        if (static::$hasEcomColumns === null) {
            try {
                static::$hasEcomColumns = Schema::hasColumn('product_cache', 'ecom_status');
            } catch (\Throwable) {
                static::$hasEcomColumns = false;
            }
        }
        return static::$hasEcomColumns;
    }

    /**
     * Returns the correct status column name for the ecom channel.
     * Use this everywhere instead of hardcoding 'ecom_status' or 'shopify_status'.
     */
    public static function ecomStatusColumn(): string
    {
        return static::hasEcomColumns() ? 'ecom_status' : 'shopify_status';
    }

    public static function erpIdColumn(): string
    {
        return static::hasEcomColumns() ? 'erp_id' : 'odoo_id';
    }

    // ── Accessors ─────────────────────────────────────────────────────────

    /**
     * $cache->ecom_status — reads generic column if available, falls back.
     */
    public function getEcomStatusAttribute(): ?string
    {
        if (static::hasEcomColumns() && isset($this->attributes['ecom_status'])) {
            return $this->attributes['ecom_status'];
        }
        return $this->attributes['shopify_status'] ?? null;
    }

    public function getErpIdAttribute(): ?int
    {
        if (static::hasEcomColumns() && isset($this->attributes['erp_id'])) {
            return (int) $this->attributes['erp_id'];
        }
        return isset($this->attributes['odoo_id']) ? (int) $this->attributes['odoo_id'] : null;
    }

    public function getEcomProductIdAttribute(): ?string
    {
        if (static::hasEcomColumns() && isset($this->attributes['ecom_product_id'])) {
            return $this->attributes['ecom_product_id'];
        }
        return $this->attributes['shopify_product_id'] ?? null;
    }

    public function getEcomSyncedAtAttribute()
    {
        if (static::hasEcomColumns() && isset($this->attributes['ecom_synced_at'])) {
            return $this->attributes['ecom_synced_at']
                ? \Carbon\Carbon::parse($this->attributes['ecom_synced_at'])
                : null;
        }
        return isset($this->attributes['shopify_synced_at'])
            ? \Carbon\Carbon::parse($this->attributes['shopify_synced_at'])
            : null;
    }

    public function getEcomMessageAttribute(): ?string
    {
        if (static::hasEcomColumns() && isset($this->attributes['ecom_message'])) {
            return $this->attributes['ecom_message'];
        }
        return $this->attributes['shopify_message'] ?? null;
    }

    // ── Scopes ────────────────────────────────────────────────────────────

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(function ($q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
              ->orWhere('default_code', 'like', "%{$term}%")
              ->orWhere('barcode', 'like', "%{$term}%")
              ->orWhere('shopify_product_id', 'like', "%{$term}%")
              ->orWhere('amazon_asin', 'like', "%{$term}%");
        });
    }

    /**
     * Filter by ecom status — works before and after migration.
     */
    public function scopeEcomStatus(Builder $query, string $status): Builder
    {
        $col = static::ecomStatusColumn();
        return $query->where($col, $status);
    }

    public function scopeAmazonStatus(Builder $query, string $status): Builder
    {
        return $query->where('amazon_status', $status);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    // ── Status count helpers ──────────────────────────────────────────────
    // These are the ONLY place that decides which column to query.
    // All controllers should call these instead of writing raw ->where() calls.

    public static function countEcomStatus(string $status): int
    {
        $col = static::ecomStatusColumn();
        return static::where($col, $status)->count();
    }

    public static function pendingEcomIds(): array
    {
        $col = static::ecomStatusColumn();
        $erpCol = static::erpIdColumn();
        return static::where($col, '!=', 'sent')
            ->orWhereNull($col)
            ->pluck($erpCol)
            ->map(fn($id) => (int) $id)
            ->filter()
            ->toArray();
    }

    // ── Cache helpers ─────────────────────────────────────────────────────

    public function readCache(): ?array
    {
        if ($this->raw_data !== null) {
            return $this->raw_data;
        }

        if ($this->file_path && Storage::disk('local')->exists($this->file_path)) {
            $content = Storage::disk('local')->get($this->file_path);
            return $content ? json_decode($content, true) : null;
        }

        return null;
    }

    public function cacheExists(): bool
    {
        if ($this->raw_data !== null) {
            return true;
        }
        return $this->file_path && Storage::disk('local')->exists($this->file_path);
    }

    public function statusBadgeClass(string $channel): string
    {
        $status = match($channel) {
            'ecom'   => $this->ecom_status,
            'shopify'=> $this->attributes['shopify_status'] ?? $this->ecom_status,
            'amazon' => $this->attributes['amazon_status'] ?? null,
            default  => null,
        };

        return match ($status) {
            self::STATUS_SENT    => 'bg-emerald-100 text-emerald-700',
            self::STATUS_FAILED  => 'bg-red-100 text-red-700',
            self::STATUS_SKIPPED => 'bg-yellow-100 text-yellow-700',
            default              => 'bg-gray-100 text-gray-500',
        };
    }

    public function fileExists(): bool
    {
        return $this->file_path && Storage::disk('local')->exists($this->file_path);
    }
}
