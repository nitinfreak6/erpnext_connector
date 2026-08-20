<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\ChannelMapping;
use App\Services\Erp\ErpInterface;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class MappingController extends Controller
{
    private array $validTypes;

    public function __construct(private readonly SettingsService $settings)
    {
        $this->validTypes = array_keys(ChannelMapping::typeLabels());
    }

    /**
     * Show mappings for a given type.
     */
    public function index(Request $request, string $type): View
    {
        abort_unless(in_array($type, $this->validTypes), 404);
        $this->assertMappingTypeEnabled($type);

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
		
		$typeNoun = ucfirst($type); 

        return view('dashboard.mappings.index', compact(
            'type', 'channel', 'search', 'mappings', 'typeNoun', 'labels', 'icons', 'perPage'
        ));
    }

    /**
     * Store a new mapping.
     */
    public function store(Request $request, string $type): RedirectResponse
    {
        abort_unless(in_array($type, $this->validTypes), 404);
        $this->assertMappingTypeEnabled($type);

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
        $this->assertMappingTypeEnabled($type);

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
        $this->assertMappingTypeEnabled($type);
        $mapping->delete();

        return back()->with('success', 'Mapping deleted.');
    }

    /**
     * Toggle active state.
     */
    public function toggle(string $type, ChannelMapping $mapping): RedirectResponse
    {
        abort_unless($mapping->type === $type, 404);
        $this->assertMappingTypeEnabled($type);
        $mapping->update(['is_active' => !$mapping->is_active]);

        return back()->with('success', $mapping->is_active ? 'Mapping enabled.' : 'Mapping disabled.');
    }

    /**
     * Bulk import via JSON paste.
     */
    public function import(Request $request, string $type): RedirectResponse
    {
        abort_unless(in_array($type, $this->validTypes), 404);
        $this->assertMappingTypeEnabled($type);

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


    /**
     * Fetch ERP-side options for a mapping type (driver-agnostic via ErpInterface).
     */
    public function fetchErpFields(Request $request, string $type): \Illuminate\Http\JsonResponse
    {
        abort_unless(in_array($type, $this->validTypes), 404);
        $this->assertMappingTypeEnabled($type);

        try {
            $erp    = app(ErpInterface::class);
            $fields = $erp->getMappingOptions($type, $request->query('q'));

            return response()->json([
                'fields' => $fields,
                'driver' => $erp->driverName(),
                'type'   => $type,
            ]);
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
		$this->assertMappingTypeEnabled($type);
		$fields = app(\App\Services\Ecom\EcomInterface::class)
					->getMappingOptions($type, $request->query('q'));
		return response()->json(['fields' => $fields, 'type' => $type]);
	}

    private function assertMappingTypeEnabled(string $type): void
    {
        abort_unless($this->settings->isMappingTypeEnabled($type), 403);
    }

}