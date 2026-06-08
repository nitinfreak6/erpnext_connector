<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\EntityDefinition;
use App\Models\ProductFieldConfig;
use App\Services\Ecom\EcomInterface;
use App\Services\Erp\ErpInterface;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProductFieldConfigController extends Controller
{
    private const FIELDS_DISK = 'local';

    public function __construct(private readonly SettingsService $settings) {}

    // ── File path helpers — per driver + entity type ─────────────────────

    private function ecomFieldsFile(string $entityType = 'product'): string
    {
        return 'fields/' . $this->settings->ecomDriver() . '_' . $entityType . '_fields.json';
    }

    private function erpFieldsFile(string $entityType = 'product'): string
    {
        return 'fields/' . $this->settings->erpDriver() . '_' . $entityType . '_fields.json';
    }

    // ── Index ─────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        // Active entity type — defaults to 'product' so existing bookmarks work
        $entityType = $request->query('entity', 'product');

        // All active entities for the tab bar — driven from entity_definitions
        $entities = EntityDefinition::where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        // Current entity definition (for scopes etc.)
        $entity = $entities->firstWhere('entity_type', $entityType)
            ?? $entities->first();

        $ecomDriver = $this->settings->ecomDriver();
        $erpDriver  = $this->settings->erpDriver();

        // Only show configs for the selected entity type + active driver pair
        $configs = ProductFieldConfig::where('entity_type', $entityType)
            ->where('ecom_driver', $ecomDriver)
            ->where('erp_driver', $erpDriver)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString();

        $ecomFields    = $this->loadEcomFields($entityType);
        $erpFields     = $this->loadErpFields($entityType);
        $ecomFetchedAt = $this->fieldsFetchedAt($this->ecomFieldsFile($entityType));
        $erpFetchedAt  = $this->fieldsFetchedAt($this->erpFieldsFile($entityType));

        return view('dashboard.product-field-config.index', compact(
            'entity', 'entities', 'entityType',
            'configs', 'ecomFields', 'erpFields',
            'ecomFetchedAt', 'erpFetchedAt',
            'ecomDriver', 'erpDriver'
        ));
    }

    // ── Store ─────────────────────────────────────────────────────────────

    public function store(Request $request)
    {
        $data = $request->validate([
            'entity_type'         => 'nullable|string|max:50',
            'ecom_field'          => 'required|string|max:100',
            'ecom_field_label'    => 'nullable|string|max:255',
            'erp_field'           => 'nullable|string|max:100',
            'erp_field_label'     => 'nullable|string|max:255',
            'erp_field_2'         => 'nullable|string|max:100',
            'erp_field_2_label'   => 'nullable|string|max:255',
            'field_type'          => 'required|in:default,custom,combine',
            'combine_separator'   => 'nullable|string|max:20',
            'scope'               => 'required|string|max:50',
            'default_value'       => 'nullable|string|max:500',
            'transform'           => 'nullable|string|max:50',
            'min_length'          => 'nullable|integer|min:0',
            'max_length'          => 'nullable|integer|min:0',
            'is_active'           => 'boolean',
			'is_readonly'         => 'boolean',
            'sort_order'          => 'nullable|integer',
        ]);

        $data['entity_type'] = $data['entity_type'] ?? 'product';
        $data['ecom_driver'] = $this->settings->ecomDriver();
        $data['erp_driver']  = $this->settings->erpDriver();
        $data['is_active']   = $request->boolean('is_active', true);
        $data['is_readonly'] = $request->boolean('is_readonly', false);
		
        $data['sort_order']  = $data['sort_order'] ?? 0;

        ProductFieldConfig::create($data);

        $entityType = $data['entity_type'];
        return redirect()
            ->route('dashboard.product-field-config.index', ['entity' => $entityType])
            ->with('success', 'Field mapping added.');
    }

    // ── Update ────────────────────────────────────────────────────────────

    public function update(Request $request, ProductFieldConfig $config)
    {
        $data = $request->validate([
            'entity_type'         => 'nullable|string|max:50',
            'ecom_field'          => 'required|string|max:100',
            'ecom_field_label'    => 'nullable|string|max:255',
            'erp_field'           => 'nullable|string|max:100',
            'erp_field_label'     => 'nullable|string|max:255',
            'erp_field_2'         => 'nullable|string|max:100',
            'erp_field_2_label'   => 'nullable|string|max:255',
            'field_type'          => 'required|in:default,custom,combine',
            'combine_separator'   => 'nullable|string|max:20',
            'scope'               => 'required|string|max:50',
            'default_value'       => 'nullable|string|max:500',
            'transform'           => 'nullable|string|max:50',
            'min_length'          => 'nullable|integer|min:0',
            'max_length'          => 'nullable|integer|min:0',
            'is_active'           => 'boolean',
			'is_readonly'         => 'boolean',
            'sort_order'          => 'nullable|integer',
        ]);

        $data['is_active'] = $request->boolean('is_active', true);
        $data['is_readonly'] = $request->boolean('is_readonly', false);

        $config->update($data);

        $entityType = $config->entity_type ?? 'product';
        return redirect()
            ->route('dashboard.product-field-config.index', ['entity' => $entityType])
            ->with('success', 'Field mapping updated.');
    }

    // ── Destroy ───────────────────────────────────────────────────────────

    public function destroy(ProductFieldConfig $config)
    {
        $entityType = $config->entity_type ?? 'product';
        $config->delete();
        return redirect()
            ->route('dashboard.product-field-config.index', ['entity' => $entityType])
            ->with('success', 'Field mapping deleted.');
    }

    // ── Toggle ────────────────────────────────────────────────────────────

    public function toggle(ProductFieldConfig $config)
    {
        $config->update(['is_active' => !$config->is_active]);
        $entityType = $config->entity_type ?? 'product';
        return redirect()
            ->route('dashboard.product-field-config.index', ['entity' => $entityType])
            ->with('success', $config->is_active ? 'Enabled.' : 'Disabled.');
    }

    // ── Fetch ecom fields ─────────────────────────────────────────────────

    public function fetchEcomFields(Request $request)
    {
        $entityType = $request->query('entity', 'product');
        $ecomDriver = $this->settings->ecomDriver();

        try {
            app(EcomInterface::class)->getProducts(['limit' => 1]);
        } catch (\Throwable $e) {
            Log::error("fetchEcomFields [{$ecomDriver}][{$entityType}]: " . $e->getMessage());
            return redirect()
                ->route('dashboard.product-field-config.index', ['entity' => $entityType])
                ->with('error', "Could not connect to {$this->settings->ecomDisplayName()}: " . $e->getMessage());
        }

        // Driver-neutral field discovery — the active ecom adapter reports its
        // own fields via EcomInterface::getAvailableFields(), grouped by scope
        // so the persisted JSON shape (template/variant/fields) is unchanged.
        $all          = app(EcomInterface::class)->getAvailableFields($entityType);
        $entityFields = [
            'template_fields' => array_values(array_filter($all, fn($f) => ($f['scope'] ?? '') === 'template')),
            'variant_fields'  => array_values(array_filter($all, fn($f) => ($f['scope'] ?? '') === 'variant')),
            'fields'          => array_values(array_filter($all, fn($f) => !in_array($f['scope'] ?? '', ['template', 'variant'], true))),
        ];

        $data = array_merge(['fetched_at' => now()->toISOString()], $entityFields);

        Storage::disk(self::FIELDS_DISK)->put(
            $this->ecomFieldsFile($entityType),
            json_encode($data, JSON_PRETTY_PRINT)
        );

        return redirect()
            ->route('dashboard.product-field-config.index', ['entity' => $entityType])
            ->with('success', "{$this->settings->ecomDisplayName()} fields updated for {$entityType}.");
    }

    // ── Fetch ERP fields ──────────────────────────────────────────────────

    public function fetchErpFields(Request $request)
    {
        $entityType = $request->query('entity', 'product');
        $erpDriver  = $this->settings->erpDriver();
        $fields     = [];

        try {
            app(ErpInterface::class)->getAllActiveProducts(0, 1);
        } catch (\Throwable $e) {
            Log::error("fetchErpFields [{$erpDriver}][{$entityType}]: " . $e->getMessage());
            return redirect()
                ->route('dashboard.product-field-config.index', ['entity' => $entityType])
                ->with('error', "Could not connect to {$this->settings->erpDisplayName()}: " . $e->getMessage());
        }

        // Driver-neutral field discovery — the active ERP adapter reports its
        // own header+line fields via ErpInterface::getAvailableFields().
        $fields = app(ErpInterface::class)->getAvailableFields($entityType);

        $data = ['fetched_at' => now()->toISOString(), 'fields' => $fields];

        Storage::disk(self::FIELDS_DISK)->put(
            $this->erpFieldsFile($entityType),
            json_encode($data, JSON_PRETTY_PRINT)
        );

        return redirect()
            ->route('dashboard.product-field-config.index', ['entity' => $entityType])
            ->with('success', "{$this->settings->erpDisplayName()} fields fetched: " . count($fields) . " fields.");
    }

    // ── Private helpers ───────────────────────────────────────────────────

    private function loadEcomFields(string $entityType): array
    {
        $file = $this->ecomFieldsFile($entityType);
        if (!Storage::disk(self::FIELDS_DISK)->exists($file)) {
            // Backwards compat: try old product-only filename for existing installs
            if ($entityType === 'product') {
                $oldFile = 'fields/' . $this->settings->ecomDriver() . '_product_fields.json';
                if (Storage::disk(self::FIELDS_DISK)->exists($oldFile)) {
                    return json_decode(Storage::disk(self::FIELDS_DISK)->get($oldFile), true) ?? [];
                }
            }
            return ['template_fields' => [], 'variant_fields' => [], 'fields' => []];
        }
        return json_decode(Storage::disk(self::FIELDS_DISK)->get($file), true) ?? [];
    }

    private function loadErpFields(string $entityType): array
    {
        $file = $this->erpFieldsFile($entityType);
        if (!Storage::disk(self::FIELDS_DISK)->exists($file)) {
            if ($entityType === 'product') {
                $oldFile = 'fields/' . $this->settings->erpDriver() . '_product_fields.json';
                if (Storage::disk(self::FIELDS_DISK)->exists($oldFile)) {
                    return json_decode(Storage::disk(self::FIELDS_DISK)->get($oldFile), true) ?? [];
                }
            }
            return ['fields' => [], 'template_fields' => [], 'variant_fields' => []];
        }
        return json_decode(Storage::disk(self::FIELDS_DISK)->get($file), true) ?? [];
    }

    private function fieldsFetchedAt(string $path): ?string
    {
        if (!Storage::disk(self::FIELDS_DISK)->exists($path)) return null;
        $data = json_decode(Storage::disk(self::FIELDS_DISK)->get($path), true);
        return $data['fetched_at'] ?? null;
    }

}
