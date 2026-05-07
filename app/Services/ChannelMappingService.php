<?php

namespace App\Services;

use App\Models\ChannelMapping;
use Illuminate\Support\Facades\Cache;

/**
 * Central resolver for all channel mappings.
 * All sync services inject this and call resolve*() methods.
 * Results are cached per-request to avoid repeated DB hits.
 */
class ChannelMappingService
{
    private array $cache = [];

    // ── Generic resolver ────────────────────────────────────────────────

    /**
     * Resolve an Odoo ID → external ID for a given type/channel.
     * Returns null if no active mapping found.
     */
    public function resolve(string $type, string $channel, string $odooId): ?string
    {
        $cacheKey = "{$type}:{$channel}:{$odooId}";

        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        $mapping = ChannelMapping::ofType($type)
            ->forChannel($channel)
            ->active()
            ->where('odoo_id', $odooId)
            ->first();

        return $this->cache[$cacheKey] = $mapping?->external_id;
    }

    /**
     * Resolve an external ID → Odoo ID (reverse lookup).
     */
    public function resolveReverse(string $type, string $channel, string $externalId): ?string
    {
        $cacheKey = "{$type}:{$channel}:rev:{$externalId}";

        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        $mapping = ChannelMapping::ofType($type)
            ->forChannel($channel)
            ->active()
            ->where('external_id', $externalId)
            ->first();

        return $this->cache[$cacheKey] = $mapping?->odoo_id;
    }

    /**
     * Get full map of odoo_id => external_id for a type/channel.
     */
    public function map(string $type, string $channel): array
    {
        $cacheKey = "{$type}:{$channel}:map";

        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        return $this->cache[$cacheKey] = ChannelMapping::asMap($type, $channel);
    }

    // ── Typed resolvers (Shopify) ────────────────────────────────────────

    /**
     * Warehouse: Odoo location ID → Shopify location ID.
     * Falls back to legacy config('odoo.location_map').
     */
    public function shopifyWarehouse(string $odooLocationId): ?string
    {
        $result = $this->resolve(ChannelMapping::TYPE_WAREHOUSE, ChannelMapping::CHANNEL_SHOPIFY, $odooLocationId);

        // Legacy config fallback
        if (!$result) {
            $result = config('odoo.location_map', [])[$odooLocationId] ?? null;
        }

        return $result;
    }

    /**
     * Category: Odoo category ID → Shopify product_type string.
     */
    public function shopifyCategory(string $odooCategoryId): ?string
    {
        return $this->resolve(ChannelMapping::TYPE_CATEGORY, ChannelMapping::CHANNEL_SHOPIFY, $odooCategoryId);
    }

    /**
     * Shipping: Shopify shipping title → Odoo delivery product ID.
     */
    public function odooShippingProduct(string $shopifyShippingTitle): ?string
    {
        return $this->resolveReverse(ChannelMapping::TYPE_SHIPPING, ChannelMapping::CHANNEL_SHOPIFY, $shopifyShippingTitle);
    }

    /**
     * Payment: Shopify payment gateway → Odoo journal ID.
     */
    public function odooPaymentJournal(string $shopifyGateway): ?string
    {
        return $this->resolveReverse(ChannelMapping::TYPE_PAYMENT, ChannelMapping::CHANNEL_SHOPIFY, $shopifyGateway);
    }

    /**
     * Pricelist: Shopify price rule / currency → Odoo pricelist ID.
     */
    public function odooPricelist(string $shopifyCurrency, string $channel = ChannelMapping::CHANNEL_SHOPIFY): ?string
    {
        return $this->resolveReverse(ChannelMapping::TYPE_PRICELIST, $channel, $shopifyCurrency);
    }

    /**
     * Sales Order Type: channel name → Odoo sale.order.type ID.
     */
    public function odooSalesOrderType(string $channel): ?string
    {
        return $this->resolveReverse(ChannelMapping::TYPE_SALES_ORDER_TYPE, $channel, $channel);
    }

    /**
     * Sales Rep: channel → Odoo user ID to assign as salesperson.
     */
    public function odooSalesRep(string $channel): ?string
    {
        return $this->resolveReverse(ChannelMapping::TYPE_SALES_REP, $channel, $channel);
    }

    /**
     * Tax: Shopify tax title → Odoo tax ID.
     */
    public function odooTax(string $shopifyTaxTitle, string $channel = ChannelMapping::CHANNEL_SHOPIFY): ?string
    {
        return $this->resolveReverse(ChannelMapping::TYPE_TAX, $channel, $shopifyTaxTitle);
    }

    /**
     * Product size: Odoo attribute value → Shopify size option value.
     */
    public function shopifySize(string $odooSizeValue): ?string
    {
        return $this->resolve(ChannelMapping::TYPE_PRODUCT_SIZE, ChannelMapping::CHANNEL_SHOPIFY, $odooSizeValue);
    }

    // ── Typed resolvers (Amazon) ─────────────────────────────────────────

    /**
     * Warehouse: Odoo location ID → Amazon fulfillment center ID.
     */
    public function amazonWarehouse(string $odooLocationId): ?string
    {
        return $this->resolve(ChannelMapping::TYPE_WAREHOUSE, ChannelMapping::CHANNEL_AMAZON, $odooLocationId);
    }

    /**
     * Sales Rep: Amazon channel → Odoo user ID.
     */
    public function odooAmazonSalesRep(): ?string
    {
        return $this->resolveReverse(ChannelMapping::TYPE_SALES_REP, ChannelMapping::CHANNEL_AMAZON, 'amazon');
    }

    /**
     * Sales Order Type: Amazon → Odoo sale.order.type ID.
     */
    public function odooAmazonSalesOrderType(): ?string
    {
        return $this->resolveReverse(ChannelMapping::TYPE_SALES_ORDER_TYPE, ChannelMapping::CHANNEL_AMAZON, 'amazon');
    }

    /**
     * Pricelist: Amazon → Odoo pricelist ID.
     */
    public function odooAmazonPricelist(): ?string
    {
        return $this->resolveReverse(ChannelMapping::TYPE_PRICELIST, ChannelMapping::CHANNEL_AMAZON, 'amazon');
    }

    /**
     * Tax: Amazon tax title → Odoo tax ID.
     */
    public function odooAmazonTax(string $amazonTaxTitle): ?string
    {
        return $this->resolveReverse(ChannelMapping::TYPE_TAX, ChannelMapping::CHANNEL_AMAZON, $amazonTaxTitle);
    }

    /**
     * Clear runtime cache (call if mappings are updated mid-request).
     */
    public function clearCache(): void
    {
        $this->cache = [];
    }
}