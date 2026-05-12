@extends('dashboard.layout')
@section('title', 'Product Field Mapping')
@section('page-title', 'Product Field Mapping')

@section('content')
<div x-data="fieldConfigApp()" x-init="init()">

{{-- Flash --}}
@if(session('success'))
<div class="mb-4 bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 rounded-lg text-sm flex items-center gap-2">
    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
    {{ session('success') }}
</div>
@endif
@if(session('error'))
<div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm flex items-center gap-2">
    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
    {{ session('error') }}
</div>
@endif

{{-- Header --}}
<div class="flex items-center justify-between mb-5">
    <div class="text-xs text-gray-400 space-y-0.5">
        <div>
            Shopify fields:
            @if($shopifyFetchedAt)
                <span class="text-emerald-600">fetched {{ \Carbon\Carbon::parse($shopifyFetchedAt)->diffForHumans() }}</span>
            @else
                <span class="text-amber-500">not fetched yet</span>
            @endif
        </div>
        <div>
            Odoo fields:
            @if($odooFetchedAt)
                <span class="text-emerald-600">fetched {{ \Carbon\Carbon::parse($odooFetchedAt)->diffForHumans() }}</span>
            @else
                <span class="text-amber-500">not fetched yet — click Fetch Odoo Fields</span>
            @endif
        </div>
    </div>
    <div class="flex items-center gap-2">
        {{-- Fetch Shopify fields --}}
        <form method="POST" action="{{ route('dashboard.product-field-config.fetch-shopify') }}">
            @csrf
            <button type="submit"
                    class="inline-flex items-center gap-1.5 text-sm border border-gray-300 text-gray-700 hover:bg-gray-50 px-3 py-1.5 rounded-lg transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                Fetch Shopify Fields
            </button>
        </form>
        {{-- Fetch Odoo fields --}}
        <form method="POST" action="{{ route('dashboard.product-field-config.fetch-odoo') }}">
            @csrf
            <button type="submit"
                    class="inline-flex items-center gap-1.5 text-sm border border-gray-300 text-gray-700 hover:bg-gray-50 px-3 py-1.5 rounded-lg transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                Fetch Odoo Fields
            </button>
        </form>
        {{-- Add New --}}
        <button @click="openAdd()"
                class="inline-flex items-center gap-1.5 text-sm bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1.5 rounded-lg transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            Add New Mapping
        </button>
    </div>
</div>

{{-- Table --}}
<div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">#</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Shopify Field</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Field Type</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Odoo Field / Custom Value</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Level</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Default Value</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Transform</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($configs as $config)
            <tr class="hover:bg-gray-50 transition {{ $config->is_active ? '' : 'opacity-40' }}">
                <td class="px-4 py-3 text-xs text-gray-400">{{ $config->sort_order ?: $loop->iteration }}</td>
                <td class="px-4 py-3">
                    <div class="font-mono text-xs text-indigo-700 bg-indigo-50 px-2 py-0.5 rounded inline-block">{{ $config->shopify_field }}</div>
                    @if($config->shopify_field_label)
                    <div class="text-xs text-gray-400 mt-0.5">{{ $config->shopify_field_label }}</div>
                    @endif
                </td>
                <td class="px-4 py-3">
                    @if($config->field_type === 'default')
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-700">Default</span>
                    @else
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-700">Custom</span>
                    @endif
                </td>
                <td class="px-4 py-3">
                    @if($config->field_type === 'default' && $config->odoo_field)
                        <div class="font-mono text-xs text-gray-700 bg-gray-100 px-2 py-0.5 rounded inline-block">{{ $config->odoo_field }}</div>
                        @if($config->odoo_field_label)
                        <div class="text-xs text-gray-400 mt-0.5">{{ $config->odoo_field_label }}</div>
                        @endif
                    @elseif($config->field_type === 'custom')
                        <span class="text-xs text-purple-600 italic">Custom value</span>
                    @else
                        <span class="text-gray-300">—</span>
                    @endif
                </td>
                <td class="px-4 py-3">
                    @if($config->scope === 'variant')
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-700">Variant</span>
                    @else
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600">Template</span>
                    @endif
                </td>
                <td class="px-4 py-3 text-xs text-gray-500">{{ $config->default_value ?: '—' }}</td>
                <td class="px-4 py-3 text-xs text-gray-400 font-mono">{{ $config->transform ?: '—' }}</td>
                <td class="px-4 py-3">
                    <form method="POST" action="{{ route('dashboard.product-field-config.toggle', $config) }}">
                        @csrf @method('PATCH')
                        <button type="submit"
                                class="text-xs px-2 py-0.5 rounded font-medium transition cursor-pointer
                                       {{ $config->is_active ? 'bg-emerald-100 text-emerald-700 hover:bg-red-100 hover:text-red-600' : 'bg-gray-100 text-gray-500 hover:bg-emerald-100 hover:text-emerald-600' }}">
                            {{ $config->is_active ? 'Active' : 'Disabled' }}
                        </button>
                    </form>
                </td>
                <td class="px-4 py-3 text-right">
                    <div class="flex items-center justify-end gap-2">
                        <button @click="openEdit({{ json_encode($config) }})"
                                class="text-indigo-500 hover:text-indigo-700 p-1 rounded hover:bg-indigo-50 transition" title="Edit">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                        </button>
                        <form method="POST" action="{{ route('dashboard.product-field-config.destroy', $config) }}"
                              onsubmit="return confirm('Delete this mapping?')">
                            @csrf @method('DELETE')
                            <button type="submit"
                                    class="text-red-400 hover:text-red-600 p-1 rounded hover:bg-red-50 transition" title="Delete">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="9" class="px-4 py-12 text-center text-gray-400 text-sm">
                    No field mappings yet. Click <strong>Add New Mapping</strong> or run <code class="bg-gray-100 px-1 rounded">php artisan migrate</code> to seed defaults.
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
    @if($configs->hasPages())
    <div class="px-4 py-3 border-t border-gray-100">{{ $configs->links() }}</div>
    @endif
</div>

{{-- ── Modal ── --}}
<div x-show="showModal" x-cloak
     class="fixed inset-0 z-50 flex items-center justify-center"
     style="background: rgba(0,0,0,0.5)">
    <div @click.away="closeModal()"
         class="bg-white rounded-2xl shadow-2xl w-full max-w-lg mx-4 overflow-hidden">

        {{-- Modal header --}}
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
            <h3 class="text-base font-semibold text-gray-800" x-text="editId ? 'Edit Field Mapping' : 'Add New Field Mapping'"></h3>
            <button @click="closeModal()" class="text-gray-400 hover:text-gray-600">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        {{-- Modal form --}}
        <form :action="editId ? '{{ url('dashboard/product-field-config') }}/' + editId : '{{ route('dashboard.product-field-config.store') }}'"
              method="POST" class="px-6 py-5 space-y-4 overflow-y-auto" style="max-height:75vh">
            @csrf
            <span x-show="editId" x-html="'<input name=\'_method\' type=\'hidden\' value=\'PUT\'>'"></span>

            {{-- Shopify Field --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    Shopify Field <span class="text-red-500">*</span>
                </label>
                <select name="shopify_field" x-model="form.shopify_field"
                        @change="onShopifyFieldChange()"
                        class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 outline-none">
                    <option value="">— Select a Shopify field —</option>
                    <optgroup label="Template Fields">
                        <template x-for="f in shopifyTemplateFields" :key="f.key">
                            <option :value="f.key" :selected="form.shopify_field === f.key" x-text="f.label + ' (' + f.key + ')'"></option>
                        </template>
                    </optgroup>
                    <optgroup label="Variant Fields">
                        <template x-for="f in shopifyVariantFields" :key="f.key">
                            <option :value="f.key" :selected="form.shopify_field === f.key" x-text="f.label + ' (' + f.key + ')'"></option>
                        </template>
                    </optgroup>
                </select>
                <input type="hidden" name="shopify_field_label" :value="form.shopify_field_label">
                <input type="hidden" name="scope" :value="form.scope">
            </div>

            {{-- Is Item Level (auto-set from shopify field, but overrideable) --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Is Item Level (Scope)</label>
                <select name="scope" x-model="form.scope"
                        class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 outline-none">
                    <option value="template">No — Template Level (product)</option>
                    <option value="variant">Yes — Item Level (variant)</option>
                </select>
            </div>

            {{-- Field Type --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    Field Type <span class="text-red-500">*</span>
                </label>
                <select name="field_type" x-model="form.field_type"
                        class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 outline-none">
                    <option value="default">Default — Map from Odoo field</option>
                    <option value="custom">Custom — Use a fixed value</option>
                </select>
            </div>

            {{-- Odoo Field (shown only when field_type = default) --}}
            <div x-show="form.field_type === 'default'">
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    Odoo Field <span class="text-red-500">*</span>
                    <span class="text-xs text-gray-400 font-normal ml-1">— which Odoo value maps to this Shopify field</span>
                </label>
                <select name="odoo_field" x-model="form.odoo_field"
                        @change="onOdooFieldChange()"
                        class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 outline-none">
                    <option value="">— Select an Odoo field —</option>
                    <template x-if="form.scope === 'template'">
                        <optgroup label="Template Fields (product.template)">
                            <template x-for="f in odooTemplateFields" :key="f.key">
                                <option :value="f.key" :selected="form.odoo_field === f.key"
                                        x-text="f.label + ' (' + f.key + ')'"></option>
                            </template>
                        </optgroup>
                    </template>
                    <template x-if="form.scope === 'variant'">
                        <optgroup label="Variant Fields (product.product)">
                            <template x-for="f in odooVariantFields" :key="f.key">
                                <option :value="f.key" :selected="form.odoo_field === f.key"
                                        x-text="f.label + ' (' + f.key + ')'"></option>
                            </template>
                        </optgroup>
                    </template>
                </select>
                <input type="hidden" name="odoo_field_label" :value="form.odoo_field_label">
            </div>

            {{-- Default Value --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    Default Value
                    <span class="text-xs text-gray-400 font-normal ml-1">— used if Odoo value is empty</span>
                </label>
                <input type="text" name="default_value" x-model="form.default_value"
                       placeholder="e.g. draft, 0.00, active"
                       class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 outline-none">
            </div>

            {{-- Transform --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Transform</label>
                <select name="transform" x-model="form.transform"
                        class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 outline-none">
                    <option value="">None</option>
                    <option value="number_format">Number Format (e.g. 500.00)</option>
                    <option value="number_format_nullable">Number Format or Null if 0</option>
                    <option value="boolean_status">Boolean → active / draft</option>
                    <option value="array_second">Array Second Value [id, name] → name</option>
                    <option value="base64_image">Base64 → Shopify images array</option>
                </select>
            </div>

            {{-- Min / Max Length --}}
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Min Length</label>
                    <input type="number" name="min_length" x-model="form.min_length" min="0"
                           class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Max Length</label>
                    <input type="number" name="max_length" x-model="form.max_length" min="0"
                           class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 outline-none">
                </div>
            </div>

            {{-- Sort Order + Active --}}
            <div class="grid grid-cols-2 gap-3 items-end">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Sort Order</label>
                    <input type="number" name="sort_order" x-model="form.sort_order" min="0"
                           class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 outline-none">
                </div>
                <div class="flex items-center gap-2 pb-2">
                    <input type="checkbox" name="is_active" value="1" id="modal_is_active"
                           :checked="form.is_active"
                           @change="form.is_active = $event.target.checked"
                           class="rounded text-indigo-600">
                    <label for="modal_is_active" class="text-sm text-gray-700 cursor-pointer">Active</label>
                </div>
            </div>

            <input type="hidden" name="channel" value="shopify">

            {{-- Footer --}}
            <div class="flex items-center justify-between pt-2 border-t border-gray-100">
                <button type="button" @click="closeModal()"
                        class="text-sm text-gray-500 hover:text-gray-700 px-4 py-2 border border-gray-300 rounded-lg transition">
                    Close
                </button>
                <button type="submit"
                        class="text-sm bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2 rounded-lg transition font-medium">
                    Save
                </button>
            </div>
        </form>
    </div>
</div>

</div>{{-- end x-data --}}

<script>
function fieldConfigApp() {
    return {
        showModal: false,
        editId: null,

        // Field lists loaded from PHP (passed as JSON)
        shopifyTemplateFields: @json($shopifyFields['template_fields'] ?? []),
        shopifyVariantFields:  @json($shopifyFields['variant_fields']  ?? []),
        odooTemplateFields:    @json($odooFields['template_fields']    ?? []),
        odooVariantFields:     @json($odooFields['variant_fields']     ?? []),

        form: {
            shopify_field:       '',
            shopify_field_label: '',
            field_type:          'default',
            odoo_field:          '',
            odoo_field_label:    '',
            scope:               'template',
            default_value:       '',
            transform:           '',
            min_length:          '',
            max_length:          '',
            sort_order:          0,
            is_active:           true,
        },

        init() {},

        openAdd() {
            this.editId = null;
            this.form = {
                shopify_field: '', shopify_field_label: '',
                field_type: 'default', odoo_field: '', odoo_field_label: '',
                scope: 'template', default_value: '', transform: '',
                min_length: '', max_length: '', sort_order: 0, is_active: true,
            };
            this.showModal = true;
        },

        openEdit(config) {
            this.editId = config.id;
            this.form = {
                shopify_field:       config.shopify_field       || '',
                shopify_field_label: config.shopify_field_label || '',
                field_type:          config.field_type          || 'default',
                odoo_field:          config.odoo_field          || '',
                odoo_field_label:    config.odoo_field_label    || '',
                scope:               config.scope               || 'template',
                default_value:       config.default_value       || '',
                transform:           config.transform           || '',
                min_length:          config.min_length          || '',
                max_length:          config.max_length          || '',
                sort_order:          config.sort_order          || 0,
                is_active:           config.is_active,
            };
            this.showModal = true;
        },

        closeModal() {
            this.showModal = false;
            this.editId = null;
        },

        // When Shopify field is selected, auto-set scope and label
        onShopifyFieldChange() {
            const allFields = [...this.shopifyTemplateFields, ...this.shopifyVariantFields];
            const found = allFields.find(f => f.key === this.form.shopify_field);
            if (found) {
                this.form.shopify_field_label = found.label;
                this.form.scope = found.scope || 'template';
            }
        },

        // When Odoo field is selected, auto-set label
        onOdooFieldChange() {
            const allFields = [...this.odooTemplateFields, ...this.odooVariantFields];
            const found = allFields.find(f => f.key === this.form.odoo_field);
            if (found) {
                this.form.odoo_field_label = found.label;
            }
        },
    };
}
</script>
@endsection