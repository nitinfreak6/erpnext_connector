@extends('dashboard.layout')
@section('title', $entity->label . ' Field Config')
@section('page-title', $entity->label . ' Field Config')

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

{{-- Entity type tabs — driven from entity_definitions, no hardcoding --}}
<div class="flex gap-2 mb-5 flex-wrap">
    @foreach($entities as $e)
    <a href="{{ route('dashboard.product-field-config.index', ['entity' => $e->entity_type]) }}"
       class="px-3 py-1.5 rounded-lg text-xs font-medium transition
              {{ $e->entity_type === $entityType
                 ? 'bg-indigo-600 text-white'
                 : 'bg-white border border-gray-200 text-gray-600 hover:bg-indigo-50 hover:text-indigo-600' }}">
        {{ $e->label }}
    </a>
    @endforeach
</div>

{{-- Header --}}
<div class="flex items-center justify-between mb-5">
    <div class="text-xs text-gray-400 space-y-0.5">
        <div>
            {{ $ecomDisplayName }} fields:
            @if($ecomFetchedAt)
                <span class="text-emerald-600">fetched {{ \Carbon\Carbon::parse($ecomFetchedAt)->diffForHumans() }}</span>
            @else
                <span class="text-amber-500">not fetched yet — click Fetch {{ $ecomDisplayName }} Fields</span>
            @endif
        </div>
        <div>
            {{ $erpDisplayName }} fields:
            @if($erpFetchedAt)
                <span class="text-emerald-600">fetched {{ \Carbon\Carbon::parse($erpFetchedAt)->diffForHumans() }}</span>
            @else
                <span class="text-amber-500">not fetched yet — click Fetch {{ $erpDisplayName }} Fields</span>
            @endif
        </div>
    </div>
    <div class="flex items-center gap-2">
        <form method="POST" action="{{ route('dashboard.product-field-config.fetch-ecom-fields', ['entity' => $entityType]) }}">
            @csrf
            <button type="submit" class="inline-flex items-center gap-1.5 text-sm border border-gray-300 text-gray-700 hover:bg-gray-50 px-3 py-1.5 rounded-lg transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                Fetch {{ $ecomDisplayName }} Fields
            </button>
        </form>
        <form method="POST" action="{{ route('dashboard.product-field-config.fetch-erp-fields', ['entity' => $entityType]) }}">
            @csrf
            <button type="submit" class="inline-flex items-center gap-1.5 text-sm border border-gray-300 text-gray-700 hover:bg-gray-50 px-3 py-1.5 rounded-lg transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                Fetch {{ $erpDisplayName }} Fields
            </button>
        </form>
        <button @click="openAdd()" class="inline-flex items-center gap-1.5 text-sm bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1.5 rounded-lg transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            Add Mapping
        </button>
    </div>
</div>

{{-- Table --}}
<div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">#</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">{{ $ecomDisplayName }} Field</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Type</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">{{ $erpDisplayName }} Field / Value</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Scope</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Transform</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Default</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($configs as $config)
            <tr class="hover:bg-gray-50 transition {{ $config->is_active ? '' : 'opacity-40' }}">
                <td class="px-4 py-3 text-xs text-gray-400">{{ $config->sort_order ?: $loop->iteration }}</td>

                <td class="px-4 py-3">
                    <div class="font-mono text-xs text-indigo-700 bg-indigo-50 px-2 py-0.5 rounded inline-block">
                        {{ $config->ecom_field ?? $config->shopify_field ?? '—' }}
                    </div>
                    @if($config->ecom_field_label ?? $config->shopify_field_label)
                        <div class="text-xs text-gray-400 mt-0.5">{{ $config->ecom_field_label ?? $config->shopify_field_label }}</div>
                    @endif
                </td>

                <td class="px-4 py-3">
                    @if($config->field_type === 'default')
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-700">Default</span>
                    @elseif($config->field_type === 'combine')
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-emerald-100 text-emerald-700">Combine</span>
                    @else
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-700">Custom</span>
                    @endif
                </td>

                <td class="px-4 py-3">
                    @if($config->field_type === 'default' && ($config->erp_field ?? $config->odoo_field))
                        <div class="font-mono text-xs text-gray-700 bg-gray-100 px-2 py-0.5 rounded inline-block">{{ $config->erp_field ?? $config->odoo_field }}</div>
                        @if($config->erp_field_label ?? $config->odoo_field_label)
                            <div class="text-xs text-gray-400 mt-0.5">{{ $config->erp_field_label ?? $config->odoo_field_label }}</div>
                        @endif
                    @elseif($config->field_type === 'combine')
                        <div class="text-xs space-y-0.5">
                            <span class="font-mono text-gray-700 bg-gray-100 px-1.5 py-0.5 rounded inline-block">{{ $config->erp_field ?? $config->odoo_field }}</span>
                            <span class="text-gray-400 font-mono mx-1">{{ $config->combine_separator ?? ' ' }}</span>
                            <span class="font-mono text-gray-700 bg-gray-100 px-1.5 py-0.5 rounded inline-block">{{ $config->erp_field_2 ?? $config->odoo_field_2 }}</span>
                        </div>
                    @elseif($config->field_type === 'custom')
                        <span class="text-xs text-purple-600 font-mono bg-purple-50 px-1.5 py-0.5 rounded">{{ $config->default_value ?: '—' }}</span>
                    @else
                        <span class="text-gray-300">—</span>
                    @endif
                </td>

                <td class="px-4 py-3">
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600">
                        {{ $config->scope }}
                    </span>
                </td>

                <td class="px-4 py-3 text-xs text-gray-400 font-mono">{{ $config->transform ?: '—' }}</td>
                <td class="px-4 py-3 text-xs text-gray-500">{{ ($config->field_type !== 'custom' && $config->default_value) ? $config->default_value : '—' }}</td>

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
				
				{{-- Readonly --}}
                <td class="px-4 py-3">
                    @if($config->is_readonly)
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-700">Readonly</span>
                    @else
                        <span class="text-gray-300 text-xs">—</span>
                    @endif
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
                            <button type="submit" class="text-red-400 hover:text-red-600 p-1 rounded hover:bg-red-50 transition" title="Delete">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="9" class="px-4 py-12 text-center text-gray-400 text-sm">
                    No field mappings yet for <strong>{{ $entity->label }}</strong>. Click <strong>Add Mapping</strong> to get started.
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
    @if($configs->hasPages())
        <div class="px-4 py-3 border-t border-gray-100">{{ $configs->links() }}</div>
    @endif
</div>

{{-- Modal --}}
<div x-show="showModal" x-cloak
     class="fixed inset-0 z-50 flex items-center justify-center"
     style="background: rgba(0,0,0,0.5)">
    <div @click.away="closeModal()"
         class="bg-white rounded-2xl shadow-2xl w-full max-w-lg mx-4 overflow-hidden">

        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
            <h3 class="text-base font-semibold text-gray-800" x-text="editId ? 'Edit Field Mapping' : 'Add Field Mapping'"></h3>
            <button @click="closeModal()" class="text-gray-400 hover:text-gray-600">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <form x-ref="form"
              action="{{ route('dashboard.product-field-config.store') }}"
              method="POST"
              class="px-6 py-5 space-y-4 overflow-y-auto"
              style="max-height:75vh">
            @csrf
            <input type="hidden" name="_method" x-ref="method" value="POST">
            <input type="hidden" name="entity_type" value="{{ $entityType }}">

            {{-- Ecom Field --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    {{ $ecomDisplayName }} Field <span class="text-red-500">*</span>
                </label>
                @php
                    // template_fields (product), variant_fields (product), or fields (all other entities)
                    $allEcomFields = array_merge(
                        !empty($ecomFields['template_fields']) ? $ecomFields['template_fields'] : ($ecomFields['fields'] ?? []),
                        $ecomFields['variant_fields'] ?? []
                    );
                @endphp
                @if(!empty($allEcomFields))
                <select name="ecom_field" x-model="form.ecom_field"
                        @change="onEcomFieldChange()"
                        class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 outline-none">
                    <option value="">— Select a {{ $ecomDisplayName }} field —</option>
                    @foreach($allEcomFields as $f)
                        <option value="{{ $f['key'] }}">{{ $f['label'] }} ({{ $f['key'] }})</option>
                    @endforeach
                </select>
                @else
                <input type="text" name="ecom_field" x-model="form.ecom_field"
                       placeholder="e.g. title, price"
                       class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 outline-none">
                <p class="text-xs text-gray-400 mt-1">Fetch {{ $ecomDisplayName }} fields above to get a dropdown.</p>
                @endif
                <input type="hidden" name="ecom_field_label" :value="form.ecom_field_label">
            </div>

            {{-- Scope — options from entity_definitions.scopes --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Scope / Level</label>
                <select name="scope" x-model="form.scope"
                        class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 outline-none">
                    @foreach($entity->scopes as $scope)
                        <option value="{{ $scope }}">{{ ucfirst($scope) }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Field Type --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Field Type <span class="text-red-500">*</span></label>
                <select name="field_type" x-model="form.field_type"
                        class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 outline-none">
                    <option value="default">Default — map from single {{ $erpDisplayName }} field</option>
                    <option value="combine">Combine — merge two {{ $erpDisplayName }} fields</option>
                    <option value="custom">Custom — send a fixed value</option>
                </select>
            </div>

            <input type="hidden" name="erp_field"         :value="form.erp_field">
            <input type="hidden" name="erp_field_label"   :value="form.erp_field_label">
            <input type="hidden" name="erp_field_2"       :value="form.erp_field_2">
            <input type="hidden" name="erp_field_2_label" :value="form.erp_field_2_label">
            <input type="hidden" name="combine_separator"  :value="form.combine_separator">
            <input type="hidden" name="default_value"      :value="form.default_value">

            {{-- ERP Field 1 --}}
            <div x-show="form.field_type === 'default' || form.field_type === 'combine'">
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    <span x-text="form.field_type === 'combine' ? '{{ $erpDisplayName }} Field 1' : '{{ $erpDisplayName }} Field'"></span>
                </label>
                @php $erpFieldList = $erpFields['fields'] ?? array_merge($erpFields['template_fields'] ?? [], $erpFields['variant_fields'] ?? []); @endphp
                @if(!empty($erpFieldList))
                <select :value="form.erp_field"
                        @change="form.erp_field = $event.target.value; onErpFieldChange()"
                        class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 outline-none">
                    <option value="">— Select a {{ $erpDisplayName }} field —</option>
                    @foreach($erpFieldList as $f)
                        <option value="{{ $f['key'] }}">{{ $f['label'] }} ({{ $f['key'] }})</option>
                    @endforeach
                </select>
                @else
                <input type="text" @input="form.erp_field = $event.target.value" :value="form.erp_field"
                       placeholder="e.g. name, list_price"
                       class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 outline-none">
                <p class="text-xs text-gray-400 mt-1">Fetch {{ $erpDisplayName }} fields above to get a dropdown.</p>
                @endif
            </div>

            {{-- ERP Field 2 + Separator --}}
            <div x-show="form.field_type === 'combine'" class="space-y-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ $erpDisplayName }} Field 2</label>
                    @if(!empty($erpFieldList))
                    <select :value="form.erp_field_2"
                            @change="form.erp_field_2 = $event.target.value"
                            class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 outline-none">
                        <option value="">— Select a {{ $erpDisplayName }} field —</option>
                        @foreach($erpFieldList as $f)
                            <option value="{{ $f['key'] }}">{{ $f['label'] }} ({{ $f['key'] }})</option>
                        @endforeach
                    </select>
                    @else
                    <input type="text" @input="form.erp_field_2 = $event.target.value" :value="form.erp_field_2"
                           placeholder="e.g. description_sale"
                           class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 outline-none">
                    @endif
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Separator</label>
                    <input type="text" @input="form.combine_separator = $event.target.value" :value="form.combine_separator"
                           placeholder="e.g. - or / or space"
                           class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 outline-none font-mono">
                </div>
            </div>

            {{-- Custom Value --}}
            <div x-show="form.field_type === 'custom'">
                <label class="block text-sm font-medium text-gray-700 mb-1">Fixed Value <span class="text-red-500">*</span></label>
                <input type="text" @input="form.default_value = $event.target.value" :value="form.default_value"
                       placeholder="e.g. active, MyBrand"
                       class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 outline-none">
            </div>

            {{-- Default Value fallback --}}
            <div x-show="form.field_type !== 'custom'">
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    Default Value
                    <span class="text-xs text-gray-400 font-normal ml-1">— fallback if {{ $erpDisplayName }} value is empty</span>
                </label>
                <input type="text" @input="form.default_value = $event.target.value" :value="form.default_value"
                       placeholder="e.g. draft, 0.00"
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
                    <option value="base64_image">Base64 → image array</option>
                    <option value="line_container">Line Container (maps array of line items to ERP ORM commands)</option>
                    <option value="parse_int">parse_int</option>
                </select>
            </div>

            {{-- Min / Max --}}
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Min Length</label>
                    <input type="number" name="min_length" x-model="form.min_length" min="0"
                           class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Max Length</label>
                    <input type="number" name="max_length" x-model="form.max_length" min="0"
                           class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 outline-none">
                </div>
            </div>

            {{-- Sort Order + Active --}}
            <div class="grid grid-cols-2 gap-3 items-end">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Sort Order</label>
                    <input type="number" name="sort_order" x-model="form.sort_order" min="0"
                           class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 outline-none">
                </div>
                <div class="flex items-center gap-2 pb-2">
                    <input type="checkbox" name="is_active" value="1" id="modal_is_active"
                           :checked="form.is_active" @change="form.is_active = $event.target.checked"
                           class="rounded text-indigo-600">
                    <label for="modal_is_active" class="text-sm text-gray-700 cursor-pointer">Active</label>
                </div>
				<div class="flex items-center gap-2 pb-2">
                    <input type="checkbox" name="is_readonly" value="1" id="modal_is_readonly"
                           :checked="form.is_readonly"
                           @change="form.is_readonly = $event.target.checked"
                           class="rounded text-amber-500">
                    <label for="modal_is_readonly" class="text-sm text-gray-700 cursor-pointer">
                        Readonly
                        <span class="text-xs text-gray-400 block font-normal">ERP computes this</span>
                    </label>
                </div>
            </div>

            <div class="flex items-center justify-between pt-2 border-t border-gray-100">
                <button type="button" @click="closeModal()"
                        class="text-sm text-gray-500 hover:text-gray-700 px-4 py-2 border border-gray-300 rounded-lg transition">
                    Cancel
                </button>
                <button type="submit"
                        class="text-sm bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2 rounded-lg transition font-medium">
                    Save
                </button>
            </div>
        </form>
    </div>
</div>

</div>

<script>
function fieldConfigApp() {
    return {
        showModal: false,
        editId: null,

        ecomFields: @json(array_merge(!empty($ecomFields['template_fields']) ? $ecomFields['template_fields'] : ($ecomFields['fields'] ?? []), $ecomFields['variant_fields'] ?? [])),
        erpFields:  @json($erpFields['fields'] ?? array_merge($erpFields['template_fields'] ?? [], $erpFields['variant_fields'] ?? [])),

        form: {
            ecom_field: '', ecom_field_label: '',
            field_type: 'default',
            erp_field: '', erp_field_label: '',
            erp_field_2: '', erp_field_2_label: '',
            combine_separator: ' ',
            scope: '{{ $entity->scopes[0] ?? "header" }}',
            default_value: '', transform: '',
            min_length: '', max_length: '',
            sort_order: 0, is_active: true,
        },

        init() {},

        openAdd() {
            this.editId = null;
            this.form = {
                ecom_field: '', ecom_field_label: '',
                field_type: 'default',
                erp_field: '', erp_field_label: '',
                erp_field_2: '', erp_field_2_label: '',
                combine_separator: ' ',
                scope: '{{ $entity->scopes[0] ?? "header" }}',
                default_value: '', transform: '',
                min_length: '', max_length: '',
                sort_order: 0, is_active: true, is_readonly: false,
            };
            this.$nextTick(() => {
                this.$refs.form.action = '{{ route('dashboard.product-field-config.store') }}';
                this.$refs.method.value = 'POST';
            });
            this.showModal = true;
        },

        openEdit(config) {
            this.editId = config.id;
            this.form = {
                ecom_field:        config.ecom_field        || config.shopify_field       || '',
                ecom_field_label:  config.ecom_field_label  || config.shopify_field_label || '',
                field_type:        config.field_type        || 'default',
                erp_field:         config.erp_field         || config.odoo_field          || '',
                erp_field_label:   config.erp_field_label   || config.odoo_field_label    || '',
                erp_field_2:       config.erp_field_2       || config.odoo_field_2        || '',
                erp_field_2_label: config.erp_field_2_label || config.odoo_field_2_label  || '',
                combine_separator: config.combine_separator || ' ',
                scope:             config.scope             || '{{ $entity->scopes[0] ?? "header" }}',
                default_value:     config.default_value     || '',
                transform:         config.transform         || '',
                min_length:        config.min_length        || '',
                max_length:        config.max_length        || '',
                sort_order:        config.sort_order        || 0,
                is_active:         config.is_active,
				is_readonly:       !!config.is_readonly,
            };
            this.$nextTick(() => {
                this.$refs.form.action = '/dashboard/product-field-config/' + config.id;
                this.$refs.method.value = 'PUT';
            });
            this.showModal = true;
        },

        closeModal() {
            this.showModal = false;
            this.editId = null;
        },

        onEcomFieldChange() {
            const found = this.ecomFields.find(f => f.key === this.form.ecom_field);
            if (found) {
                this.form.ecom_field_label = found.label;
                if (found.scope) this.form.scope = found.scope;
            }
        },

        onErpFieldChange() {
            const found = this.erpFields.find(f => f.key === this.form.erp_field);
            if (found) this.form.erp_field_label = found.label;
        },
    };
}
</script>
@endsection