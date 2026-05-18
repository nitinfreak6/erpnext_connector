<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

class ProductCache extends Model
{
    protected $table = 'product_cache';

    protected $fillable = [
        'odoo_id',
        'name',
        'default_code',
        // ── New display columns ────────────────────────────
        'price',
        'cost',
        'weight',
        'barcode',
        'category',
        'variant_count',
        'is_active',
        'product_type',
        // ── Channel IDs ───────────────────────────────────
        'shopify_product_id',
        'shopify_handle',
        'amazon_asin',
        // ── Statuses ──────────────────────────────────────
        'shopify_status',
        'amazon_status',
        'shopify_message',
        'amazon_message',
        // ── Raw payload (replaces JSON file for reads) ────
        'raw_data',
        // ── File path (kept for audit/backup only) ────────
        'file_path',
        // ── Timestamps ────────────────────────────────────
        'fetched_at',
        'shopify_synced_at',
        'amazon_synced_at',
    ];

    protected $casts = [
        'raw_data'          => 'array',   // ← auto encode/decode JSON
        'is_active'         => 'boolean',
        'price'             => 'float',
        'cost'              => 'float',
        'weight'            => 'float',
        'variant_count'     => 'integer',
        'fetched_at'        => 'datetime',
        'shopify_synced_at' => 'datetime',
        'amazon_synced_at'  => 'datetime',
    ];

    // ── Status constants ─────────────────────────────────────────────────
    const STATUS_PENDING = 'pending';
    const STATUS_SENT    = 'sent';
    const STATUS_FAILED  = 'failed';
    const STATUS_SKIPPED = 'skipped';

    // ── Scopes ───────────────────────────────────────────────────────────

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

    public function scopeShopifyStatus(Builder $query, string $status): Builder
    {
        return $query->where('shopify_status', $status);
    }

    public function scopeAmazonStatus(Builder $query, string $status): Builder
    {
        return $query->where('amazon_status', $status);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Get variant data from raw_data (no file read needed).
     */
    public function getVariants(): array
    {
        return $this->raw_data['variants'] ?? [];
    }

    /**
     * Get attribute values from raw_data.
     */
    public function getAttributeValues(): array
    {
        return $this->raw_data['attribute_values'] ?? [];
    }

    /**
     * Get template data from raw_data.
     */
    public function getTemplate(): array
    {
        return $this->raw_data['template'] ?? [];
    }

    /**
     * Read the full cached product data.
     * Prefers raw_data column (fast DB read).
     * Falls back to JSON file only if raw_data is null (legacy records).
     */
    public function readCache(): ?array
    {
        // Fast path — data already in DB column
        if ($this->raw_data !== null) {
            return $this->raw_data;
        }

        // Legacy fallback — read JSON file for old records
        if ($this->file_path && Storage::disk('local')->exists($this->file_path)) {
            $content = Storage::disk('local')->get($this->file_path);
            return $content ? json_decode($content, true) : null;
        }

        return null;
    }

    /**
     * Check if this product has usable cached data.
     */
    public function cacheExists(): bool
    {
        if ($this->raw_data !== null) {
            return true;
        }
        return $this->file_path && Storage::disk('local')->exists($this->file_path);
    }

    /**
     * CSS classes for status badges.
     */
    public function statusBadgeClass(string $channel): string
    {
        $status = $channel === 'shopify' ? $this->shopify_status : $this->amazon_status;

        return match ($status) {
            self::STATUS_SENT    => 'bg-emerald-100 text-emerald-700',
            self::STATUS_FAILED  => 'bg-red-100 text-red-700',
            self::STATUS_SKIPPED => 'bg-yellow-100 text-yellow-700',
            default              => 'bg-gray-100 text-gray-500',
        };
    }

    /**
     * JSON file still exists on disk (for audit/backup check).
     */
    public function fileExists(): bool
    {
        return $this->file_path && Storage::disk('local')->exists($this->file_path);
    }
}