<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\ConnectorSetting;
use App\Services\ConnectorRegistry;
use App\Services\ConnectorSettingsProvisioner;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

class SettingsController extends Controller
{
    /** Keys saved from the Global Settings direction cards. */
    private const SYNC_TOGGLE_KEYS = [
        'product_sync_enabled',
        'inventory_sync_enabled',
        'customer_sync_enabled',
        'sales_order_sync_enabled',
    ];

    private const SYNC_MODE_KEYS = [
        'product_sync_mode',
        'inventory_sync_mode',
        'customer_sync_mode',
        'sales_order_sync_mode',
    ];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly ConnectorRegistry $registry,
        private readonly ConnectorSettingsProvisioner $provisioner,
    ) {}

    /**
     * Global Settings — common settings, sync direction, Amazon.
     */
    public function index()
    {
        $groups = ConnectorSetting::where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->groupBy('group');

        return view('dashboard.settings', compact('groups'));
    }

    /**
     * ERP connection settings (Odoo, SAP, …) for the active erp_driver.
     */
    public function erp()
    {
        $driverGroup = $this->settings->erpDriver();

        if (!$this->registry->hasAdapter($driverGroup)) {
            return view('dashboard.settings.erp', [
                'settings'    => collect(),
                'driverGroup' => $driverGroup,
                'driverError' => "ERP driver [{$driverGroup}] is not registered in config/connectors.php.",
            ]);
        }

        $this->provisioner->ensureErpSettings($driverGroup);

        $settings = ConnectorSetting::where('is_active', true)
            ->where('group', $driverGroup)
            ->orderBy('sort_order')
            ->get();

        return view('dashboard.settings.erp', compact('settings', 'driverGroup'));
    }

    /**
     * E-commerce connection settings (Shopify, …) for the active ecom_driver.
     */
    public function ecom()
    {
        $driverGroup = $this->settings->ecomDriver();

        if ($this->registry->hasAdapter($driverGroup)) {
            $this->provisioner->ensureEcomSettings($driverGroup);
        }

        $settings = ConnectorSetting::where('is_active', true)
            ->where('group', $driverGroup)
            ->orderBy('sort_order')
            ->get();

        return view('dashboard.settings.ecom', compact('settings', 'driverGroup'));
    }

    /**
     * Save all settings from the global settings page in one POST.
     */
    public function update(Request $request)
    {
        $inputs = $request->except(['_token', '_method', '_settings_context']);
        $context = $request->input('_settings_context', 'global');

        if ($context === 'global' && isset($inputs['erp_driver'])) {
            $slug = strtolower(trim((string) $inputs['erp_driver']));
            $inputs['erp_driver'] = $slug;

            if ($slug === '' || !$this->registry->hasAdapter($slug)) {
                return redirect()->route('dashboard.settings')
                    ->with('error', $slug === ''
                        ? 'ERP Driver cannot be empty.'
                        : "ERP driver [{$slug}] is not registered. Add it to config/connectors.php first.")
                    ->withInput();
            }
        }

        if ($context === 'global' && isset($inputs['ecom_driver'])) {
            $slug = strtolower(trim((string) $inputs['ecom_driver']));
            $inputs['ecom_driver'] = $slug;

            if ($slug !== '' && !$this->registry->hasAdapter($slug)) {
                return redirect()->route('dashboard.settings')
                    ->with('error', "E-commerce driver [{$slug}] is not registered. Add it to config/connectors.php first.")
                    ->withInput();
            }
        }

        // Direction-card toggles exist only on Global Settings — do not turn them off
        // when saving ERP or E-commerce connection forms.
        if ($context === 'global') {
            foreach (self::SYNC_TOGGLE_KEYS as $key) {
                if (!array_key_exists($key, $inputs)) {
                    $inputs[$key] = '0';
                } else {
                    $inputs[$key] = $this->normalizeToggle($inputs[$key]);
                }
            }
        }

        foreach ($inputs as $key => $value) {
            if (in_array($key, self::SYNC_TOGGLE_KEYS, true)) {
                $value = $this->normalizeToggle($value);
            }

            $setting = ConnectorSetting::where('key', $key)->first();

            if (!$setting && in_array($key, array_merge(self::SYNC_TOGGLE_KEYS, self::SYNC_MODE_KEYS), true)) {
                $setting = $this->createSyncDirectionSetting($key, (string) $value);
            }

            if (!$setting) {
                continue;
            }

            if ($setting->is_secret && $this->shouldSkipSecretUpdate($setting, $value)) {
                continue;
            }

            if ($setting->is_secret) {
                $setting->value = trim((string) $value);
                $setting->saveQuietly();
            } else {
                $setting->update(['value' => $value]);
            }
        }

        $this->settings->clearCache();

        Cache::forget('connector_settings_all');

        return $this->redirectAfterUpdate($request);
    }

    private function normalizeToggle(mixed $value): string
    {
        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true) ? '1' : '0';
    }

    private function createSyncDirectionSetting(string $key, string $value): ConnectorSetting
    {
        $defaults = [
            'product_sync_enabled'    => ['label' => 'Enable Product Sync',       'field_type' => 'toggle',   'default' => '1'],
            'product_sync_mode'       => ['label' => 'Product Sync Direction',    'field_type' => 'sync_mode','default' => 'erp_to_ecom'],
            'inventory_sync_enabled'  => ['label' => 'Enable Inventory Sync',     'field_type' => 'toggle',   'default' => '1'],
            'inventory_sync_mode'     => ['label' => 'Inventory Sync Direction',  'field_type' => 'sync_mode','default' => 'erp_to_ecom'],
            'customer_sync_enabled'   => ['label' => 'Enable Customer Sync',      'field_type' => 'toggle',   'default' => '0'],
            'customer_sync_mode'      => ['label' => 'Customer Sync Direction',   'field_type' => 'sync_mode','default' => 'erp_to_ecom'],
            'sales_order_sync_enabled'=> ['label' => 'Enable Sales Order Sync',   'field_type' => 'toggle',   'default' => '1'],
            'sales_order_sync_mode'   => ['label' => 'Sales Order Sync Direction','field_type' => 'sync_mode','default' => 'erp_to_ecom'],
        ];

        $meta = $defaults[$key] ?? ['label' => $key, 'field_type' => 'text', 'default' => ''];

        return ConnectorSetting::create([
            'group'         => 'sync_direction',
            'key'           => $key,
            'label'         => $meta['label'],
            'value'         => $value !== '' ? $value : $meta['default'],
            'default_value' => $meta['default'],
            'field_type'    => $meta['field_type'],
            'is_secret'     => false,
            'is_active'     => true,
            'sort_order'    => 10,
        ]);
    }

    private function redirectAfterUpdate(Request $request): \Illuminate\Http\RedirectResponse
    {
        $message = match ($request->input('_settings_context', 'global')) {
            'erp'  => $this->settings->erpDisplayName() . ' settings saved successfully.',
            'ecom' => $this->settings->ecomDisplayName() . ' settings saved successfully.',
            default => 'Global settings saved successfully.',
        };

        $route = match ($request->input('_settings_context', 'global')) {
            'erp'  => 'dashboard.settings.erp',
            'ecom' => 'dashboard.settings.ecom',
            default => 'dashboard.settings',
        };

        return redirect()->route($route)->with('success', $message);
    }

    /**
     * Do not overwrite secrets when the field was left unchanged on the form.
     */
    private function shouldSkipSecretUpdate(ConnectorSetting $setting, mixed $value): bool
    {
        if ($value === null || trim((string) $value) === '') {
            return true;
        }

        $value = (string) $value;

        if (str_contains($value, '•') || str_contains($value, '****')) {
            return true;
        }

        $masked = $setting->getMaskedValue();
        if ($masked !== '' && $value === $masked) {
            return true;
        }

        if (preg_match('/^eyJ[A-Za-z0-9+\/=_-]+$/', $value)) {
            return true;
        }

        $current = $setting->getDecryptedValue();
        if ($current === null || $current === '') {
            return false;
        }

        if (hash_equals($current, $value)) {
            return true;
        }

        return false;
    }

    /**
     * Reveal a secret value — admin only.
     */
    public function reveal(Request $request, ConnectorSetting $setting)
    {
        abort_unless(auth()->user()->can('reveal-secrets'), 403);
        return response()->json(['value' => $setting->getDecryptedValue()]);
    }

    /**
     * Trigger a manual sync via Artisan.
     */
    public function triggerSync(Request $request)
    {
        abort_unless(auth()->user()->can('trigger-sync'), 403);

        $type = $request->input('type');

        $commandMap = [
            'products'         => 'sync:products',
            'inventory'        => 'sync:inventory',
            'orders'           => 'sync:orders',
            'customers'        => 'sync:customers',
            'dispatch'         => 'sync:dispatch',
            'all'              => 'sync:all',
            'amazon_products'  => 'sync:amazon-products',
            'amazon_orders'    => 'sync:amazon-orders',
            'amazon_inventory' => 'sync:amazon-inventory',
        ];

        if (!isset($commandMap[$type])) {
            return back()->with('error', 'Unknown sync type.');
        }

        try {
            Artisan::queue($commandMap[$type]);
            return back()->with('success', "Sync '{$type}' queued successfully.");
        } catch (\Throwable $e) {
            return back()->with('error', 'Failed to queue sync: ' . $e->getMessage());
        }
    }
}
