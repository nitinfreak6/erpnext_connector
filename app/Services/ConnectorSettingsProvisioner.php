<?php

namespace App\Services;

use App\Models\ConnectorSetting;
use Illuminate\Support\Str;

/**
 * Creates connector_settings rows for a driver from config/connectors.php.
 *
 * Each deployment uses one ERP + one e-commerce driver. When erp_driver is set
 * to e.g. erpnext, the ERP Settings page loads group=erpnext — this class
 * ensures those rows exist.
 */
class ConnectorSettingsProvisioner
{
    public function __construct(
        private readonly ConnectorRegistry $registry,
    ) {}

    /**
     * Ensure DB settings exist for an ERP driver slug (group = slug).
     */
    public function ensureErpSettings(string $driverSlug): void
    {
        $this->ensureDriverSettings($driverSlug, 'erp');
    }

    /**
     * Ensure DB settings exist for an e-commerce driver slug.
     */
    public function ensureEcomSettings(string $driverSlug): void
    {
        $this->ensureDriverSettings($driverSlug, 'ecom');
    }

    private function ensureDriverSettings(string $driverSlug, string $channel): void
    {
        $cfg = $this->registry->driver($driverSlug);

        if ($cfg === []) {
            return;
        }

        $credentials = $cfg['credentials'] ?? [];
        $meta        = $cfg['credential_meta'] ?? [];
        $driverLabel = $cfg['label'] ?? Str::title($driverSlug);
        $sort        = 1;

        foreach ($credentials as $key) {
            if (ConnectorSetting::where('key', $key)->exists()) {
                $sort++;
                continue;
            }

            $fieldMeta = $meta[$key] ?? [];
            $isSecret  = (bool) ($fieldMeta['is_secret'] ?? $this->guessSecret($key));

            ConnectorSetting::create([
                'group'         => $driverSlug,
                'key'           => $key,
                'label'         => $fieldMeta['label'] ?? $this->labelForKey($key, $driverLabel),
                'value'         => null,
                'default_value' => $fieldMeta['default_value'] ?? null,
                'description'   => $fieldMeta['description'] ?? null,
                'field_type'    => $fieldMeta['field_type'] ?? ($isSecret ? 'password' : 'text'),
                'is_secret'     => $isSecret,
                'is_active'     => true,
                'sort_order'    => $sort,
            ]);

            $sort++;
        }
    }

    private function labelForKey(string $key, string $driverLabel): string
    {
        $tail = Str::title(str_replace('_', ' ', preg_replace('/^[a-z]+_/', '', $key) ?? $key));

        return $tail !== '' ? $tail : $driverLabel;
    }

    private function guessSecret(string $key): bool
    {
        return (bool) preg_match('/(api_key|api_secret|secret|password|token)$/i', $key);
    }
}
