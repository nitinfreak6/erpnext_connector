<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class ProductCache extends Model
{
    protected $table = 'product_cache';

    protected $fillable = [
        'odoo_id',
        'name',
        'default_code',
        'file_path',
        'shopify_status',
        'amazon_status',
        'shopify_product_id',
        'shopify_message',
        'amazon_message',
        'fetched_at',
        'shopify_synced_at',
        'amazon_synced_at',
    ];

    protected $casts = [
        'fetched_at'        => 'datetime',
        'shopify_synced_at' => 'datetime',
        'amazon_synced_at'  => 'datetime',
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_SENT    = 'sent';
    const STATUS_FAILED  = 'failed';
    const STATUS_SKIPPED = 'skipped';

    /**
     * Read the cached product JSON from disk.
     */
    public function readCache(): ?array
    {
        if (!$this->file_path) return null;

        $content = Storage::disk('local')->get($this->file_path);

        return $content ? json_decode($content, true) : null;
    }

    /**
     * Check if cache file exists on disk.
     */
    public function cacheExists(): bool
    {
        return $this->file_path && Storage::disk('local')->exists($this->file_path);
    }

    public function statusBadgeClass(string $channel): string
    {
        $status = $channel === 'shopify' ? $this->shopify_status : $this->amazon_status;

        return match($status) {
            self::STATUS_SENT    => 'bg-emerald-100 text-emerald-700',
            self::STATUS_FAILED  => 'bg-red-100 text-red-700',
            self::STATUS_SKIPPED => 'bg-yellow-100 text-yellow-700',
            default              => 'bg-gray-100 text-gray-500',
        };
    }
}