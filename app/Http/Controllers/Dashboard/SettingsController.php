<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\ConnectorSetting;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class SettingsController extends Controller
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * Global Settings page — all groups as expandable accordion sections.
     */
    public function index()
    {
        $groups = ConnectorSetting::where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->groupBy('group');

        // Section metadata — built from the connector registry so every
        // registered driver gets its settings section automatically.
        // Only the static 'general' section is hardcoded.
        $registry    = app(\App\Services\ConnectorRegistry::class);
        $sectionMeta = [
            'general' => [
                'label'       => 'Common Settings',
                'icon'        => '⚙️',
                'description' => 'ERP/Ecom driver selection and global sync feature flags',
                'color'       => 'indigo',
            ],
        ];

        foreach (array_merge($registry->erpDrivers(), $registry->ecomDrivers()) as $slug => $cfg) {
            $sectionMeta[$slug] = [
                'label'       => ($cfg['label'] ?? ucfirst($slug)) . ' Settings',
                'icon'        => $cfg['icon'] ?? '🔌',
                'description' => ($cfg['label'] ?? ucfirst($slug)) . ' connection credentials',
                'color'       => $cfg['color'] ?? 'slate',
            ];
        }

        return view('dashboard.settings', compact('groups', 'sectionMeta'));
    }

    /**
     * Save all settings from the global settings page in one POST.
     */
    public function update(Request $request)
    {
        $inputs = $request->except(['_token', '_method']);

        foreach ($inputs as $key => $value) {
            $setting = ConnectorSetting::where('key', $key)->first();
            if (!$setting) continue;

            // Skip blank secrets — keep existing value
            if ($setting->is_secret && ($value === '' || $value === null)) {
                continue;
            }

            if ($setting->is_secret && $value !== '' && $value !== null) {
                $setting->value = Crypt::encryptString($value);
                $setting->saveQuietly();
            } else {
                $setting->update(['value' => $value]);
            }
        }

        $this->settings->clearCache();

        // Also clear ERP driver cache so new driver takes effect immediately
        Cache::forget('connector_settings_all');

        return redirect()->route('dashboard.settings')
            ->with('success', 'Global settings saved successfully.');
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