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

        if (!$mapping) {
            // Fall back to default_value if configured on any active mapping for this type/channel
            $default = ChannelMapping::ofType($type)->forChannel($channel)->active()
                ->whereNotNull('meta->default_value')->first();
            return $this->cache[$cacheKey] = $default?->meta['default_value'] ?? null;
        }

        $value = $mapping->external_id;

        // Apply meta transforms if configured
        $meta = $mapping->meta ?? [];
        if (!empty($meta['min_length']) && strlen($value) < (int) $meta['min_length']) {
            $value = str_pad($value, (int) $meta['min_length'], '0', STR_PAD_LEFT);
        }
        if (!empty($meta['max_length'])) {
            $value = substr($value, 0, (int) $meta['max_length']);
        }

        return $this->cache[$cacheKey] = $value;
    }

    /**
     * Resolve with full mapping object (includes meta, labels, value fields).
     */
    public function resolveMapping(string $type, string $channel, string $odooId): ?ChannelMapping
    {
        return ChannelMapping::ofType($type)
            ->forChannel($channel)
            ->active()
            ->where('odoo_id', $odooId)
            ->first();
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

        if (!$result) {
            $settingsMap = app(SettingsService::class)->odooLocationMap();
            $result = $settingsMap[$odooLocationId]
                ?? config('odoo.location_map', [])[$odooLocationId]
                ?? null;
        }

        return $result;
    }

    /**
     * Direct Shopify location → Odoo stock.location lookup (ignores channel filter).
     * Matches numeric ids and gid://shopify/Location/… interchangeably.
     */
    public function resolveWarehouseOdooIdForShopifyLocation(string $shopifyLocationId): ?string
    {
        $targetNumeric = $this->normalizeShopifyLocationNumericId(trim($shopifyLocationId));

        if ($targetNumeric === '' || $targetNumeric === '0') {
            return null;
        }

        foreach (ChannelMapping::query()
            ->where('type', ChannelMapping::TYPE_WAREHOUSE)
            ->where(function ($query) {
                $query->where('is_active', true)
                    ->orWhere('is_active', 1)
                    ->orWhere('is_active', '1');
            })
            ->get(['odoo_id', 'external_id']) as $mapping) {
            $externalId = trim((string) ($mapping->external_id ?? ''));

            if (!$this->isRealWarehouseExternalId($externalId)) {
                continue;
            }

            if ($this->normalizeShopifyLocationNumericId($externalId) !== $targetNumeric
                && !$this->shopifyLocationMatches($externalId, $shopifyLocationId)) {
                continue;
            }

            $odooId = trim((string) ($mapping->odoo_id ?? ''));

            if ($odooId !== '' && $odooId !== '0') {
                return $odooId;
            }
        }

        $activeRows = ChannelMapping::query()
            ->where('type', ChannelMapping::TYPE_WAREHOUSE)
            ->where(function ($query) {
                $query->where('is_active', true)
                    ->orWhere('is_active', 1)
                    ->orWhere('is_active', '1');
            })
            ->get(['odoo_id', 'external_id'])
            ->filter(fn ($row) => $this->isRealWarehouseExternalId((string) ($row->external_id ?? '')));

        if ($activeRows->count() === 1) {
            $odooId = trim((string) ($activeRows->first()->odoo_id ?? ''));

            if ($odooId !== '' && $odooId !== '0') {
                return $odooId;
            }
        }

        return null;
    }

    /**
     * Short runtime diagnostic when warehouse reverse lookup fails (shown in UI error).
     */
    public function warehouseLookupDiagnostic(string $shopifyLocationId): string
    {
        $targetNumeric = $this->normalizeShopifyLocationNumericId(trim($shopifyLocationId));
        $active        = ChannelMapping::query()
            ->where('type', ChannelMapping::TYPE_WAREHOUSE)
            ->where(function ($query) {
                $query->where('is_active', true)
                    ->orWhere('is_active', 1)
                    ->orWhere('is_active', '1');
            })
            ->get(['external_id', 'odoo_id']);
        $labels = $active
            ->filter(fn ($row) => $this->isRealWarehouseExternalId((string) ($row->external_id ?? '')))
            ->map(fn ($row) => trim((string) ($row->external_id ?? '')) . '→' . trim((string) ($row->odoo_id ?? '')))
            ->filter(fn ($label) => $label !== '→')
            ->values()
            ->implode(', ');

        return 'Debug: '
            . $active->count() . ' active warehouse row(s)'
            . ($labels !== '' ? " [{$labels}]" : '')
            . ", target #{$targetNumeric}.";
    }

    public function odooWarehouse(string $shopifyLocationId, ?string $channel = null): ?string
    {
        $shopifyLocationId = trim($shopifyLocationId);

        if ($shopifyLocationId === '') {
            return null;
        }

        $direct = $this->resolveWarehouseOdooIdForShopifyLocation($shopifyLocationId);
        if ($direct !== null) {
            return $direct;
        }

        $channels = [];
        if ($channel !== null && trim($channel) !== '') {
            $channels[] = strtolower(trim($channel));
        }
        $channels[] = ChannelMapping::CHANNEL_SHOPIFY;
        $channels   = array_values(array_unique($channels));

        foreach ($channels as $tryChannel) {
            $matched = $this->findActiveWarehouseOdooId($shopifyLocationId, $tryChannel);
            if ($matched !== null) {
                return $matched;
            }
        }

        $matched = $this->findActiveWarehouseOdooId($shopifyLocationId, null);
        if ($matched !== null) {
            return $matched;
        }

        foreach ($this->warehouseExternalIdCandidates($shopifyLocationId) as $candidate) {
            $byLabel = ChannelMapping::ofType(ChannelMapping::TYPE_WAREHOUSE)
                ->active()
                ->where('external_label', $candidate)
                ->value('odoo_id');

            if ($byLabel !== null && $byLabel !== '') {
                return (string) $byLabel;
            }
        }

        foreach ($this->legacyWarehouseMaps() as $odooId => $mappedShopifyId) {
            if ($this->shopifyLocationMatches((string) $mappedShopifyId, $shopifyLocationId)) {
                return (string) $odooId;
            }
        }

        if ($this->matchesInventoryFetchLocation($shopifyLocationId, $channel ?? ChannelMapping::CHANNEL_SHOPIFY)) {
            $realRows = ChannelMapping::ofType(ChannelMapping::TYPE_WAREHOUSE)
                ->active()
                ->get(['odoo_id', 'external_id'])
                ->filter(fn ($row) => $this->isRealWarehouseExternalId((string) ($row->external_id ?? '')));

            if ($realRows->count() === 1) {
                $odooId = trim((string) ($realRows->first()->odoo_id ?? ''));

                if ($odooId !== '' && $odooId !== '0') {
                    return $odooId;
                }
            }
        }

        return null;
    }

    private function findActiveWarehouseOdooId(string $shopifyLocationId, ?string $channel): ?string
    {
        $query = ChannelMapping::ofType(ChannelMapping::TYPE_WAREHOUSE)->active();

        if ($channel !== null && $channel !== '') {
            $channel = strtolower(trim($channel));
            $query->where(function ($q) use ($channel) {
                $q->where('channel', $channel)
                    ->orWhere('channel', ChannelMapping::CHANNEL_BOTH);
            });
        }

        foreach ($query->get(['odoo_id', 'external_id']) as $mapping) {
            $externalId = trim((string) ($mapping->external_id ?? ''));

            if (!$this->isRealWarehouseExternalId($externalId)) {
                continue;
            }

            if (!$this->shopifyLocationMatches($externalId, $shopifyLocationId)) {
                continue;
            }

            $odooId = trim((string) ($mapping->odoo_id ?? ''));

            if ($odooId !== '' && $odooId !== '0') {
                return $odooId;
            }
        }

        return null;
    }

    private function isRealWarehouseExternalId(string $externalId): bool
    {
        $externalId = trim($externalId);

        return $externalId !== '' && $externalId !== '0';
    }

    private function matchesInventoryFetchLocation(string $shopifyLocationId, string $channel): bool
    {
        $defaultLoc = $this->defaultShopifyWarehouseLocationId();
        if ($defaultLoc !== null && $this->shopifyLocationMatches($defaultLoc, $shopifyLocationId)) {
            return true;
        }

        $settingsLoc = trim((string) (app(SettingsService::class)->get('shopify_location_id') ?? ''));
        if ($settingsLoc !== '' && $this->shopifyLocationMatches($settingsLoc, $shopifyLocationId)) {
            return true;
        }

        return false;
    }

    /**
     * Actionable hint when reverse warehouse lookup fails (inactive row, wrong external_id, etc.).
     */
    public function warehouseReverseMappingHint(string $shopifyLocationId, ?string $channel = null): ?string
    {
        $channel = $channel ?: ChannelMapping::CHANNEL_SHOPIFY;

        $inactive = ChannelMapping::ofType(ChannelMapping::TYPE_WAREHOUSE)
            ->forChannel($channel)
            ->where('is_active', false)
            ->get(['external_id'])
            ->first(fn ($row) => $this->shopifyLocationMatches(
                (string) ($row->external_id ?? ''),
                $shopifyLocationId
            ));

        if ($inactive !== null) {
            return 'Warehouse mapping for external id '
                . $inactive->external_id
                . ' exists but is inactive — open Mappings → Warehouse and set Status to Active.';
        }

        $activeRows = ChannelMapping::ofType(ChannelMapping::TYPE_WAREHOUSE)
            ->forChannel($channel)
            ->active()
            ->get(['external_id', 'odoo_id']);

        if ($activeRows->isEmpty()) {
            return 'No active warehouse mappings found — add Mappings → Warehouse with external id '
                . $shopifyLocationId
                . ' mapped to your Odoo stock.location id.';
        }

        $matching = $activeRows->first(fn ($row) => $this->shopifyLocationMatches(
            (string) ($row->external_id ?? ''),
            $shopifyLocationId
        ));

        if ($matching !== null) {
            $odooId = trim((string) ($matching->odoo_id ?? ''));

            if ($odooId === '' || $odooId === '0') {
                return 'Warehouse mapping matched Shopify location #'
                    . $shopifyLocationId
                    . ' but Odoo id is empty — set Odoo id to your stock.location id (e.g. 5 for WH/Stock).';
            }
        }

        if ($activeRows->count() > 1) {
            $externalIds = $activeRows
                ->pluck('external_id')
                ->map(fn ($id) => trim((string) $id))
                ->filter(fn ($id) => $id !== '')
                ->implode(', ');

            return 'Multiple active warehouse mappings ('
                . ($externalIds !== '' ? $externalIds : 'none')
                . ') — ensure one row matches Shopify location #'
                . $shopifyLocationId
                . ' (numeric or gid://shopify/Location/…).';
        }

        if ($activeRows->count() === 1) {
            $row = $activeRows->first();
            $ext = trim((string) ($row->external_id ?? ''));

            if ($ext === '' || $ext === '0' || !$this->shopifyLocationMatches($ext, $shopifyLocationId)) {
                return 'Active warehouse mapping uses external id "'
                    . ($ext !== '' ? $ext : '—')
                    . '" but stock was fetched for Shopify location #'
                    . $shopifyLocationId
                    . '. Update external id to '
                    . $shopifyLocationId
                    . ' under Mappings → Warehouse (Odoo id '
                    . ($row->odoo_id ?? '—')
                    . ').';
            }
        }

        return null;
    }

    /**
     * Compare Shopify location IDs (numeric vs GID) — used by inventory warehouse resolution.
     */
    public function shopifyLocationMatches(string $mappedShopifyId, string $shopifyLocationId): bool
    {
        $leftNumeric  = $this->normalizeShopifyLocationNumericId($mappedShopifyId);
        $rightNumeric = $this->normalizeShopifyLocationNumericId($shopifyLocationId);

        if ($leftNumeric !== '' && $rightNumeric !== '' && $leftNumeric === $rightNumeric) {
            return true;
        }

        $left  = $this->warehouseExternalIdCandidates($mappedShopifyId);
        $right = $this->warehouseExternalIdCandidates($shopifyLocationId);

        foreach ($left as $l) {
            foreach ($right as $r) {
                if ($this->normalizeShopifyLocationNumericId($l) === $this->normalizeShopifyLocationNumericId($r)
                    && $this->normalizeShopifyLocationNumericId($l) !== '') {
                    return true;
                }
            }
        }

        return array_intersect($left, $right) !== [];
    }

    /** @return array<string, string> Odoo location ID → Shopify location ID */
    private function legacyWarehouseMaps(): array
    {
        $settingsMap = app(SettingsService::class)->odooLocationMap();
        $configMap   = config('odoo.location_map', []);

        return $settingsMap !== [] ? $settingsMap : (is_array($configMap) ? $configMap : []);
    }

    /**
     * @return list<string>
     */
    private function warehouseExternalIdCandidates(string $shopifyLocationId): array
    {
        $raw = trim($shopifyLocationId);
        if ($raw === '') {
            return [];
        }

        $candidates = [$raw];

        if (str_starts_with($raw, 'gid://')) {
            $numeric = (string) last(explode('/', $raw));
            if ($numeric !== '') {
                $candidates[] = $numeric;
            }
        } elseif (ctype_digit($raw)) {
            $candidates[] = "gid://shopify/Location/{$raw}";
        }

        return array_values(array_unique($candidates));
    }

    /**
     * First active Odoo warehouse id for inventory/product stock (WH/Stock, etc.).
     */
    public function defaultWarehouseOdooId(?string $channel = ChannelMapping::CHANNEL_SHOPIFY): ?string
    {
        $query = ChannelMapping::ofType(ChannelMapping::TYPE_WAREHOUSE)
            ->active()
            ->orderBy('id');

        if ($channel !== null && $channel !== '') {
            $query->forChannel($channel);
        }

        $odooId = $query->value('odoo_id');

        if ($odooId === null || $odooId === '') {
            return null;
        }

        return (string) $odooId;
    }

    public function hasActiveWarehouseMappings(): bool
    {
        return ChannelMapping::query()
            ->where('type', ChannelMapping::TYPE_WAREHOUSE)
            ->where(function ($query) {
                $query->where('is_active', true)
                    ->orWhere('is_active', 1)
                    ->orWhere('is_active', '1');
            })
            ->get(['external_id'])
            ->contains(fn ($row) => $this->isRealWarehouseExternalId((string) ($row->external_id ?? '')));
    }

    /**
     * Active Odoo stock.location IDs with warehouse channel mappings.
     *
     * @return list<string>
     */
    public function activeWarehouseOdooIds(string $channel = ChannelMapping::CHANNEL_SHOPIFY): array
    {
        return ChannelMapping::ofType(ChannelMapping::TYPE_WAREHOUSE)
            ->forChannel($channel)
            ->active()
            ->pluck('odoo_id')
            ->map(fn ($id) => (string) $id)
            ->filter(fn ($id) => $id !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Shopify location for inventory fetch/display — warehouse mapping first, then settings fallback.
     */
    public function defaultShopifyWarehouseLocationId(?string $odooLocationId = null): ?string
    {
        if ($odooLocationId !== null && $odooLocationId !== '') {
            $mapped = $this->shopifyWarehouse((string) $odooLocationId);
            if ($mapped !== null && $mapped !== '') {
                return $this->normalizeShopifyLocationNumericId($mapped);
            }
        }

        $externalId = ChannelMapping::ofType(ChannelMapping::TYPE_WAREHOUSE)
            ->forChannel(ChannelMapping::CHANNEL_SHOPIFY)
            ->active()
            ->orderBy('id')
            ->value('external_id');

        if ($externalId !== null && $externalId !== '' && (string) $externalId !== '0') {
            return $this->normalizeShopifyLocationNumericId((string) $externalId);
        }

        $settings = app(SettingsService::class)->get('shopify_location_id');
        if ($settings) {
            return $this->normalizeShopifyLocationNumericId((string) $settings);
        }

        return app(\App\Services\Shopify\ShopifyInventoryService::class)->getFirstLocationId();
    }

    public function normalizeShopifyLocationNumericId(string $id): string
    {
        $id = trim($id);

        if (str_starts_with($id, 'gid://')) {
            return (string) last(explode('/', $id));
        }

        return $id;
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
     * Shipping: Odoo delivery.carrier ID → Shopify tracking company / shipping title string.
     */
    public function shopifyShippingCarrier(string $odooCarrierId): ?string
    {
        return $this->resolve(ChannelMapping::TYPE_SHIPPING, ChannelMapping::CHANNEL_SHOPIFY, $odooCarrierId);
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