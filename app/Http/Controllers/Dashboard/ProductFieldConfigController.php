<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\EntityDefinition;
use App\Models\ProductFieldConfig;
use App\Services\Config\FieldTransformRegistry;
use App\Services\Ecom\EcomInterface;
use App\Services\Erp\ErpInterface;
use App\Services\FieldMappingService;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProductFieldConfigController extends Controller
{
    private const FIELDS_DISK = 'local';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly FieldMappingService $fieldMapping,
    ) {}

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
        $entityType = $request->query('entity', 'product');

        $entities = EntityDefinition::where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->filter(fn ($entity) => $this->settings->isEntitySyncEnabled($entity->entity_type))
            ->values();

        if ($entities->isEmpty()) {
            abort(403, 'No sync entities are enabled in Global Settings.');
        }

        if (!$this->settings->isEntitySyncEnabled($entityType)) {
            $entityType = $entities->first()->entity_type;
        }

        $entity = $entities->firstWhere('entity_type', $entityType)
            ?? $entities->first();

        $ecomDriver = $this->settings->ecomDriver();
        $erpDriver  = $this->settings->erpDriver();

        $syncModeLabel    = $this->settings->syncModeLabel($entityType);
        $activeDirections = $this->settings->fieldConfigDirectionsForEntity($entityType);
        $defaultDirection = $this->settings->activeFieldConfigDirection($entityType);
        $directionLocked  = $this->settings->isFieldConfigDirectionLocked($entityType);
        $targetSide       = $defaultDirection === 'ecom_to_erp' ? 'erp' : 'ecom';

        // Only show configs matching Global Settings sync direction for this entity
        $configsQuery = ProductFieldConfig::where('entity_type', $entityType)
            ->where('ecom_driver', $ecomDriver)
            ->where('erp_driver', $erpDriver)
            ->where(function ($q) use ($entityType, $activeDirections) {
                $q->whereIn('direction', $activeDirections);

                // Legacy rows (direction NULL) still apply to dispatch/customer ecom→erp reads
                if (in_array('ecom_to_erp', $activeDirections, true)
                    && in_array($entityType, ['dispatch', 'customer'], true)) {
                    $q->orWhereNull('direction');
                }
            });

        $configs = $configsQuery->ordered()->paginate(50)->withQueryString();

        $transformOptions = FieldTransformRegistry::all();

        $ecomFields = $this->filterDiscoveredFieldsByDirection(
            $this->loadEcomFields($entityType),
            $defaultDirection,
        );
        $erpFields = $this->filterDiscoveredFieldsByDirection(
            $this->loadErpFields($entityType),
            $defaultDirection,
        );

        return view('dashboard.product-field-config.index', compact(
            'entity', 'entities', 'entityType',
            'configs', 'ecomFields', 'erpFields', 'targetSide',
            'ecomDriver', 'erpDriver', 'defaultDirection',
            'activeDirections', 'directionLocked', 'syncModeLabel',
            'transformOptions'
        ));
    }

    // ── Store ─────────────────────────────────────────────────────────────

    public function store(Request $request)
    {
        $data = $this->validateFieldConfigRequest($request);

        $entityType = $data['entity_type'] ?? 'product';
        $data['entity_type'] = $entityType;
        $data['direction']   = $this->resolveFieldConfigDirection($request, $entityType);
        $data['ecom_driver'] = $this->settings->ecomDriver();
        $data['erp_driver']  = $this->settings->erpDriver();
        $data['is_active']   = $request->boolean('is_active', true);
        $data['sort_order']  = $this->normalizeSortOrder($data['sort_order'] ?? null);

        ProductFieldConfig::create($data);
        $this->fieldMapping->clearCache();

        $entityType = $data['entity_type'];
        return redirect()
            ->route('dashboard.product-field-config.index', ['entity' => $entityType])
            ->with('success', 'Field mapping added.');
    }

    // ── Update ────────────────────────────────────────────────────────────

    public function update(Request $request, ProductFieldConfig $config)
    {
        $data = $this->validateFieldConfigRequest($request);

        $entityType = $config->entity_type ?? 'product';
        $data['direction'] = $this->resolveFieldConfigDirection($request, $entityType, $config->direction);
        $data['is_active']   = $request->boolean('is_active', true);
        $data['sort_order']  = $this->normalizeSortOrder($data['sort_order'] ?? $config->sort_order);

        $config->update($data);
        $this->fieldMapping->clearCache();

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
        $this->fieldMapping->clearCache();
        return redirect()
            ->route('dashboard.product-field-config.index', ['entity' => $entityType])
            ->with('success', 'Field mapping deleted.');
    }

    // ── Toggle ────────────────────────────────────────────────────────────

    public function toggle(ProductFieldConfig $config)
    {
        $config->update(['is_active' => !$config->is_active]);
        $this->fieldMapping->clearCache();
        $entityType = $config->entity_type ?? 'product';
        return redirect()
            ->route('dashboard.product-field-config.index', ['entity' => $entityType])
            ->with('success', $config->is_active ? 'Enabled.' : 'Disabled.');
    }

    // ── Fetch ecom fields ─────────────────────────────────────────────────

    public function fetchEcomFields(Request $request)
    {
        $entityType = $this->resolveEntityTypeFromRequest($request);
        $ecomDriver = $this->settings->ecomDriver();

        try {
            $all = app(EcomInterface::class)->getAvailableFields($entityType);
            if ($all === []) {
                return redirect()
                    ->route('dashboard.product-field-config.index', ['entity' => $entityType])
                    ->with('error', $this->ecomFieldsFetchHint($entityType));
            }

            $entityFields = [
                'template_fields' => array_values(array_filter($all, fn ($f) => ($f['scope'] ?? '') === 'template')),
                'variant_fields'  => array_values(array_filter($all, fn ($f) => ($f['scope'] ?? '') === 'variant')),
                'fields'          => array_values(array_filter($all, fn ($f) => !in_array($f['scope'] ?? '', ['template', 'variant'], true))),
            ];
        } catch (\Throwable $e) {
            Log::error("fetchEcomFields [{$ecomDriver}][{$entityType}]: " . $e->getMessage());
            return redirect()
                ->route('dashboard.product-field-config.index', ['entity' => $entityType])
                ->with('error', "Could not connect to {$this->settings->ecomDisplayName()}: " . $e->getMessage());
        }

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
        $entityType = $this->resolveEntityTypeFromRequest($request);
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

    private function resolveEntityTypeFromRequest(Request $request): string
    {
        return (string) ($request->input('entity') ?? $request->query('entity', 'product'));
    }

    /** @return array<string, mixed> */
    private function validateFieldConfigRequest(Request $request): array
    {
        $fieldType = $request->input('field_type');
        $direction = $request->input('direction');

        $data = $request->validate([
            'entity_type'         => 'nullable|string|max:50',
            'direction'           => 'nullable|in:erp_to_ecom,ecom_to_erp',
            'ecom_field'          => [
                Rule::requiredIf(fn () => !($fieldType === 'custom' && $direction === 'ecom_to_erp')),
                'nullable',
                'string',
                'max:100',
            ],
            'ecom_field_label'    => 'nullable|string|max:255',
            'ecom_field_2'        => 'nullable|string|max:100',
            'ecom_field_2_label'  => 'nullable|string|max:255',
            'ecom_api_path'       => 'nullable|string|max:255',
            'ecom_cast'           => 'nullable|string|max:50',
            'erp_field'           => [
                Rule::requiredIf(fn () => !($fieldType === 'custom' && $direction === 'erp_to_ecom')),
                'nullable',
                'string',
                'max:100',
            ],
            'erp_field_label'     => 'nullable|string|max:255',
            'erp_field_2'         => 'nullable|string|max:100',
            'erp_field_2_label'   => 'nullable|string|max:255',
            'field_type'          => 'required|in:default,custom,combine',
            'combine_separator'   => 'nullable|string|max:20',
            'scope'               => 'required|string|max:50',
            'default_value'       => ['nullable', 'string', 'max:500'],
            'conditions'          => 'nullable|string|max:2000',
            'transform'           => 'nullable|string|max:100',
            'transform_param'     => 'nullable|string|max:200',
            'min_length'          => 'nullable|integer|min:0',
            'max_length'          => 'nullable|integer|min:0',
            'is_active'           => 'boolean',
            'sort_order'          => 'nullable|integer|min:0',
        ]);

        if ($fieldType === 'custom' && $direction === 'ecom_to_erp') {
            $data['ecom_field'] = null;
            $data['ecom_field_label'] = null;
        }

        if ($fieldType === 'custom' && $direction === 'erp_to_ecom') {
            $data['erp_field'] = $data['erp_field'] ?: null;
            $data['erp_field_label'] = $data['erp_field_label'] ?: null;
        }

        if ($fieldType === 'combine' && $direction === 'ecom_to_erp') {
            $data['erp_field_2'] = null;
            $data['erp_field_2_label'] = null;
        }

        if ($fieldType === 'combine' && $direction === 'erp_to_ecom') {
            $data['ecom_field_2'] = null;
            $data['ecom_field_2_label'] = null;
        }

        $baseTransform = trim($request->input('transform_base') ?? '');
        $param         = trim($request->input('transform_param') ?? '');
        $data['transform'] = FieldTransformRegistry::build($baseTransform, $param !== '' ? $param : null);
        unset($data['transform_param']);

        if ($fieldType === 'custom') {
            $data['default_value'] = $this->normalizeCustomDefaultValue(
                $data['default_value'] ?? null,
                $data['transform'] ?? null,
                $data['ecom_cast'] ?? null
            );
        }

        return $data;
    }

    private function normalizeCustomDefaultValue(?string $default, ?string $transform, ?string $ecomCast): ?string
    {
        unset($ecomCast);

        if (trim($transform ?? '') !== '') {
            return null;
        }

        $default = trim((string) ($default ?? ''));

        if ($default === '' || in_array(strtolower($default), ['empty', 'null', 'none'], true)) {
            return null;
        }

        return $default;
    }

    private function normalizeSortOrder(mixed $value): int
    {
        return max(0, (int) $value);
    }

    private function resolveFieldConfigDirection(Request $request, string $entityType, ?string $fallback = null): string
    {
        if (!$this->settings->isFieldConfigDirectionLocked($entityType)) {
            $requested = trim((string) ($request->input('direction') ?? ''));

            if (in_array($requested, ['erp_to_ecom', 'ecom_to_erp'], true)) {
                return $requested;
            }
        }

        return $fallback ?? $this->settings->activeFieldConfigDirection($entityType);
    }

    /** @param  array<string, mixed>  $payload */
    private function filterDiscoveredFieldsByDirection(array $payload, string $direction): array
    {
        foreach (['fields', 'template_fields', 'variant_fields'] as $bucket) {
            if (!isset($payload[$bucket]) || !is_array($payload[$bucket])) {
                continue;
            }

            $payload[$bucket] = array_values(array_filter(
                $payload[$bucket],
                static function ($field) use ($direction) {
                    if (!is_array($field)) {
                        return false;
                    }

                    $directions = $field['directions'] ?? null;

                    return $directions === null
                        || $directions === []
                        || in_array($direction, $directions, true);
                },
            ));
        }

        return $payload;
    }

    private function ecomFieldsFetchHint(string $entityType): string
    {
        return match ($entityType) {
            'product' => 'No fields discovered. For ERP→Shopify, check GraphQL access. For Shopify→ERP, sync or fetch products first, then retry.',
            'sales_order' => 'No orders found in Shopify. Create a test order, then retry.',
            'customer' => 'No customers found in Shopify. Create a customer, then retry.',
            'dispatch' => 'No fulfillments found. Fulfill an order in Shopify, then retry.',
            'inventory' => 'No variants with inventory found. Add products with stock, then retry.',
            default => "No fields discovered for {$entityType} in {$this->settings->ecomDisplayName()}.",
        };
    }

}