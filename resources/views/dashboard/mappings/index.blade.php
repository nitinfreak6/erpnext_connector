@extends('dashboard.layout')

@section('title', $labels[$type] ?? 'Mappings')
@section('page-title', $labels[$type] ?? 'Mappings')

@section('content')
<div x-data="mappingPage()">

{{-- Header --}}
<div class="flex items-center justify-between mb-5">
    <p class="text-sm text-gray-500">
        Map <strong class="text-gray-700">{{ $erpDisplayName }}</strong> values →
        <strong class="text-indigo-600">{{ $ecomDisplayName }}</strong> / Amazon
    </p>
    <div class="flex gap-2">
        <button @click="showImport = !showImport"
                class="inline-flex items-center gap-1.5 text-sm border border-gray-300 text-gray-700 hover:bg-gray-50 px-3 py-1.5 rounded-lg transition">
            ↑ Import JSON
        </button>
        <button @click="openAdd()"
                class="inline-flex items-center gap-1.5 text-sm bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1.5 rounded-lg transition">
            + Add Mapping
        </button>
    </div>
</div>

{{-- Flash --}}
@foreach(['success','error','warning'] as $t)
    @if(session($t))
        <div class="mb-4 px-4 py-3 rounded-lg text-sm border
            {{ $t === 'success' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : ($t === 'error' ? 'bg-red-50 text-red-700 border-red-200' : 'bg-amber-50 text-amber-700 border-amber-200') }}">
            {{ session($t) }}
        </div>
    @endif
@endforeach

{{-- Import panel --}}
<div x-show="showImport" x-cloak x-transition
     class="bg-amber-50 border border-amber-200 rounded-xl p-5 mb-5">
    <h3 class="text-sm font-semibold text-amber-800 mb-2">Bulk Import via JSON</h3>
    <form method="POST" action="{{ route('dashboard.mappings.import', $type) }}">
        @csrf
        <textarea name="json_data" rows="4"
                  placeholder='[{"odoo_id":"5","odoo_label":"WH/Stock","external_id":"69188747343","external_label":"Main Warehouse","channel":"shopify"}]'
                  class="w-full text-sm font-mono border border-amber-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-amber-300 outline-none bg-white"></textarea>
        <div class="flex justify-end gap-2 mt-2">
            <button type="button" @click="showImport = false" class="text-sm text-gray-500 px-3 py-1.5">Cancel</button>
            <button type="submit" class="text-sm bg-amber-600 hover:bg-amber-700 text-white px-4 py-1.5 rounded-lg">Import</button>
        </div>
    </form>
</div>

{{-- Filters --}}
<div class="bg-white rounded-xl border border-gray-200 p-4 mb-4 flex flex-wrap items-center gap-3">
    <form method="GET" action="{{ route('dashboard.mappings.index', $type) }}" class="flex flex-wrap items-center gap-3 flex-1">
        <div class="flex items-center gap-1 bg-gray-100 rounded-lg p-1">
            @foreach(['all' => 'All', 'shopify' => $ecomDisplayName, 'amazon' => 'Amazon'] as $val => $lbl)
            <button type="submit" name="channel" value="{{ $val }}"
                    class="text-xs px-3 py-1 rounded-md transition font-medium
                           {{ $channel === $val ? 'bg-white shadow text-indigo-700' : 'text-gray-500 hover:text-gray-700' }}">
                {{ $lbl }}
            </button>
            @endforeach
        </div>
        <div class="relative flex-1 max-w-sm">
            <input type="text" name="search" value="{{ $search }}" placeholder="Search mappings..."
                   class="w-full text-sm border border-gray-300 rounded-lg pl-3 pr-3 py-2 focus:ring-2 focus:ring-indigo-300 outline-none">
        </div>
        <button type="submit" class="text-sm bg-gray-800 text-white px-3 py-2 rounded-lg hover:bg-gray-900">Search</button>
        @if($search)
        <a href="{{ route('dashboard.mappings.index', $type) }}" class="text-sm text-gray-400 hover:text-gray-600">Clear</a>
        @endif
    </form>
    <span class="text-xs text-gray-400">{{ $mappings->total() }} total</span>
</div>

{{-- Table --}}
<div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
    @if($mappings->isEmpty())
        <div class="py-16 text-center text-gray-400">
            <p class="text-sm font-medium">No mappings yet</p>
            <p class="text-xs mt-1">Click "Add Mapping" to create your first one</p>
        </div>
    @else
    <div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="bg-gray-50 border-b border-gray-200 text-xs font-semibold text-gray-500 uppercase tracking-wider">
                <th class="text-left px-4 py-3">Channel</th>
                <th class="text-left px-4 py-3">{{ $erpDisplayName }} ID</th>
                <th class="text-left px-4 py-3">{{ $erpDisplayName }} Label</th>
                <th class="text-left px-4 py-3">{{ $erpDisplayName }} Value Field</th>
                <th class="px-4 py-3 text-gray-300">→</th>
                <th class="text-left px-4 py-3">External ID</th>
                <th class="text-left px-4 py-3">External Label</th>
                <th class="text-left px-4 py-3">Ext. Value Field</th>
                <th class="text-left px-4 py-3">Default</th>
                <th class="text-left px-4 py-3">Status</th>
                <th class="text-right px-4 py-3">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @foreach($mappings as $mapping)
            @php $meta = $mapping->meta ?? []; @endphp
            <tr class="hover:bg-gray-50 transition {{ $mapping->is_active ? '' : 'opacity-50' }}">
                <td class="px-4 py-3">
                    @if($mapping->channel === 'shopify')
                        <span class="px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-700">{{ $ecomDisplayName }}</span>
                    @elseif($mapping->channel === 'amazon')
                        <span class="px-2 py-0.5 rounded text-xs font-medium bg-orange-100 text-orange-700">Amazon</span>
                    @else
                        <span class="px-2 py-0.5 rounded text-xs font-medium bg-indigo-100 text-indigo-700">Both</span>
                    @endif
                </td>
                <td class="px-4 py-3 font-mono text-xs text-gray-700">{{ $mapping->odoo_id }}</td>
                <td class="px-4 py-3 text-gray-800 font-medium">{{ $mapping->odoo_label ?: '—' }}</td>
                <td class="px-4 py-3 text-xs text-gray-500 font-mono">{{ $meta['odoo_value_field'] ?? '—' }}</td>
                <td class="px-4 py-3 text-gray-300 text-center">→</td>
                <td class="px-4 py-3 font-mono text-xs text-gray-700">{{ $mapping->external_id }}</td>
                <td class="px-4 py-3 text-gray-800">{{ $mapping->external_label ?: '—' }}</td>
                <td class="px-4 py-3 text-xs text-gray-500 font-mono">{{ $meta['external_value_field'] ?? '—' }}</td>
                <td class="px-4 py-3 text-xs text-gray-500">{{ $meta['default_value'] ?? '—' }}</td>
                <td class="px-4 py-3">
                    <form method="POST" action="{{ route('dashboard.mappings.toggle', [$type, $mapping]) }}">
                        @csrf @method('PATCH')
                        <button type="submit"
                                class="px-2 py-0.5 rounded text-xs font-medium cursor-pointer transition
                                       {{ $mapping->is_active ? 'bg-emerald-100 text-emerald-700 hover:bg-red-100 hover:text-red-600' : 'bg-gray-100 text-gray-500 hover:bg-emerald-100 hover:text-emerald-700' }}">
                            {{ $mapping->is_active ? 'Active' : 'Disabled' }}
                        </button>
                    </form>
                </td>
                <td class="px-4 py-3 text-right">
                    <div class="flex items-center justify-end gap-2">
                        <button @click="openEdit({{ json_encode([
                            'id'                  => $mapping->id,
                            'channel'             => $mapping->channel,
                            'odoo_id'             => $mapping->odoo_id,
                            'odoo_label'          => $mapping->odoo_label,
                            'external_id'         => $mapping->external_id,
                            'external_label'      => $mapping->external_label,
                            'odoo_value_field'    => $meta['odoo_value_field']    ?? '',
                            'external_value_field'=> $meta['external_value_field'] ?? '',
                            'default_value'       => $meta['default_value']       ?? '',
                            'min_length'          => $meta['min_length']          ?? '',
                            'max_length'          => $meta['max_length']          ?? '',
                            'is_active'           => $mapping->is_active,
                        ]) }})"
                                class="text-indigo-500 hover:text-indigo-700 p-1 rounded hover:bg-indigo-50 transition" title="Edit">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                            </svg>
                        </button>
                        <form method="POST" action="{{ route('dashboard.mappings.destroy', [$type, $mapping]) }}"
                              onsubmit="return confirm('Delete this mapping?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-red-400 hover:text-red-600 p-1 rounded hover:bg-red-50 transition" title="Delete">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                </svg>
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    </div>
    @if($mappings->hasPages())
    <div class="px-4 py-3 border-t border-gray-100 flex items-center justify-between">
        <p class="text-xs text-gray-500">Showing {{ $mappings->firstItem() }}–{{ $mappings->lastItem() }} of {{ $mappings->total() }}</p>
        {{ $mappings->links() }}
    </div>
    @endif
    @endif
</div>

{{-- ── Add / Edit Modal ── --}}
<div x-show="showModal" x-cloak
     class="fixed inset-0 z-50 flex items-center justify-center bg-black/40"
     @keydown.escape.window="closeModal()">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg mx-4 overflow-hidden"
         @click.outside="closeModal()">

        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
            <h2 class="text-base font-semibold text-gray-800"
                x-text="editId ? 'Edit Mapping' : 'Add Mapping'"></h2>
            <button @click="closeModal()" class="text-gray-400 hover:text-gray-600 text-lg leading-none">×</button>
        </div>

        {{-- Add form --}}
        <form x-show="!editId"
              method="POST" action="{{ route('dashboard.mappings.store', $type) }}"
              class="px-6 py-5 space-y-4">
            @csrf
            @include('dashboard.mappings._form', ['data' => null])
            <div class="flex justify-between pt-2">
                <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg">Save</button>
                <button type="button" @click="closeModal()" class="px-4 py-2 border border-gray-300 text-gray-600 text-sm rounded-lg hover:bg-gray-50">Close</button>
            </div>
        </form>

        {{-- Edit forms (one per mapping to get correct action URL) --}}
        @foreach($mappings as $mapping)
        <form x-show="editId === {{ $mapping->id }}"
              method="POST" action="{{ route('dashboard.mappings.update', [$type, $mapping]) }}"
              class="px-6 py-5 space-y-4">
            @csrf @method('PUT')
            @include('dashboard.mappings._form', ['data' => $mapping])
            <div class="flex justify-between pt-2">
                <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg">Save</button>
                <button type="button" @click="closeModal()" class="px-4 py-2 border border-gray-300 text-gray-600 text-sm rounded-lg hover:bg-gray-50">Close</button>
            </div>
        </form>
        @endforeach

    </div>
</div>

</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('mappingPage', () => ({
        showModal: false,
        showImport: false,
        editId: null,
        erpFields: [],
        ecomFields: [],
        erpLoading: false,
        ecomLoading: false,
        erpRoute: '{{ route('dashboard.mappings.fetch-erp-fields', $type) }}',
        ecomRoute: '{{ route('dashboard.mappings.fetch-ecom-fields', $type) }}',
        csrfToken: '{{ csrf_token() }}',
        form: {
            channel: 'shopify',
            odoo_id: '', odoo_label: '',
            external_id: '', external_label: '',
            odoo_value_field: '', external_value_field: '',
            default_value: '', min_length: '', max_length: '',
            is_active: true,
        },
        openAdd() {
            this.editId = null;
            this.erpFields = [];
            this.ecomFields = [];
            this.form = { channel: 'shopify', odoo_id: '', odoo_label: '', external_id: '', external_label: '', odoo_value_field: '', external_value_field: '', default_value: '', min_length: '', max_length: '', is_active: true };
            this.showModal = true;
        },
        openEdit(m) {
            this.editId = m.id;
            this.erpFields = [];
            this.ecomFields = [];
            this.form = {
                channel:              m.channel               || 'shopify',
                odoo_id:              m.odoo_id               || '',
                odoo_label:           m.odoo_label            || '',
                external_id:          m.external_id           || '',
                external_label:       m.external_label        || '',
                odoo_value_field:     m.odoo_value_field      || '',
                external_value_field: m.external_value_field  || '',
                default_value:        m.default_value         || '',
                min_length:           m.min_length            || '',
                max_length:           m.max_length            || '',
                is_active:            m.is_active !== undefined ? m.is_active : true,
            };
            this.showModal = true;
        },
        closeModal() { this.showModal = false; this.editId = null; },
        async fetchErpFields() {
            this.erpLoading = true;
            try {
                const res = await fetch(this.erpRoute, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrfToken, 'Content-Type': 'application/json' },
                });
                const data = await res.json();
                this.erpFields = data.fields || [];
            } catch(e) { console.error('fetchErpFields:', e); }
            this.erpLoading = false;
        },
        async fetchEcomFields() {
            this.ecomLoading = true;
            try {
                const res = await fetch(this.ecomRoute, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrfToken, 'Content-Type': 'application/json' },
                });
                const data = await res.json();
                this.ecomFields = data.fields || [];
            } catch(e) { console.error('fetchEcomFields:', e); }
            this.ecomLoading = false;
        },
    }));
});
</script>

@endsection