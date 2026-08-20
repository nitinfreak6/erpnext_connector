<?php

namespace App\Services;

use App\Models\ConnectorSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class SettingsService
{
    private const CACHE_KEY = 'connector_settings_all';
    private const CACHE_TTL = 300; // 5 minutes

    // ── Core get / set ─────────────────────────────────────────────────

    public function get(string $key, ?string $fallbackEnvKey = null): ?string
    {
        $settings = $this->all();

        if (isset($settings[$key])) {
            return $settings[$key];
        }

        if ($fallbackEnvKey) {
            return env($fallbackEnvKey);
        }

        return null;
    }
	
	public function orderSyncMode(): string
	{
		return $this->salesOrderSyncMode();
	}

    public function all(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return ConnectorSetting::where('is_active', true)
                ->get()
                ->mapWithKeys(fn ($s) => [$s->key => $s->getDecryptedValue()])
                ->toArray();
        });
    }

    public function set(string $key, ?string $value): void
    {
        $setting = ConnectorSetting::where('key', $key)->first();

        if ($setting) {
            if ($setting->is_secret && $value !== null && $value !== '') {
                $setting->value = Crypt::encryptString($value);
                $setting->saveQuietly();
            } else {
                $setting->update(['value' => $value]);
            }
        }

        $this->clearCache();
    }

    public function setMany(array $keyValues): void
    {
        foreach ($keyValues as $key => $value) {
            $this->set($key, $value);
        }
    }

    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    // ── App identity ───────────────────────────────────────────────────

    public function appName(): string
    {
        return $this->get('app_name') ?: config('app.name', 'Connector');
    }

    // FIX #1: erp_display_name default is generic 'ERP', not hardcoded 'Odoo'
    public function erpDisplayName(): string
    {
        return $this->get('erp_display_name') ?: 'ERP';
    }

    /** Base URL for the active ERP adapter (wire logs, admin links). */
    public function erpApiBaseUrl(): ?string
    {
        try {
            return app(\App\Services\Erp\ErpInterface::class)->apiBaseUrl();
        } catch (\Throwable) {
            return null;
        }
    }

    // FIX #1: reads 'ecom_display_name' (correct key). Falls back to
    // 'shopify_display_name' for existing installs that have not yet migrated.
    public function ecomDisplayName(): string
    {
        return $this->get('ecom_display_name')
            ?: $this->get('shopify_display_name')
            ?: 'Ecommerce';
    }

    // FIX #2: amazonDisplayName() REMOVED — was a hardcoded channel name.
    // Amazon display is handled by the amazon_display_name connector setting
    // directly in the settings blade, not exposed as a SettingsService method.

    // ── ERP driver ─────────────────────────────────────────────────────

    public function erpDriver(): string
    {
        return $this->get('erp_driver')
            ?? config('sync.erp_driver', env('ERP_DRIVER', 'odoo'));
    }

    // ── E-commerce driver ──────────────────────────────────────────────

    public function ecomDriver(): string
    {
        return $this->get('ecom_driver')
            ?? config('sync.ecom_driver', env('ECOM_DRIVER', 'shopify'));
    }

    // ── Sync master switches ───────────────────────────────────────────

    private function isEnabled(string $key, bool $default = true): bool
    {
        $value = $this->get($key);

        if ($value === null || $value === '') {
            return $default;
        }

        if (in_array(strtolower($value), ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Field-config entities hidden from sidebar/tabs until sync is implemented.
     *
     * @var list<string>
     */
    private const HIDDEN_FIELD_CONFIG_ENTITIES = [
        'sales_credit',
        'sales_credit_confirmation',
        'blind_return',
        'purchase_order',
        'receipt_order',
        'inventory_adjustment',
    ];

    /**
     * Whether a field-config entity should appear in the UI.
     */
    public function isEntitySyncEnabled(string $entityType): bool
    {
        if (in_array($entityType, self::HIDDEN_FIELD_CONFIG_ENTITIES, true)) {
            return false;
        }

        return match ($entityType) {
            'product' => $this->isProductSyncEnabled(),
            'inventory' => $this->isInventorySyncEnabled(),
            'customer' => $this->isCustomerSyncEnabled(),
            'sales_order', 'dispatch' => $this->isSalesOrderSyncEnabled(),
            default => true,
        };
    }

    /**
     * Whether a channel-mapping type should appear in the sidebar.
     */
    public function isMappingTypeEnabled(string $type): bool
    {
        return match ($type) {
            'product_size', 'category' => $this->isProductSyncEnabled(),
            'warehouse' => $this->isInventorySyncEnabled(),
            'shipping', 'payment', 'sales_order_type', 'tax', 'pricelist', 'channel' => $this->isSalesOrderSyncEnabled(),
            'sales_rep' => $this->isCustomerSyncEnabled(),
            default => true,
        };
    }

    /** @return array<string, array{label: string, icon: string, feature: string}> */
    public function sidebarMappingTypes(): array
    {
        $types = [
            'warehouse'        => ['label' => 'Warehouse',        'icon' => 'M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z', 'feature' => 'inventory'],
            'shipping'         => ['label' => 'Shipping',         'icon' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4', 'feature' => 'orders'],
            'category'         => ['label' => 'Category',         'icon' => 'M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10', 'feature' => 'product'],
            'pricelist'        => ['label' => 'Pricelist',        'icon' => 'M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z', 'feature' => 'orders'],
            'payment'          => ['label' => 'Payment',          'icon' => 'M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z', 'feature' => 'orders'],
            'channel'          => ['label' => 'Channel',          'icon' => 'M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z', 'feature' => 'orders'],
            'sales_order_type' => ['label' => 'Order Type',       'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2', 'feature' => 'orders'],
            'sales_rep'        => ['label' => 'Sales Rep',        'icon' => 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z', 'feature' => 'customer'],
            'product_size'     => ['label' => 'Product Size',     'icon' => 'M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4', 'feature' => 'product'],
            'tax'              => ['label' => 'Tax',              'icon' => 'M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z', 'feature' => 'orders'],
        ];

        return array_filter($types, fn (array $info) => match ($info['feature']) {
            'product'   => $this->isProductSyncEnabled(),
            'inventory' => $this->isInventorySyncEnabled(),
            'customer'  => $this->isCustomerSyncEnabled(),
            'orders'    => $this->isSalesOrderSyncEnabled(),
            default     => true,
        });
    }

    // FIX #8: product_sync_enabled is the ONE key (from the Product Settings card).
    // Legacy sync_products_enabled fallback kept for existing installs.
    public function isProductSyncEnabled(): bool
    {
        if ($this->get('product_sync_enabled') !== null) {
            return $this->isEnabled('product_sync_enabled', true);
        }
        return $this->isEnabled('sync_products_enabled', true);
    }

    // FIX #8: inventory_sync_enabled is the ONE key (from the Inventory Settings card).
    public function isInventorySyncEnabled(): bool
    {
        if ($this->get('inventory_sync_enabled') !== null) {
            return $this->isEnabled('inventory_sync_enabled', true);
        }
        return $this->isEnabled('sync_inventory_enabled', true);
    }

    // FIX #8: sales_order_sync_enabled is the ONE key (from the Sales Settings card).
    public function isSalesOrderSyncEnabled(): bool
    {
        if ($this->get('sales_order_sync_enabled') !== null) {
            return $this->isEnabled('sales_order_sync_enabled', true);
        }
        return $this->isEnabled('sync_orders_enabled', true);
    }

    // FIX #8: customer_sync_enabled is the ONE key (from the Customer Settings card).
    public function isCustomerSyncEnabled(): bool
    {
        if ($this->get('customer_sync_enabled') !== null) {
            return $this->isEnabled('customer_sync_enabled', true);
        }
        return $this->isEnabled('sync_customers_enabled', true);
    }

    public function isDispatchConfirmationEnabled(): bool
    {
        return $this->isEnabled('dispatch_confirmation_enabled', true);
    }

    public function isProductLinkingEnabled(): bool
    {
        return $this->isEnabled('product_linking_enabled', false);
    }

    // FIX #2: isShopifyChannelEnabled() REMOVED — Shopify is just the active ecom driver,
    // not a special named channel. Use ecomDriver() === 'shopify' if you need this check.

    public function isAmazonChannelEnabled(): bool
	{
		// Channel toggle must be on AND credentials must exist
		if (!$this->isEnabled('amazon_channel_enabled', true)) {
			return false;
		}

		// If no seller ID configured, Amazon is not actually usable
		return !empty($this->amazonSellerId());
	}

    // ── Sync modes ─────────────────────────────────────────────────────

    public function productSyncMode(): string
    {
        return $this->get('product_sync_mode') ?: 'erp_to_ecom';
    }

    public function customerSyncMode(): string
    {
        return $this->get('customer_sync_mode') ?: 'erp_to_ecom';
    }

    public function salesOrderSyncMode(): string
    {
        return $this->get('sales_order_sync_mode') ?: 'erp_to_ecom';
    }

    public function inventorySyncMode(): string
    {
        return $this->get('inventory_sync_mode') ?: $this->productSyncMode();
    }

    /** Sync mode for field-config UI and entity-specific flows. */
    public function syncModeForEntity(string $entityType): string
    {
        return match ($entityType) {
            'sales_order', 'order' => $this->salesOrderSyncMode(),
            'inventory'            => $this->inventorySyncMode(),
            'customer'             => $this->customerSyncMode(),
            'dispatch'             => $this->dispatchSyncMode(),
            default                => $this->productSyncMode(),
        };
    }

    /**
     * Field-config directions visible for an entity (one per unidirectional sync mode).
     *
     * @return list<'erp_to_ecom'|'ecom_to_erp'>
     */
    public function fieldConfigDirectionsForEntity(string $entityType): array
    {
        $mode = $this->syncModeForEntity($entityType);

        if ($mode === 'bidirectional') {
            return ['erp_to_ecom', 'ecom_to_erp'];
        }

        return [$mode === 'ecom_to_erp' ? 'ecom_to_erp' : 'erp_to_ecom'];
    }

    /** Primary direction used when adding new field configs (first entry from fieldConfigDirectionsForEntity). */
    public function activeFieldConfigDirection(string $entityType): string
    {
        return $this->fieldConfigDirectionsForEntity($entityType)[0];
    }

    public function isFieldConfigDirectionLocked(string $entityType): bool
    {
        return $this->syncModeForEntity($entityType) !== 'bidirectional';
    }

    public function syncModeLabel(string $entityType): string
    {
        $mode = $this->syncModeForEntity($entityType);
        $ecom = $this->ecomDisplayName();
        $erp  = $this->erpDisplayName();

        return match ($mode) {
            'ecom_to_erp'   => "{$ecom} → {$erp}",
            'erp_to_ecom'   => "{$erp} → {$ecom}",
            'bidirectional' => "{$ecom} ↔ {$erp}",
            default         => (string) $mode,
        };
    }

    /**
     * Dispatch direction follows sales-order direction.
     * Stale dispatch_sync_mode rows in connector_settings (old default ecom_to_erp) are ignored
     * when sales orders are erp_to_ecom — otherwise dispatch fetch/post would be wrongly blocked.
     */
    public function dispatchSyncMode(): string
    {
        $salesMode = $this->salesOrderSyncMode();

        if ($salesMode === 'ecom_to_erp') {
            return 'ecom_to_erp';
        }

        if ($salesMode === 'erp_to_ecom') {
            return 'erp_to_ecom';
        }

        // bidirectional — only path implemented today is Odoo delivery → Ecom fulfillment
        $stored = $this->get('dispatch_sync_mode');

        return in_array($stored, ['erp_to_ecom', 'ecom_to_erp'], true) ? $stored : 'erp_to_ecom';
    }

    public function allowsFetchFromErp(string $entityType): bool
    {
        $mode = $this->syncModeForEntity($entityType);

        return $mode === 'erp_to_ecom' || $mode === 'bidirectional';
    }

    public function allowsFetchFromEcom(string $entityType): bool
    {
        $mode = $this->syncModeForEntity($entityType);

        return $mode === 'ecom_to_erp' || $mode === 'bidirectional';
    }

    /** Odoo picking done → Shopify fulfillment (ERP → E-com order sync). */
    public function allowsDispatchErpToEcom(): bool
    {
        return in_array($this->salesOrderSyncMode(), ['erp_to_ecom', 'bidirectional'], true);
    }

    /** Shopify fulfillment → Odoo delivery (E-com → ERP order sync). */
    public function allowsDispatchEcomToErp(): bool
    {
        return in_array($this->salesOrderSyncMode(), ['ecom_to_erp', 'bidirectional'], true);
    }

    public function allowsDispatchFetch(): bool
    {
        return $this->allowsDispatchErpToEcom() || $this->allowsDispatchEcomToErp();
    }

    public function allowsDispatchPost(): bool
    {
        return $this->allowsDispatchFetch();
    }

    /** Which dispatch fetch/post path to use for the current orders listing view. */
    public function dispatchFlowForListing(?string $listingDirection = null): string
    {
        $sales = $this->salesOrderSyncMode();

        if ($sales === 'bidirectional' && in_array($listingDirection, ['erp_to_ecom', 'ecom_to_erp'], true)) {
            return $listingDirection === 'ecom_to_erp' ? 'ecom_to_erp' : 'erp_to_ecom';
        }

        if ($sales === 'ecom_to_erp') {
            return 'ecom_to_erp';
        }

        return 'erp_to_ecom';
    }

    // ── Sync direction helpers ─────────────────────────────────────────
    // FIX #6, #7, #8: All return dynamic driver values — never hardcoded strings.

    /** Channel to pull products FROM. */
    public function productFetchFrom(): string
    {
        $mode = $this->productSyncMode();
        return $mode === 'ecom_to_erp' ? $this->ecomDriver() : $this->erpDriver();
    }

    /** Channel to push products TO. */
    public function productPostTo(): string
    {
        $mode = $this->productSyncMode();
        return $mode === 'ecom_to_erp' ? $this->erpDriver() : $this->ecomDriver();
    }

    /** Channel to pull sales orders FROM. */
    public function salesOrderFetchFrom(): string
    {
        $stored = $this->get('sales_order_fetch_from');
        if ($stored) return $stored;
        $mode = $this->salesOrderSyncMode();
        return $mode === 'ecom_to_erp' ? $this->ecomDriver() : $this->erpDriver();
    }

    /** Channel to push sales orders TO. */
    public function salesOrderPostTo(): string
    {
        $stored = $this->get('sales_order_post_to');
        if ($stored) return $stored;
        $mode = $this->salesOrderSyncMode();
        return $mode === 'ecom_to_erp' ? $this->erpDriver() : $this->ecomDriver();
    }

    /** Channel to pull dispatch confirmations FROM. */
    public function dispatchFetchFrom(): string
    {
        $stored = $this->get('dispatch_fetch_from');
        if ($stored) {
            return $stored;
        }

        return $this->dispatchSyncMode() === 'ecom_to_erp'
            ? $this->ecomDriver()
            : $this->erpDriver();
    }

    /** Channel to push dispatch confirmations TO. */
    public function dispatchPostTo(): string
    {
        $stored = $this->get('dispatch_post_to');
        if ($stored) {
            return $stored;
        }

        return $this->dispatchSyncMode() === 'ecom_to_erp'
            ? $this->erpDriver()
            : $this->ecomDriver();
    }

    // FIX #9: channelLabel() no longer has hardcoded 'shopify' => 'Shopify'.
    // Any driver slug maps to its display name via ecomDisplayName/erpDisplayName.
    public function channelLabel(string $slug): string
    {
        if ($slug === $this->erpDriver() || in_array($slug, ['erp', ''])) {
            return $this->erpDisplayName();
        }

        if ($slug === $this->ecomDriver()) {
            return $this->ecomDisplayName();
        }

        // Amazon is a named secondary channel
        if ($slug === 'amazon') {
            return $this->get('amazon_display_name') ?: 'Amazon';
        }

        return ucfirst($slug);
    }

    // FIX #10: availableChannels() uses erpDriver() key, not hardcoded 'odoo'.
    public function availableChannels(): array
    {
        $channels = [
            $this->erpDriver()  => $this->erpDisplayName(),
            $this->ecomDriver() => $this->ecomDisplayName(),
        ];

        if ($this->isAmazonChannelEnabled()) {
            $channels['amazon'] = $this->get('amazon_display_name') ?: 'Amazon';
        }

        return $channels;
    }

    // ── Odoo credentials (used only inside OdooErpAdapter) ─────────────

    public function odooUrl(): string
    {
        return $this->get('odoo_url') ?? env('ODOO_URL', '');
    }

    public function odooDb(): string
    {
        return $this->get('odoo_db') ?? env('ODOO_DB', '');
    }

    public function odooUsername(): string
    {
        return $this->get('odoo_username') ?? env('ODOO_USERNAME', '');
    }

    public function odooApiKey(): string
    {
        return $this->get('odoo_api_key') ?? env('ODOO_API_KEY', '');
    }

    // ── Shopify credentials (used only inside ShopifyEcomAdapter) ───────

    public function shopifyShop(): string
    {
        return $this->get('shopify_shop') ?? env('SHOPIFY_SHOP', '');
    }
	
	public function shopifyVersion(): string
    {
        return $this->get('shopify_api_version') ?? env('shopify_api_version', '');
    }

    public function shopifyAccessToken(): string
    {
        return $this->get('shopify_access_token') ?? env('SHOPIFY_ACCESS_TOKEN', '');
    }

    public function shopifyWebhookSecret(): string
    {
        return $this->get('shopify_webhook_secret') ?? env('SHOPIFY_WEBHOOK_SECRET', '');
    }

    // ── Amazon credentials ─────────────────────────────────────────────

    public function amazonClientId(): string
    {
        return $this->get('amazon_client_id') ?? env('AMAZON_LWA_CLIENT_ID', '');
    }

    public function amazonClientSecret(): string
    {
        return $this->get('amazon_client_secret') ?? env('AMAZON_LWA_CLIENT_SECRET', '');
    }

    public function amazonRefreshToken(): string
    {
        return $this->get('amazon_refresh_token') ?? env('AMAZON_LWA_REFRESH_TOKEN', '');
    }

    public function amazonSellerId(): string
    {
        return $this->get('amazon_seller_id') ?? env('AMAZON_SELLER_ID', '');
    }

    public function amazonMarketplaceId(): string
    {
        return $this->get('amazon_marketplace_id') ?? env('AMAZON_MARKETPLACE_ID', 'ATVPDKIKX0DER');
    }

    public function odooLocationMap(): array
    {
        $raw = $this->get('odoo_location_map') ?? '{}';
        return json_decode($raw, true) ?? [];
    }

    // ── ERPNext credentials (used inside ErpNextErpAdapter) ─────────────

    public function erpnextUrl(): string
    {
        return rtrim($this->get('erpnext_url') ?? env('ERPNEXT_URL', ''), '/');
    }

    public function erpnextApiKey(): string
    {
        return $this->get('erpnext_api_key') ?? env('ERPNEXT_API_KEY', '');
    }

    public function erpnextApiSecret(): string
    {
        return $this->get('erpnext_api_secret') ?? env('ERPNEXT_API_SECRET', '');
    }

    public function erpnextTimeout(): int
    {
        return (int) ($this->get('erpnext_timeout') ?? env('ERPNEXT_TIMEOUT', 30));
    }

    public function erpnextCompany(): string
    {
        return $this->get('erpnext_company') ?? env('ERPNEXT_COMPANY', '');
    }

    public function erpnextWarehouse(): string
    {
        return $this->get('erpnext_warehouse') ?? env('ERPNEXT_WAREHOUSE', '');
    }

    public function erpnextSellingPriceList(): string
    {
        return trim($this->get('erpnext_selling_price_list') ?? env('ERPNEXT_SELLING_PRICE_LIST', 'Standard Selling'));
    }

    /** @return array<string, string> */
    public function erpnextWarehouseMap(): array
    {
        $raw = $this->get('erpnext_warehouse_map') ?? '{}';

        return json_decode($raw, true) ?? [];
    }
}
