<?php

namespace App\Services;

use App\Models\ConnectorSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class SettingsService
{
    private const CACHE_KEY = 'connector_settings_all';
    private const CACHE_TTL = 300; // 5 minutes

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

    /**
     * Application name — shown in browser tab, header, emails.
     * Falls back to APP_NAME from .env.
     */
    public function appName(): string
    {
        return $this->get('app_name') ?: config('app.name', 'Connector');
    }

    /**
     * ERP display name — shown wherever the ERP label appears in the UI.
     * e.g. "Odoo Field", "Odoo ID" column headers in product field config.
     * Defaults to 'Odoo' for existing installs.
     */
    public function erpDisplayName(): string
    {
        return $this->get('erp_display_name') ?: 'Odoo';
    }

    // ── ERP driver ─────────────────────────────────────────────────────

    public function erpDriver(): string
    {
        return $this->get('erp_driver')
            ?? config('sync.erp_driver', env('ERP_DRIVER', 'odoo'));
    }

    // ── Sync master switches ───────────────────────────────────────────

    /**
     * Returns true only when the value is explicitly '1', 'true', 'yes', or 'on'.
     * Any other value (including null / empty string) is treated as DISABLED
     * so a missing DB row can't accidentally leave sync running.
     */
    private function isEnabled(string $key, bool $default = true): bool
    {
        $value = $this->get($key);

        if ($value === null || $value === '') {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public function isProductSyncEnabled(): bool
    {
        return $this->isEnabled('sync_products_enabled', true);
    }

    public function isInventorySyncEnabled(): bool
    {
        return $this->isEnabled('sync_inventory_enabled', true);
    }

    public function isOrderSyncEnabled(): bool
    {
        return $this->isEnabled('sync_orders_enabled', true);
    }

    public function isCustomerSyncEnabled(): bool
    {
        return $this->isEnabled('sync_customers_enabled', true);
    }

    public function isShopifyChannelEnabled(): bool
    {
        return $this->isEnabled('shopify_channel_enabled', true);
    }

    public function isAmazonChannelEnabled(): bool
    {
        return $this->isEnabled('amazon_channel_enabled', true);
    }

    // ── Odoo credentials ───────────────────────────────────────────────

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

    // ── Shopify credentials ────────────────────────────────────────────

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