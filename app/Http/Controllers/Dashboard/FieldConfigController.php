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

class FieldConfigController extends Controller
{
    private const FIELDS_DISK = 'local';

    public function __construct(private readonly SettingsService $settings) {}

    // ── Index — list field configs for a given entity type ───────────────

    public function index(string $entityType)
    {
        $entity = EntityDefinition::where('entity_type', $entityType)
            ->where('is_active', true)
            ->firstOrFail();

        $entities = EntityDefinition::where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $ecomDriver = $this->settings->ecomDriver();
        $erpDriver  = $this->settings->erpDriver();

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

        return view('dashboard.field-config.index', compact(
            'entity', 'entities', 'entityType',
            'configs', 'ecomFields', 'erpFields',
            'ecomFetchedAt', 'erpFetchedAt',
            'ecomDriver', 'erpDriver'
        ));
    }

    // ── Store ────────────────────────────────────────────────────────────

    public function store(Request $request, string $entityType)
    {
        $entity = EntityDefinition::where('entity_type', $entityType)->firstOrFail();

        $data = $request->validate([
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
            'sort_order'          => 'nullable|integer',
			'is_readonly' => 'boolean',
        ]);

        ProductFieldConfig::create(array_merge($data, [
            'entity_type' => $entityType,
            'ecom_driver' => $this->settings->ecomDriver(),
            'erp_driver'  => $this->settings->erpDriver(),
            'is_active'   => $request->boolean('is_active', true),
            'sort_order'  => $data['sort_order'] ?? 0,
        ]));

        return back()->with('success', 'Field mapping added.');
    }

    // ── Update ───────────────────────────────────────────────────────────

    public function update(Request $request, string $entityType, ProductFieldConfig $config)
    {
        $data = $request->validate([
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
            'sort_order'          => 'nullable|integer',
        ]);

        $config->update(array_merge($data, [
            'is_active' => $request->boolean('is_active', true),
        ]));

        return back()->with('success', 'Field mapping updated.');
    }

    // ── Destroy ──────────────────────────────────────────────────────────

    public function destroy(string $entityType, ProductFieldConfig $config)
    {
        $config->delete();
        return back()->with('success', 'Field mapping deleted.');
    }

    // ── Toggle ───────────────────────────────────────────────────────────

    public function toggle(string $entityType, ProductFieldConfig $config)
    {
        $config->update(['is_active' => !$config->is_active]);
        return back()->with('success', $config->is_active ? 'Enabled.' : 'Disabled.');
    }

    // ── Fetch ecom fields ────────────────────────────────────────────────

    public function fetchEcomFields(string $entityType)
    {
        $ecomDriver = $this->settings->ecomDriver();

        try {
            app(EcomInterface::class)->getProducts(['limit' => 1]);
        } catch (\Throwable $e) {
            Log::error("fetchEcomFields [{$ecomDriver}][{$entityType}]: " . $e->getMessage());
            return back()->with('error',
                "Could not connect to {$this->settings->ecomDisplayName()}: " . $e->getMessage()
            );
        }

        // Driver-neutral field discovery — each ecom adapter reports its own
        // fields via EcomInterface::getAvailableFields(). Grouped by scope so
        // the persisted JSON shape (template/variant/fields) is unchanged.
        $all  = app(EcomInterface::class)->getAvailableFields($entityType);
        $data = [
            'fetched_at'      => now()->toISOString(),
            'fields'          => $all,
            'template_fields' => array_values(array_filter($all, fn($f) => ($f['scope'] ?? '') === 'template')),
            'variant_fields'  => array_values(array_filter($all, fn($f) => ($f['scope'] ?? '') === 'variant')),
        ];

        Storage::disk(self::FIELDS_DISK)->put(
            $this->ecomFieldsFile($entityType),
            json_encode($data, JSON_PRETTY_PRINT)
        );

        return back()->with('success', "{$this->settings->ecomDisplayName()} fields updated for {$entityType}.");
    }

    // ── Fetch ERP fields ─────────────────────────────────────────────────

    public function fetchErpFields(string $entityType)
    {
        $erpDriver = $this->settings->erpDriver();
        $fields    = [];

        try {
            app(ErpInterface::class)->getAllActiveProducts(0, 1);
        } catch (\Throwable $e) {
            Log::error("fetchErpFields [{$erpDriver}][{$entityType}]: " . $e->getMessage());
            return back()->with('error',
                "Could not connect to {$this->settings->erpDisplayName()}: " . $e->getMessage()
            );
        }

        // Driver-neutral field discovery — the active ERP adapter reports its
        // own fields via ErpInterface::getAvailableFields() (Odoo introspects
        // via fields_get; other adapters return their own catalog).
        $fields = app(ErpInterface::class)->getAvailableFields($entityType);

        $data = ['fetched_at' => now()->toISOString(), 'fields' => $fields];

        Storage::disk(self::FIELDS_DISK)->put(
            $this->erpFieldsFile($entityType),
            json_encode($data, JSON_PRETTY_PRINT)
        );

        return back()->with('success',
            "{$this->settings->erpDisplayName()} fields fetched: " . count($fields) . " fields for {$entityType}."
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function ecomFieldsFile(string $entityType): string
    {
        return 'fields/' . $this->settings->ecomDriver() . '_' . $entityType . '_fields.json';
    }

    private function erpFieldsFile(string $entityType): string
    {
        return 'fields/' . $this->settings->erpDriver() . '_' . $entityType . '_fields.json';
    }

    private function loadEcomFields(string $entityType): array
    {
        $file = $this->ecomFieldsFile($entityType);
        if (!Storage::disk(self::FIELDS_DISK)->exists($file)) {
            return ['template_fields' => [], 'variant_fields' => []];
        }
        return json_decode(Storage::disk(self::FIELDS_DISK)->get($file), true) ?? [];
    }

    private function loadErpFields(string $entityType): array
    {
        $file = $this->erpFieldsFile($entityType);
        if (!Storage::disk(self::FIELDS_DISK)->exists($file)) {
            return ['fields' => []];
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
