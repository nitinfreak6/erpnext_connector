<?php

namespace App\Services;

use App\Models\ProductCache;
use App\Services\Odoo\OdooProductService;
use App\Services\Odoo\OdooService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProductCacheService
{
    private const DISK      = 'local';
    private const BASE_DIR  = 'products';

    public function __construct(
        private readonly OdooService        $odoo,
        private readonly OdooProductService $odooProducts,
    ) {}

    // ── Fetch & Cache ────────────────────────────────────────────────────

    /**
     * Fetch ALL active product templates from Odoo and cache each to a JSON file.
     * Returns count of products fetched.
     */
    public function fetchAndCacheAll(): int
    {
        $templates = $this->odoo->searchRead(
            'product.template',
            [['sale_ok', '=', true], ['active', '=', true]],
            ['id', 'name', 'default_code', 'description_sale', 'list_price',
             'standard_price', 'weight', 'categ_id', 'barcode',
             'website_meta_keywords', 'attribute_line_ids', 'product_variant_ids',
             'type', 'active', 'sale_ok'],
            ['limit' => 500]
        );

        $count = 0;

        foreach ($templates as $template) {
            try {
                $this->cacheProduct($template);
                $count++;
            } catch (\Throwable $e) {
                Log::error("ProductCacheService: failed to cache product #{$template['id']}: " . $e->getMessage());
            }
        }

        return $count;
    }

    /**
     * Fetch and cache a single product template by Odoo ID.
     */
    public function fetchAndCacheSingle(int $odooId): ProductCache
    {
        $templates = $this->odoo->searchRead(
            'product.template',
            [['id', '=', $odooId]],
            ['id', 'name', 'default_code', 'description_sale', 'list_price',
             'standard_price', 'weight', 'categ_id', 'barcode',
             'website_meta_keywords', 'attribute_line_ids', 'product_variant_ids',
             'type', 'active', 'sale_ok']
        );

        if (empty($templates)) {
            throw new \RuntimeException("Odoo product #{$odooId} not found.");
        }

        return $this->cacheProduct($templates[0]);
    }

    /**
     * Cache a single product template with its variants to a JSON file.
     */
    public function cacheProduct(array $template): ProductCache
    {
        $odooId   = $template['id'];
        $variants = $this->odooProducts->getVariantsForTemplates([$odooId]);

        // Fetch attribute values for variants
        $avIds = [];
        foreach ($variants as $v) {
            $avIds = array_merge($avIds, $v['product_template_attribute_value_ids'] ?? []);
        }
        $attributeValues = $avIds
            ? $this->odooProducts->getAttributeValues(array_unique($avIds))
            : [];

        // Fetch product attributes (custom fields like HSN, color etc.)
        $productAttributes = $this->odooProducts->getProductAttributes($odooId);

        // Build full data structure
        $data = [
            'fetched_at'         => now()->toISOString(),
            'odoo_id'            => $odooId,
            'template'           => $template,
            'variants'           => $variants,
            'attribute_values'   => $attributeValues,
            'product_attributes' => $productAttributes,
        ];

        // Write to storage/app/products/{odoo_id}.json
        $filePath = self::BASE_DIR . "/{$odooId}.json";
        Storage::disk(self::DISK)->put($filePath, json_encode($data, JSON_PRETTY_PRINT));

        // Upsert DB record
        $cache = ProductCache::updateOrCreate(
            ['odoo_id' => $odooId],
            [
                'name'         => $template['name'],
                'default_code' => $template['default_code'] ?: null,
                'file_path'    => $filePath,
                'fetched_at'   => now(),
            ]
        );

        Log::info("ProductCacheService: cached product #{$odooId} ({$template['name']})");

        return $cache;
    }

    // ── Read from cache ──────────────────────────────────────────────────

    /**
     * Read a cached product by Odoo ID.
     * Returns null if not cached yet.
     */
    public function read(int $odooId): ?array
    {
        $cache = ProductCache::where('odoo_id', $odooId)->first();

        if (!$cache || !$cache->cacheExists()) {
            return null;
        }

        return $cache->readCache();
    }

    /**
     * Read cached product — throw if not found.
     */
    public function readOrFail(int $odooId): array
    {
        $data = $this->read($odooId);

        if (!$data) {
            // Auto-fetch if not cached
            $cache = $this->fetchAndCacheSingle($odooId);
            $data  = $cache->readCache();
        }

        return $data;
    }

    // ── Status updates ───────────────────────────────────────────────────

    public function markShopifySent(int $odooId, string $shopifyProductId): void
    {
        ProductCache::where('odoo_id', $odooId)->update([
            'shopify_status'     => ProductCache::STATUS_SENT,
            'shopify_product_id' => $shopifyProductId,
            'shopify_message'    => null,
            'shopify_synced_at'  => now(),
        ]);
    }

    public function markShopifyFailed(int $odooId, string $message): void
    {
        ProductCache::where('odoo_id', $odooId)->update([
            'shopify_status'  => ProductCache::STATUS_FAILED,
            'shopify_message' => $message,
        ]);
    }

    public function markAmazonSent(int $odooId, string $message = ''): void
    {
        ProductCache::where('odoo_id', $odooId)->update([
            'amazon_status'     => ProductCache::STATUS_SENT,
            'amazon_message'    => $message,
            'amazon_synced_at'  => now(),
        ]);
    }

    public function markAmazonFailed(int $odooId, string $message): void
    {
        ProductCache::where('odoo_id', $odooId)->update([
            'amazon_status'  => ProductCache::STATUS_FAILED,
            'amazon_message' => $message,
        ]);
    }

    /**
     * Delete cache file and DB record for a product.
     */
    public function clearCache(int $odooId): void
    {
        $cache = ProductCache::where('odoo_id', $odooId)->first();
        if ($cache) {
            Storage::disk(self::DISK)->delete($cache->file_path);
            $cache->delete();
        }
    }

    /**
     * Clear ALL cached products.
     */
    public function clearAll(): int
    {
        $count = ProductCache::count();
        Storage::disk(self::DISK)->deleteDirectory(self::BASE_DIR);
        ProductCache::truncate();
        return $count;
    }
}