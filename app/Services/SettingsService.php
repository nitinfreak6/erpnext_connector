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

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
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
        return $this->get('inventory_sync_mode') ?: 'erp_to_ecom';
    }

    public function dispatchSyncMode(): string
    {
        return $this->get('dispatch_sync_mode') ?: 'ecom_to_erp';
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
        if ($stored) return $stored;
        // Default: ecom sends fulfillment → erp
        return $this->ecomDriver();
    }

    /** Channel to push dispatch confirmations TO. */
    public function dispatchPostTo(): string
    {
        $stored = $this->get('dispatch_post_to');
        if ($stored) return $stored;
        return $this->erpDriver();
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
}
