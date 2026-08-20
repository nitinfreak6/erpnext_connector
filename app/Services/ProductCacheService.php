<?php

namespace App\Services;

use App\Models\ProductCache;
use App\Models\SyncMapping;
use App\Support\ErpId;
use App\Services\Erp\ErpInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProductCacheService
{
    private const DISK     = 'local';
    private const BASE_DIR = 'products';

    public function __construct(private readonly ErpInterface $erp) {}

    private function normalizeErpId(mixed $id): int|string
    {
        return ErpId::normalize($id);
    }

    private function cacheFileKey(int|string $erpId): string
    {
        if (is_int($erpId)) {
            return (string) $erpId;
        }

        return preg_replace('/[^a-zA-Z0-9._-]/', '_', $erpId) ?: md5($erpId);
    }

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

    public function fetchAndCacheSingle(int|string $erpId, bool $forceRefetch = false): ProductCache
    {
        $slim = $this->erp->getProductById($erpId);

        if (!$slim) {
            throw new \RuntimeException("ERP product #{$erpId} not found in {$this->erp->driverName()}.");
        }

        if (!$forceRefetch) {
            $erpIdCol     = ProductCache::erpIdColumn();
            $existing     = ProductCache::where($erpIdCol, (string) $erpId)->first();
            $erpWriteDate = $slim['write_date'] ?? null;

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

        $fullProduct = method_exists($this->erp, 'getProductByIdFull')
            ? ($this->erp->getProductByIdFull($erpId) ?? $slim)
            : $slim;

        return $this->cacheProduct($fullProduct);
    }

    public function cacheProduct(array $template): ProductCache
    {
        $erpId = $this->normalizeErpId($template['id'] ?? '');

        if ($erpId === 0 || $erpId === '0') {
            throw new \RuntimeException(
                'Product template missing ERP id — check field configs and ERP product fetch.'
            );
        }

        $variants = $this->erp->getVariantsForProducts([$erpId]);

        $avIds = [];
        foreach ($variants as $v) {
            $avIds = array_merge($avIds, $v['product_template_attribute_value_ids'] ?? []);
        }

        $attributeValues = $avIds
            ? $this->erp->getAttributeValues(array_unique($avIds))
            : [];
			
		$vendors = method_exists($this->erp, 'getVendorsForTemplate')
			? $this->erp->getVendorsForTemplate(is_int($erpId) ? $erpId : 0)
			: [];

        $data = [
            'fetched_at'       => now()->toISOString(),
            'erp_id'           => $erpId,
            'odoo_id'          => $erpId,
            'template'         => $template,
            'variants'         => $variants,
            'vendors'          => $vendors,
            'attribute_values' => $attributeValues,
        ];

        $filePath = self::BASE_DIR . '/' . $this->cacheFileKey($erpId) . '.json';
        Storage::disk(self::DISK)->put($filePath, json_encode($data, JSON_PRETTY_PRINT));

        $display = app(FieldMappingService::class)->extractProductCacheDisplay($template, $variants);

        $erpIdCol = ProductCache::hasEcomColumns() ? 'erp_id' : 'odoo_id';
        $lookupId = (string) $erpId;

        $existing = ProductCache::where($erpIdCol, $lookupId)->first();

        $updatePayload = [
            'odoo_id'       => $lookupId,
            'erp_id'        => $lookupId,
            'name'          => $display['name'],
            'default_code'  => $display['default_code'],
            'barcode'       => $display['barcode'],
            'product_type'  => $display['product_type'],
            'is_active'     => $display['is_active'],
            'price'         => $display['price'],
            'cost'          => $display['cost'],
            'weight'        => $display['weight'],
            'category'      => $display['category'],
            'variant_count' => count($variants),
            'raw_data'      => $data,
            'file_path'     => $filePath,
            'fetched_at'    => now(),
        ];

        $updatePayload['ecom_status']     = $existing ? ProductCache::STATUS_UPDATED : ProductCache::STATUS_PENDING;
        $updatePayload['shopify_status']  = $updatePayload['ecom_status'];
        $updatePayload['ecom_message']    = null;
        $updatePayload['shopify_message'] = null;

        if ($existing) {
            Log::info("ProductCacheService: #{$erpId} re-fetched — marked updated.");
        }

        $cache = ProductCache::updateOrCreate([$erpIdCol => $lookupId], $updatePayload);

        Log::info("ProductCacheService [{$this->erp->driverName()}]: cached #{$erpId} ({$display['name']})");

        return $cache;
    }

    // ── Read ──────────────────────────────────────────────────────────────

    public function read(int|string $erpId): ?array
    {
        $col   = ProductCache::erpIdColumn();
        $cache = ProductCache::where($col, (string) $erpId)->first();

        if (!$cache || !$cache->cacheExists()) {
            return null;
        }

        return $cache->readCache();
    }

    public function readOrFail(int|string $erpId): array
    {
        $data = $this->read($erpId);

        if (!$data) {
            $cache = $this->fetchAndCacheSingle($erpId);
            $data  = $cache->readCache();
        }

        return $data;
    }

    // ── Status updates ────────────────────────────────────────────────────

    public function markEcomSent(int|string $erpId, string $ecomProductId): void
    {
        $col = ProductCache::erpIdColumn();
        $key = (string) $erpId;

        ProductCache::where($col, $key)->update([
            'ecom_status'        => ProductCache::STATUS_SENT,
            'ecom_product_id'    => $ecomProductId,
            'ecom_message'       => null,
            'ecom_synced_at'     => now(),
            'shopify_status'     => ProductCache::STATUS_SENT,
            'shopify_product_id' => $ecomProductId,
            'shopify_message'    => null,
            'shopify_synced_at'  => now(),
        ]);

        $settings = app(SettingsService::class);
        SyncMapping::updateOrCreate(
            ['entity_type' => 'product', 'erp_id' => $key],
            [
                'ecom_id'             => $ecomProductId,
                'ecom_driver'         => $settings->ecomDriver(),
                'erp_driver'          => $settings->erpDriver(),
                'last_sync_direction' => 'erp_to_ecom',
                'last_synced_at'      => now(),
            ]
        );
    }

    public function markEcomFailed(int|string $erpId, string $message): void
    {
        $col = ProductCache::erpIdColumn();
        $truncated = strlen($message) > 2000 ? substr($message, 0, 2000) . '…' : $message;

        ProductCache::where($col, (string) $erpId)->update([
            'ecom_status'     => ProductCache::STATUS_PENDING,
            'ecom_message'    => $truncated,
            'shopify_status'  => ProductCache::STATUS_PENDING,
            'shopify_message' => $truncated,
        ]);
    }

    public function markShopifySent(int|string $erpId, string $shopifyProductId): void
    {
        $this->markEcomSent($erpId, $shopifyProductId);
    }

    public function markShopifyFailed(int|string $erpId, string $message): void
    {
        $this->markEcomFailed($erpId, $message);
    }

    public function markAmazonSent(int|string $erpId, string $message = ''): void
    {
        $col = ProductCache::erpIdColumn();
        $key = (string) $erpId;
        ProductCache::where($col, $key)->orWhere('odoo_id', $key)->update([
            'amazon_status'    => ProductCache::STATUS_SENT,
            'amazon_message'   => $message,
            'amazon_synced_at' => now(),
        ]);
    }

    public function markAmazonFailed(int|string $erpId, string $message): void
    {
        $col = ProductCache::erpIdColumn();
        $key = (string) $erpId;
        ProductCache::where($col, $key)->orWhere('odoo_id', $key)->update([
            'amazon_status'  => ProductCache::STATUS_FAILED,
            'amazon_message' => $message,
        ]);
    }

    public function clearCache(int|string $erpId): void
    {
        $col   = ProductCache::erpIdColumn();
        $cache = ProductCache::where($col, (string) $erpId)->first();
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
