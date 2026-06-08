<?php

namespace App\Services;

/**
 * Typed accessor over config/connectors.php.
 *
 * Replaces the per-driver arrays that used to be inlined in AppServiceProvider,
 * ProductsController, ProductCacheController, SyncPushProductsCommand,
 * OrdersController, InventoryController, OverviewController and SettingsController.
 *
 * Nothing in this class hardcodes 'odoo' / 'shopify' / 'amazon' — every value
 * comes from the registry config.
 */
class ConnectorRegistry
{
    /** @return array<string,array> */
    public function erpDrivers(): array
    {
        return config('connectors.erp', []);
    }

    /** @return array<string,array> */
    public function ecomDrivers(): array
    {
        return config('connectors.ecom', []);
    }

    /**
     * ERP/Ecom drivers selectable as the *primary* driver in Settings.
     * Excludes stubs (enabled=false) and side-channels (is_channel=true).
     *
     * @return array<string,string> slug => label
     */
    public function selectableErp(): array
    {
        return $this->selectable($this->erpDrivers());
    }

    /** @return array<string,string> slug => label */
    public function selectableEcom(): array
    {
        return $this->selectable($this->ecomDrivers());
    }

    /** @return array<string,string> */
    private function selectable(array $drivers): array
    {
        $out = [];
        foreach ($drivers as $slug => $cfg) {
            if (($cfg['enabled'] ?? true) === false) {
                continue;
            }
            if (($cfg['is_channel'] ?? false) === true) {
                continue;
            }
            $out[$slug] = $cfg['label'] ?? ucfirst($slug);
        }
        return $out;
    }

    /**
     * Adapter FQCN for a driver, or null if none (e.g. Amazon side-channel).
     */
    public function adapterClass(string $slug): ?string
    {
        return $this->driver($slug)['adapter'] ?? null;
    }

    /**
     * Resolve a logical job (e.g. 'push_product') for a driver.
     * Returns null when the driver has no such job wired — callers should
     * surface a clear "not registered" message, same behaviour as before.
     */
    public function job(string $slug, string $jobKey): ?string
    {
        return $this->driver($slug)['jobs'][$jobKey] ?? null;
    }

    /**
     * SyncMapping entity_types belonging to a channel/driver.
     * Used by the Orders / Inventory / Overview filters.
     *
     * @param  string      $slug     driver/channel slug
     * @param  string|null $category 'product'|'order'|'customer'|'inventory'; null = all
     * @return string[]
     */
    public function entityTypes(string $slug, ?string $category = null): array
    {
        $types = $this->driver($slug)['entity_types'] ?? [];

        if ($category !== null) {
            return $types[$category] ?? [];
        }

        // Flatten every category for this driver.
        return array_values(array_merge(...array_values($types) ?: [[]]));
    }

    /**
     * Union of a category's entity_types across every channel — used for the
     * "All channels" default in the Orders / Inventory filters.
     *
     * @return string[]
     */
    public function allEntityTypesForCategory(string $category): array
    {
        $all = [];
        foreach (array_merge($this->erpDrivers(), $this->ecomDrivers()) as $cfg) {
            foreach (($cfg['entity_types'][$category] ?? []) as $type) {
                $all[$type] = true;
            }
        }
        return array_keys($all);
    }

    /**
     * Credential setting keys for a driver — drives the Settings page section
     * and save-time validation.
     *
     * @return string[]
     */
    public function credentials(string $slug): array
    {
        return $this->driver($slug)['credentials'] ?? [];
    }

    public function label(string $slug): string
    {
        return $this->driver($slug)['label'] ?? ucfirst($slug);
    }

    /** @return array{signature_header?:string,id_header?:string} */
    public function webhookConfig(string $slug): array
    {
        return $this->driver($slug)['webhooks'] ?? [];
    }

    public function isChannel(string $slug): bool
    {
        return ($this->driver($slug)['is_channel'] ?? false) === true;
    }

    /**
     * Look up a driver block by slug across both erp and ecom.
     *
     * @return array<string,mixed>
     */
    public function driver(string $slug): array
    {
        return $this->erpDrivers()[$slug]
            ?? $this->ecomDrivers()[$slug]
            ?? [];
    }

    public function hasDriver(string $slug): bool
    {
        return $this->driver($slug) !== [];
    }
}
