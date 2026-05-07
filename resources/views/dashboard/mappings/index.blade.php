@extends('dashboard.layout')

@section('title', $labels[$type] ?? 'Mappings')
@section('page-title', $labels[$type] ?? 'Mappings')

@section('content')
<div x-data="{
    showAdd: false,
    showImport: false,
    editId: null,
    editData: {},
    openEdit(mapping) {
        this.editId = mapping.id;
        this.editData = { ...mapping };
    },
    closeEdit() { this.editId = null; this.editData = {}; }
}">

{{-- ── Page header ── --}}
<div class="flex items-center justify-between mb-6">
    <div>
        <p class="text-sm text-gray-500 mt-0.5">
            Map Odoo values to their equivalents in
            <span class="font-medium text-indigo-600">Shopify</span> /
            <span class="font-medium text-orange-500">Amazon</span>
        </p>
    </div>
    <div class="flex items-center gap-2">
        <button @click="showImport = !showImport"
                class="inline-flex items-center gap-1.5 text-sm border border-gray-300 text-gray-700 hover:bg-gray-50 px-3 py-1.5 rounded-lg transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
            </svg>
            Import JSON
        </button>
        <button @click="showAdd = !showAdd"
                class="inline-flex items-center gap-1.5 text-sm bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1.5 rounded-lg transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Add Mapping
        </button>
    </div>
</div>

{{-- ── Add form (collapsible) ── --}}
<div x-show="showAdd" x-cloak x-transition
     class="bg-indigo-50 border border-indigo-200 rounded-xl p-5 mb-5">
    <h3 class="text-sm font-semibold text-indigo-800 mb-4">Add New Mapping</h3>
    <form method="POST" action="{{ route('dashboard.mappings.store', $type) }}" class="grid grid-cols-2 gap-3 md:grid-cols-5">
        @csrf
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Channel</label>
            <select name="channel" class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none">
                <option value="shopify">Shopify</option>
                <option value="amazon">Amazon</option>
                <option value="both">Both</option>
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Odoo ID</label>
            <input type="text" name="odoo_id" placeholder="e.g. 5"
                   class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Odoo Label</label>
            <input type="text" name="odoo_label" placeholder="e.g. WH/Stock"
                   class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">External ID</label>
            <input type="text" name="external_id" placeholder="e.g. 69188747343"
                   class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">External Label</label>
            <input type="text" name="external_label" placeholder="e.g. Main Warehouse"
                   class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none">
        </div>
        <div class="col-span-2 md:col-span-5 flex items-center justify-between pt-1">
            <label class="flex items-center gap-2 text-sm text-gray-600 cursor-pointer">
                <input type="checkbox" name="is_active" value="1" checked class="rounded text-indigo-600">
                Active
            </label>
            <div class="flex gap-2">
                <button type="button" @click="showAdd = false"
                        class="text-sm text-gray-500 hover:text-gray-700 px-3 py-1.5">Cancel</button>
                <button type="submit"
                        class="text-sm bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-1.5 rounded-lg transition">
                    Save Mapping
                </button>
            </div>
        </div>
    </form>
</div>

{{-- ── Import form (collapsible) ── --}}
<div x-show="showImport" x-cloak x-transition
     class="bg-amber-50 border border-amber-200 rounded-xl p-5 mb-5">
    <h3 class="text-sm font-semibold text-amber-800 mb-2">Bulk Import via JSON</h3>
    <p class="text-xs text-amber-700 mb-3">Paste a JSON array. Each object needs <code>odoo_id</code>, <code>external_id</code>, and optionally <code>channel</code>, <code>odoo_label</code>, <code>external_label</code>.</p>
    <form method="POST" action="{{ route('dashboard.mappings.import', $type) }}">
        @csrf
        <textarea name="json_data" rows="5" placeholder='[{"odoo_id":"5","odoo_label":"WH/Stock","external_id":"69188747343","external_label":"Main Warehouse","channel":"shopify"}]'
                  class="w-full text-sm font-mono border border-amber-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-amber-300 outline-none bg-white"></textarea>
        @error('json_data')
            <p class="text-red-600 text-xs mt-1">{{ $message }}</p>
        @enderror
        <div class="flex justify-end gap-2 mt-2">
            <button type="button" @click="showImport = false"
                    class="text-sm text-gray-500 hover:text-gray-700 px-3 py-1.5">Cancel</button>
            <button type="submit"
                    class="text-sm bg-amber-600 hover:bg-amber-700 text-white px-4 py-1.5 rounded-lg transition">
                Import
            </button>
        </div>
    </form>
</div>

{{-- ── Filters ── --}}
<div class="bg-white rounded-xl border border-gray-200 p-4 mb-4 flex flex-wrap items-center gap-3">
    <form method="GET" action="{{ route('dashboard.mappings.index', $type) }}" class="flex flex-wrap items-center gap-3 flex-1">
        {{-- Channel filter --}}
        <div class="flex items-center gap-1 bg-gray-100 rounded-lg p-1">
            @foreach(['all' => 'All', 'shopify' => 'Shopify', 'amazon' => 'Amazon'] as $val => $lbl)
            <button type="submit" name="channel" value="{{ $val }}"
                    class="text-xs px-3 py-1 rounded-md transition font-medium
                           {{ $channel === $val ? 'bg-white shadow text-indigo-700' : 'text-gray-500 hover:text-gray-700' }}">
                {{ $lbl }}
            </button>
            @endforeach
        </div>

        {{-- Search --}}
        <div class="flex items-center gap-2 flex-1 min-w-0">
            <div class="relative flex-1 max-w-sm">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <input type="text" name="search" value="{{ $search }}" placeholder="Search mappings..."
                       class="w-full text-sm border border-gray-300 rounded-lg pl-9 pr-3 py-2 focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none">
            </div>
            <button type="submit" class="text-sm bg-gray-800 text-white px-3 py-2 rounded-lg hover:bg-gray-900 transition">Search</button>
            @if($search)
            <a href="{{ route('dashboard.mappings.index', $type) }}"
               class="text-sm text-gray-400 hover:text-gray-600 transition">Clear</a>
            @endif
        </div>
    </form>

    <span class="text-xs text-gray-400">{{ $mappings->total() }} total</span>
</div>

{{-- ── Table ── --}}
<div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
    @if($mappings->isEmpty())
        <div class="py-16 text-center">
            <svg class="w-10 h-10 text-gray-300 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 10a2 2 0 002 2h2a2 2 0 002-2V7a2 2 0 00-2-2h-2a2 2 0 00-2 2"/>
            </svg>
            <p class="text-sm text-gray-400 font-medium">No mappings yet</p>
            <p class="text-xs text-gray-300 mt-1">Click "Add Mapping" to create your first one</p>
        </div>
    @else
    <table class="w-full text-sm">
        <thead>
            <tr class="bg-gray-50 border-b border-gray-200">
                <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Channel</th>
                <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Odoo ID</th>
                <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Odoo Label</th>
                <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">
                    <svg class="w-3 h-3 inline mr-1 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                    </svg>
                </th>
                <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">External ID</th>
                <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">External Label</th>
                <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                <th class="text-right px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @foreach($mappings as $mapping)
            <tr class="hover:bg-gray-50 transition {{ $mapping->is_active ? '' : 'opacity-50' }}"
                x-show="editId !== {{ $mapping->id }}">

                {{-- Channel badge --}}
                <td class="px-4 py-3">
                    @if($mapping->channel === 'shopify')
                        <span class="badge bg-green-100 text-green-700">Shopify</span>
                    @elseif($mapping->channel === 'amazon')
                        <span class="badge bg-orange-100 text-orange-700">Amazon</span>
                    @else
                        <span class="badge bg-indigo-100 text-indigo-700">Both</span>
                    @endif
                </td>

                {{-- Odoo ID --}}
                <td class="px-4 py-3 font-mono text-xs text-gray-700">{{ $mapping->odoo_id }}</td>

                {{-- Odoo Label --}}
                <td class="px-4 py-3 text-gray-800 font-medium">{{ $mapping->odoo_label ?: '—' }}</td>

                {{-- Arrow --}}
                <td class="px-4 py-3 text-gray-300 text-center">→</td>

                {{-- External ID --}}
                <td class="px-4 py-3 font-mono text-xs text-gray-700">{{ $mapping->external_id }}</td>

                {{-- External Label --}}
                <td class="px-4 py-3 text-gray-800">{{ $mapping->external_label ?: '—' }}</td>

                {{-- Status --}}
                <td class="px-4 py-3">
                    <form method="POST" action="{{ route('dashboard.mappings.toggle', [$type, $mapping]) }}">
                        @csrf @method('PATCH')
                        <button type="submit"
                                class="badge cursor-pointer transition
                                       {{ $mapping->is_active ? 'bg-emerald-100 text-emerald-700 hover:bg-red-100 hover:text-red-600' : 'bg-gray-100 text-gray-500 hover:bg-emerald-100 hover:text-emerald-600' }}">
                            {{ $mapping->is_active ? 'Active' : 'Disabled' }}
                        </button>
                    </form>
                </td>

                {{-- Actions --}}
                <td class="px-4 py-3 text-right">
                    <div class="flex items-center justify-end gap-2">
                        <button @click="openEdit({{ json_encode(['id' => $mapping->id, 'channel' => $mapping->channel, 'odoo_id' => $mapping->odoo_id, 'odoo_label' => $mapping->odoo_label, 'external_id' => $mapping->external_id, 'external_label' => $mapping->external_label]) }})"
                                class="text-indigo-500 hover:text-indigo-700 transition p-1 rounded hover:bg-indigo-50" title="Edit">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                            </svg>
                        </button>
                        <form method="POST" action="{{ route('dashboard.mappings.destroy', [$type, $mapping]) }}"
                              onsubmit="return confirm('Delete this mapping?')">
                            @csrf @method('DELETE')
                            <button type="submit"
                                    class="text-red-400 hover:text-red-600 transition p-1 rounded hover:bg-red-50" title="Delete">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                </svg>
                            </button>
                        </form>
                    </div>
                </td>
            </tr>

            {{-- Inline edit row --}}
            <tr x-show="editId === {{ $mapping->id }}" x-cloak class="bg-indigo-50">
                <td colspan="8" class="px-4 py-3">
                    <form method="POST" action="{{ route('dashboard.mappings.update', [$type, $mapping]) }}"
                          class="grid grid-cols-2 gap-2 md:grid-cols-6 items-end">
                        @csrf @method('PUT')
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Channel</label>
                            <select name="channel" x-model="editData.channel"
                                    class="w-full text-sm border border-gray-300 rounded-lg px-2 py-1.5 focus:ring-2 focus:ring-indigo-300 outline-none">
                                <option value="shopify">Shopify</option>
                                <option value="amazon">Amazon</option>
                                <option value="both">Both</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Odoo ID</label>
                            <input type="text" name="odoo_id" x-model="editData.odoo_id"
                                   class="w-full text-sm border border-gray-300 rounded-lg px-2 py-1.5 focus:ring-2 focus:ring-indigo-300 outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Odoo Label</label>
                            <input type="text" name="odoo_label" x-model="editData.odoo_label"
                                   class="w-full text-sm border border-gray-300 rounded-lg px-2 py-1.5 focus:ring-2 focus:ring-indigo-300 outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">External ID</label>
                            <input type="text" name="external_id" x-model="editData.external_id"
                                   class="w-full text-sm border border-gray-300 rounded-lg px-2 py-1.5 focus:ring-2 focus:ring-indigo-300 outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">External Label</label>
                            <input type="text" name="external_label" x-model="editData.external_label"
                                   class="w-full text-sm border border-gray-300 rounded-lg px-2 py-1.5 focus:ring-2 focus:ring-indigo-300 outline-none">
                        </div>
                        <div class="flex gap-2">
                            <button type="submit"
                                    class="flex-1 text-sm bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1.5 rounded-lg transition">
                                Save
                            </button>
                            <button type="button" @click="closeEdit()"
                                    class="text-sm border border-gray-300 text-gray-600 hover:bg-gray-100 px-3 py-1.5 rounded-lg transition">
                                ✕
                            </button>
                        </div>
                    </form>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Pagination --}}
    @if($mappings->hasPages())
    <div class="px-4 py-3 border-t border-gray-100 flex items-center justify-between">
        <p class="text-xs text-gray-500">
            Showing {{ $mappings->firstItem() }}–{{ $mappings->lastItem() }} of {{ $mappings->total() }}
        </p>
        {{ $mappings->links() }}
    </div>
    @endif
    @endif
</div>

</div>
@endsection