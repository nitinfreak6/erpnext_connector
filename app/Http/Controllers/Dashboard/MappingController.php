<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\ChannelMapping;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class MappingController extends Controller
{
    private array $validTypes;

    public function __construct()
    {
        $this->validTypes = array_keys(ChannelMapping::typeLabels());
    }

    /**
     * Show mappings for a given type.
     */
    public function index(Request $request, string $type): View
    {
        abort_unless(in_array($type, $this->validTypes), 404);

        $channel  = $request->query('channel', 'shopify');
        $search   = $request->query('search');
        $perPage  = (int) $request->query('per_page', 20);

        $query = ChannelMapping::ofType($type)
            ->when($channel !== 'all', fn ($q) => $q->forChannel($channel))
            ->when($search, fn ($q) => $q->where(function ($q) use ($search) {
                $q->where('odoo_label', 'like', "%{$search}%")
                  ->orWhere('external_label', 'like', "%{$search}%")
                  ->orWhere('odoo_id', 'like', "%{$search}%")
                  ->orWhere('external_id', 'like', "%{$search}%");
            }))
            ->orderBy('odoo_label');

        $mappings = $query->paginate($perPage)->withQueryString();
        $labels   = ChannelMapping::typeLabels();
        $icons    = ChannelMapping::typeIcons();

        return view('dashboard.mappings.index', compact(
            'type', 'channel', 'search', 'mappings', 'labels', 'icons', 'perPage'
        ));
    }

    /**
     * Store a new mapping.
     */
    public function store(Request $request, string $type): RedirectResponse
    {
        abort_unless(in_array($type, $this->validTypes), 404);

        $data = $request->validate([
            'channel'              => 'required|in:shopify,amazon,both',
            'odoo_id'              => 'required|string|max:100',
            'odoo_label'           => 'nullable|string|max:255',
            'external_id'          => 'required|string|max:100',
            'external_label'       => 'nullable|string|max:255',
            'odoo_value_field'     => 'nullable|string|max:255',
            'external_value_field' => 'nullable|string|max:255',
            'default_value'        => 'nullable|string|max:500',
            'min_length'           => 'nullable|string|max:50',
            'max_length'           => 'nullable|string|max:50',
            'is_active'            => 'boolean',
        ]);

        ChannelMapping::create([
            'type'          => $type,
            'channel'       => $data['channel'],
            'odoo_id'       => $data['odoo_id'],
            'odoo_label'    => $data['odoo_label'] ?? null,
            'external_id'   => $data['external_id'],
            'external_label'=> $data['external_label'] ?? null,
            'is_active'     => $request->boolean('is_active', true),
            'meta'          => array_filter([
                'odoo_value_field'     => $data['odoo_value_field']     ?? null,
                'external_value_field' => $data['external_value_field'] ?? null,
                'default_value'        => $data['default_value']        ?? null,
                'min_length'           => $data['min_length']           ?? null,
                'max_length'           => $data['max_length']           ?? null,
            ]),
        ]);

        return back()->with('success', 'Mapping added successfully.');
    }

    /**
     * Update an existing mapping.
     */
    public function update(Request $request, string $type, ChannelMapping $mapping): RedirectResponse
    {
        abort_unless($mapping->type === $type, 404);

        $data = $request->validate([
            'channel'              => 'required|in:shopify,amazon,both',
            'odoo_id'              => 'required|string|max:100',
            'odoo_label'           => 'nullable|string|max:255',
            'external_id'          => 'required|string|max:100',
            'external_label'       => 'nullable|string|max:255',
            'odoo_value_field'     => 'nullable|string|max:255',
            'external_value_field' => 'nullable|string|max:255',
            'default_value'        => 'nullable|string|max:500',
            'min_length'           => 'nullable|string|max:50',
            'max_length'           => 'nullable|string|max:50',
            'is_active'            => 'boolean',
        ]);

        $mapping->update([
            'channel'       => $data['channel'],
            'odoo_id'       => $data['odoo_id'],
            'odoo_label'    => $data['odoo_label'] ?? null,
            'external_id'   => $data['external_id'],
            'external_label'=> $data['external_label'] ?? null,
            'is_active'     => $request->boolean('is_active', true),
            'meta'          => array_filter([
                'odoo_value_field'     => $data['odoo_value_field']     ?? null,
                'external_value_field' => $data['external_value_field'] ?? null,
                'default_value'        => $data['default_value']        ?? null,
                'min_length'           => $data['min_length']           ?? null,
                'max_length'           => $data['max_length']           ?? null,
            ]),
        ]);

        return back()->with('success', 'Mapping updated.');
    }

    /**
     * Delete a mapping.
     */
    public function destroy(string $type, ChannelMapping $mapping): RedirectResponse
    {
        abort_unless($mapping->type === $type, 404);
        $mapping->delete();

        return back()->with('success', 'Mapping deleted.');
    }

    /**
     * Toggle active state.
     */
    public function toggle(string $type, ChannelMapping $mapping): RedirectResponse
    {
        abort_unless($mapping->type === $type, 404);
        $mapping->update(['is_active' => !$mapping->is_active]);

        return back()->with('success', $mapping->is_active ? 'Mapping enabled.' : 'Mapping disabled.');
    }

    /**
     * Bulk import via JSON paste.
     */
    public function import(Request $request, string $type): RedirectResponse
    {
        abort_unless(in_array($type, $this->validTypes), 404);

        $request->validate(['json_data' => 'required|string']);

        $rows = json_decode($request->input('json_data'), true);

        if (!is_array($rows)) {
            return back()->withErrors(['json_data' => 'Invalid JSON format.']);
        }

        $created = 0;
        foreach ($rows as $row) {
            if (empty($row['odoo_id']) || empty($row['external_id'])) continue;

            ChannelMapping::updateOrCreate(
                ['type' => $type, 'odoo_id' => $row['odoo_id'], 'channel' => $row['channel'] ?? 'shopify'],
                [
                    'odoo_label'     => $row['odoo_label'] ?? null,
                    'external_id'    => $row['external_id'],
                    'external_label' => $row['external_label'] ?? null,
                    'is_active'      => $row['is_active'] ?? true,
                ]
            );
            $created++;
        }

        return back()->with('success', "Imported {$created} mappings.");
    }


    // ── Odoo model per mapping type ──────────────────────────────────────
    private function odooModelForType(string $type): ?string
    {
        return match ($type) {
            ChannelMapping::TYPE_WAREHOUSE        => 'stock.location',
            ChannelMapping::TYPE_SHIPPING         => 'delivery.carrier',
            ChannelMapping::TYPE_CATEGORY         => 'product.category',
            ChannelMapping::TYPE_PRICELIST        => 'product.pricelist',
            ChannelMapping::TYPE_PAYMENT          => 'account.journal',
            ChannelMapping::TYPE_CHANNEL          => 'crm.team',
            ChannelMapping::TYPE_SALES_ORDER_TYPE => 'sale.order.type',
            ChannelMapping::TYPE_SALES_REP        => 'res.users',
            ChannelMapping::TYPE_PRODUCT_SIZE     => 'product.attribute.value',
            ChannelMapping::TYPE_TAX              => 'account.tax',
            default                               => null,
        };
    }

    // ── Shopify fields per mapping type ──────────────────────────────────
    private function shopifyFieldsForType(string $type): array
    {
        return match ($type) {
            ChannelMapping::TYPE_WAREHOUSE        => ['id' => 'Location ID', 'name' => 'Location Name', 'address1' => 'Address'],
            ChannelMapping::TYPE_SHIPPING         => ['title' => 'Shipping Title', 'price' => 'Price', 'code' => 'Code'],
            ChannelMapping::TYPE_CATEGORY         => ['product_type' => 'Product Type', 'tags' => 'Tags'],
            ChannelMapping::TYPE_PRICELIST        => ['currency' => 'Currency Code', 'presentment_currency' => 'Presentment Currency'],
            ChannelMapping::TYPE_PAYMENT          => ['gateway' => 'Gateway', 'payment_gateway_names' => 'Gateway Names'],
            ChannelMapping::TYPE_CHANNEL          => ['source_name' => 'Source Name', 'referring_site' => 'Referring Site'],
            ChannelMapping::TYPE_SALES_ORDER_TYPE => ['source_name' => 'Source Name', 'gateway' => 'Gateway'],
            ChannelMapping::TYPE_SALES_REP        => ['source_name' => 'Source Name'],
            ChannelMapping::TYPE_PRODUCT_SIZE     => ['value' => 'Option Value', 'name' => 'Option Name'],
            ChannelMapping::TYPE_TAX              => ['title' => 'Tax Title', 'rate' => 'Rate', 'price' => 'Tax Price'],
            default                               => [],
        };
    }

    /**
     * Fetch Odoo ERP fields for a mapping type.
     */
    public function fetchErpFields(Request $request, string $type): \Illuminate\Http\JsonResponse
    {
        abort_unless(in_array($type, $this->validTypes), 404);

        $model = $this->odooModelForType($type);
        if (!$model) {
            return response()->json(['fields' => [], 'label' => $type]);
        }

        try {
            $odoo   = app(\App\Services\Odoo\OdooService::class);
            $domain = match ($type) {
                ChannelMapping::TYPE_WAREHOUSE => [['usage', '=', 'internal']],
                ChannelMapping::TYPE_PAYMENT   => [['type', 'in', ['bank', 'cash', 'general']]],
                default                        => [],
            };
            $records = $odoo->searchRead($model, $domain, ['id', 'name', 'display_name'], ['limit' => 200]);
            $fields  = array_map(fn($r) => [
                'id'    => (string) $r['id'],
                'label' => $r['display_name'] ?? $r['name'] ?? "#{$r['id']}",
            ], $records);

            return response()->json(['fields' => $fields, 'model' => $model]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage(), 'fields' => []], 422);
        }
    }

    /**
     * Fetch Shopify/Ecom fields for a mapping type (static definitions).
     */
    public function fetchEcomFields(Request $request, string $type): \Illuminate\Http\JsonResponse
    {
        abort_unless(in_array($type, $this->validTypes), 404);

        $raw    = $this->shopifyFieldsForType($type);
        $fields = array_map(fn($key, $label) => ['key' => $key, 'label' => $label], array_keys($raw), array_values($raw));

        return response()->json(['fields' => $fields, 'type' => $type]);
    }

}