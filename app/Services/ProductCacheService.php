<?php

namespace App\Services;

use App\Models\ProductCache;
use App\Services\Erp\ErpInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProductCacheService
{
    private const DISK     = 'local';
    private const BASE_DIR = 'products';

    public function __construct(private readonly ErpInterface $erp) {}

    // ── Fetch & Cache ─────────────────────────────────────────────────────

    public function fetchAndCacheAll(): int
    {
        $offset   = 0;
        $pageSize = 100;
        $count    = 0;

        do {
            $templates = $this->erp->getAllActiveProducts($offset, $pageSize);

            foreach ($templates as $template) {
                try {
                    $this->cacheProduct($template);
                    $count++;
                } catch (\Throwable $e) {
                    Log::error("ProductCacheService: failed to cache product #{$template['id']}: " . $e->getMessage());
                }
            }

            $offset += count($templates);
        } while (count($templates) === $pageSize);

        return $count;
    }

    public function fetchAndCacheSingle(int $erpId, bool $forceRefetch = false): ProductCache
    {
        $product = $this->erp->getProductById($erpId);

        if (!$product) {
            throw new \RuntimeException("ERP product #{$erpId} not found in {$this->erp->driverName()}.");
        }

        // Skip re-cache if write_date hasn't changed since last fetch — unless forced
        if (!$forceRefetch) {
            $erpIdCol  = ProductCache::erpIdColumn();
            $existing  = ProductCache::where($erpIdCol, $erpId)->first();
            $erpWriteDate = $product['write_date'] ?? null;

            if ($existing && $erpWriteDate && $existing->fetched_at) {
                $fetchedAt    = \Carbon\Carbon::parse($existing->fetched_at);
                $erpWrittenAt = \Carbon\Carbon::parse($erpWriteDate);

                if (!$erpWrittenAt->isAfter($fetchedAt)) {
                    \Illuminate\Support\Facades\Log::info(
                        "ProductCacheService: #{$erpId} unchanged (write_date {$erpWriteDate} ≤ fetched_at), skipping re-cache."
                    );
                    return $existing;
                }
            }
        }

        return $this->cacheProduct($product);
    }

    public function cacheProduct(array $template): ProductCache
    {
        $erpId = (int) $template['id'];

        $variants = $this->erp->getVariantsForProducts([$erpId]);

        $avIds = [];
        foreach ($variants as $v) {
            $avIds = array_merge($avIds, $v['product_template_attribute_value_ids'] ?? []);
        }

        $attributeValues = $avIds
            ? $this->erp->getAttributeValues(array_unique($avIds))
            : [];

        $data = [
            'fetched_at'       => now()->toISOString(),
            'erp_id'           => $erpId,
            'odoo_id'          => $erpId,
            'template'         => $template,
            'variants'         => $variants,
            'attribute_values' => $attributeValues,
        ];

        $filePath = self::BASE_DIR . "/{$erpId}.json";
        Storage::disk(self::DISK)->put($filePath, json_encode($data, JSON_PRETTY_PRINT));

        $categoryName = '';
        if (!empty($template['categ_id']) && is_array($template['categ_id'])) {
            $categoryName = $template['categ_id'][1] ?? '';
        }

        $erpIdCol = ProductCache::hasEcomColumns() ? 'erp_id' : 'odoo_id';

        // When re-fetching a product that was already pushed (sent/skipped), reset its
        // ecom status back to 'pending' so the Push button picks it up for re-push.
        // New products (no existing row) have null status and are already picked up.
        $existing    = ProductCache::where($erpIdCol, $erpId)->first();
        $resetStatus = $existing && in_array(
            $existing->ecom_status,
            [ProductCache::STATUS_SENT, ProductCache::STATUS_SKIPPED],
            true
        );

        $updatePayload = [
            'odoo_id'       => $erpId,
            'erp_id'        => $erpId,
            'name'          => $template['name'],
            'default_code'  => $template['default_code'] ?: null,
            'barcode'       => $template['barcode'] ?: null,
            'product_type'  => $template['type'] ?? null,
            'is_active'     => (bool) ($template['active'] ?? true),
            'price'         => $template['list_price'] ?? null,
            'cost'          => $template['standard_price'] ?? null,
            'weight'        => $template['weight'] ?? null,
            'category'      => $categoryName ?: null,
            'variant_count' => count($variants),
            'raw_data'      => $data,
            'file_path'     => $filePath,
            'fetched_at'    => now(),
        ];

        if ($resetStatus) {
            $updatePayload['ecom_status']     = ProductCache::STATUS_PENDING;
            $updatePayload['shopify_status']  = ProductCache::STATUS_PENDING;
            $updatePayload['ecom_message']    = null;
            $updatePayload['shopify_message'] = null;
            Log::info("ProductCacheService: #{$erpId} was '{$existing->ecom_status}' — reset to pending for re-push.");
        }

        $cache = ProductCache::updateOrCreate([$erpIdCol => $erpId], $updatePayload);

        Log::info("ProductCacheService [{$this->erp->driverName()}]: cached #{$erpId} ({$template['name']})");

        return $cache;
    }

    // ── Read ──────────────────────────────────────────────────────────────

    public function read(int $erpId): ?array
    {
        $col   = ProductCache::erpIdColumn();
        $cache = ProductCache::where($col, $erpId)->first();

        if (!$cache || !$cache->cacheExists()) {
            return null;
        }

        return $cache->readCache();
    }

    public function readOrFail(int $erpId): array
    {
        $data = $this->read($erpId);

        if (!$data) {
            $cache = $this->fetchAndCacheSingle($erpId);
            $data  = $cache->readCache();
        }

        return $data;
    }

    // ── Status updates ────────────────────────────────────────────────────

    public function markEcomSent(int $erpId, string $ecomProductId): void
    {
        $col = ProductCache::erpIdColumn();
        ProductCache::where($col, $erpId)->update([
            'ecom_status'        => ProductCache::STATUS_SENT,
            'ecom_product_id'    => $ecomProductId,
            'ecom_message'       => null,
            'ecom_synced_at'     => now(),
            'shopify_status'     => ProductCache::STATUS_SENT,
            'shopify_product_id' => $ecomProductId,
            'shopify_synced_at'  => now(),
        ]);
    }

    public function markEcomFailed(int $erpId, string $message): void
    {
        $col = ProductCache::erpIdColumn();
        ProductCache::where($col, $erpId)->update([
            'ecom_status'    => ProductCache::STATUS_FAILED,
            'ecom_message'   => $message,
            'shopify_status' => ProductCache::STATUS_FAILED,
            'shopify_message'=> $message,
        ]);
    }

    public function markShopifySent(int $erpId, string $shopifyProductId): void
    {
        $this->markEcomSent($erpId, $shopifyProductId);
    }

    public function markShopifyFailed(int $erpId, string $message): void
    {
        $this->markEcomFailed($erpId, $message);
    }

    public function markAmazonSent(int $erpId, string $message = ''): void
    {
        $col = ProductCache::erpIdColumn();
        ProductCache::where($col, $erpId)->orWhere('odoo_id', $erpId)->update([
            'amazon_status'    => ProductCache::STATUS_SENT,
            'amazon_message'   => $message,
            'amazon_synced_at' => now(),
        ]);
    }

    public function markAmazonFailed(int $erpId, string $message): void
    {
        $col = ProductCache::erpIdColumn();
        ProductCache::where($col, $erpId)->orWhere('odoo_id', $erpId)->update([
            'amazon_status'  => ProductCache::STATUS_FAILED,
            'amazon_message' => $message,
        ]);
    }

    public function clearCache(int $erpId): void
    {
        $col   = ProductCache::erpIdColumn();
        $cache = ProductCache::where($col, $erpId)->first();
        if ($cache) {
            if ($cache->file_path) {
                Storage::disk(self::DISK)->delete($cache->file_path);
            }
            $cache->delete();
        }
    }

    public function clearAll(): int
    {
        $count = ProductCache::count();
        Storage::disk(self::DISK)->deleteDirectory(self::BASE_DIR);
        ProductCache::truncate();
        return $count;
    }
}