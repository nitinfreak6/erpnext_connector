<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\ChannelMapping;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Illuminate\Support\Facades\Cache;

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
		if ($type === 'product_field') {
			Cache::forget('product_field_mappings_shopify');
		}
        abort_unless(in_array($type, $this->validTypes), 404);

        $data = $request->validate([
            'channel'        => 'required|in:shopify,amazon,both',
            'odoo_id'        => 'required|string|max:100',
            'odoo_label'     => 'nullable|string|max:255',
            'external_id'    => 'required|string|max:100',
            'external_label' => 'nullable|string|max:255',
            'is_active'      => 'boolean',
        ]);

        ChannelMapping::create(array_merge($data, [
            'type'      => $type,
            'is_active' => $request->boolean('is_active', true),
        ]));

        return back()->with('success', 'Mapping added successfully.');
    }

    /**
     * Update an existing mapping.
     */
    public function update(Request $request, string $type, ChannelMapping $mapping): RedirectResponse
    {
		if ($type === 'product_field') {
			Cache::forget('product_field_mappings_shopify');
		}
        abort_unless($mapping->type === $type, 404);

        $data = $request->validate([
            'channel'        => 'required|in:shopify,amazon,both',
            'odoo_id'        => 'required|string|max:100',
            'odoo_label'     => 'nullable|string|max:255',
            'external_id'    => 'required|string|max:100',
            'external_label' => 'nullable|string|max:255',
            'is_active'      => 'boolean',
        ]);

        $mapping->update(array_merge($data, [
            'is_active' => $request->boolean('is_active', true),
        ]));

        return back()->with('success', 'Mapping updated.');
    }

    /**
     * Delete a mapping.
     */
    public function destroy(string $type, ChannelMapping $mapping): RedirectResponse
    {
		if ($type === 'product_field') {
			Cache::forget('product_field_mappings_shopify');
		}
        abort_unless($mapping->type === $type, 404);
        $mapping->delete();

        return back()->with('success', 'Mapping deleted.');
    }

    /**
     * Toggle active state.
     */
    public function toggle(string $type, ChannelMapping $mapping): RedirectResponse
    {
		if ($type === 'product_field') {
			Cache::forget('product_field_mappings_shopify');
		}
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
}