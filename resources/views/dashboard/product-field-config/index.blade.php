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
@if($errors->any())
<div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm">
    <p class="font-medium mb-1">Could not save field mapping:</p>
    <ul class="list-disc list-inside space-y-0.5">
        @foreach($errors->all() as $error)
            <li>{{ $error }}</li>
        @endforeach
    </ul>
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
<div class="flex items-center justify-end mb-5">
    <button @click="openAdd()" class="inline-flex items-center gap-1.5 text-sm bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1.5 rounded-lg transition">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
        Add Mapping
    </button>
</div>

{{-- Table --}}
<div class="bg-white rounded-xl border border-gray-200 shadow-sm">
    <div class="overflow-x-auto w-full">
    <table class="text-sm w-max min-w-full">
        <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">#</th>
                @if(!$directionLocked)
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Direction</th>
                @endif
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">{{ $ecomDisplayName }} Field</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Type</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">{{ $erpDisplayName }} Field / Value</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Scope</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Conditions</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Transform</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Default</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase whitespace-nowrap">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($configs as $config)
            <tr class="hover:bg-gray-50 transition {{ $config->is_active ? '' : 'opacity-40' }}">
                <td class="px-4 py-3 text-xs text-gray-400">{{ ($config->sort_order ?? 0) > 0 ? $config->sort_order : '—' }}</td>

                @if(!$directionLocked)
                <td class="px-4 py-3">
                    @php $dir = $config->direction ?? 'erp_to_ecom'; @endphp
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $dir === 'ecom_to_erp' ? 'bg-amber-100 text-amber-800' : 'bg-sky-100 text-sky-800' }}">
                        {{ $dir === 'ecom_to_erp' ? $ecomDisplayName . ' → ' . $erpDisplayName : $erpDisplayName . ' → ' . $ecomDisplayName }}
                    </span>
                </td>
                @endif

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
                        @if(($config->direction ?? 'erp_to_ecom') === 'ecom_to_erp')
                            <div class="text-xs space-y-0.5">
                                <span class="font-mono text-indigo-700 bg-indigo-50 px-1.5 py-0.5 rounded inline-block">{{ $config->ecom_field ?? $config->shopify_field }}</span>
                                <span class="text-gray-400 font-mono mx-1">{{ $config->combine_separator ?? ' ' }}</span>
                                <span class="font-mono text-indigo-700 bg-indigo-50 px-1.5 py-0.5 rounded inline-block">{{ $config->ecom_field_2 ?? $config->erp_field_2 ?? $config->odoo_field_2 }}</span>
                                <span class="text-gray-400 mx-1">→</span>
                                <span class="font-mono text-gray-700 bg-gray-100 px-1.5 py-0.5 rounded inline-block">{{ $config->erp_field ?? $config->odoo_field }}</span>
                            </div>
                        @else
                            <div class="text-xs space-y-0.5">
                                <span class="font-mono text-gray-700 bg-gray-100 px-1.5 py-0.5 rounded inline-block">{{ $config->erp_field ?? $config->odoo_field }}</span>
                                <span class="text-gray-400 font-mono mx-1">{{ $config->combine_separator ?? ' ' }}</span>
                                <span class="font-mono text-gray-700 bg-gray-100 px-1.5 py-0.5 rounded inline-block">{{ $config->erp_field_2 ?? $config->odoo_field_2 }}</span>
                            </div>
                        @endif
                    @elseif($config->field_type === 'custom')
                        @if(($config->direction ?? 'erp_to_ecom') === 'ecom_to_erp' && ($config->erp_field ?? $config->odoo_field))
                            <div class="font-mono text-xs text-gray-700 bg-gray-100 px-2 py-0.5 rounded inline-block">{{ $config->erp_field ?? $config->odoo_field }}</div>
                            @if($config->default_value)
                                <div class="text-xs text-purple-600 mt-0.5 font-mono">{{ $config->default_value }}</div>
                            @endif
                        @else
                            <span class="text-xs text-purple-600 font-mono bg-purple-50 px-1.5 py-0.5 rounded">{{ $config->default_value ?: '—' }}</span>
                        @endif
                    @else
                        <span class="text-gray-300">—</span>
                    @endif
                </td>

                <td class="px-4 py-3">
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600">
                        {{ $config->scope }}
                    </span>
                </td>

                <td class="px-4 py-3 text-xs text-gray-500 font-mono max-w-[180px] truncate" title="{{ $config->conditions }}">
                    {{ $config->conditions ?: '—' }}
                </td>
                <td class="px-4 py-3 text-xs text-gray-600 max-w-[200px] truncate" title="{{ $config->transform }}">
                    {{ \App\Services\Config\FieldTransformRegistry::labelFor($config->transform) }}
                </td>
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

                <td class="px-4 py-3 text-right whitespace-nowrap">
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
                <td colspan="{{ $directionLocked ? 10 : 11 }}" class="px-4 py-12 text-center text-gray-400 text-sm">
                    No field mappings for <strong>{{ $syncModeLabel }}</strong> on <strong>{{ $entity->label }}</strong>.
                    Click <strong>Add Mapping</strong> or run the SQL seed for this direction.
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
    </div>
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

            {{-- Sync Direction — locked to Global Settings unless bidirectional --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Sync Direction</label>
                @if($directionLocked)
                    <input type="hidden" name="direction" value="{{ $defaultDirection }}">
                    <div class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 bg-gray-50 text-gray-700">
                        {{ $defaultDirection === 'ecom_to_erp' ? $ecomDisplayName . ' → ' . $erpDisplayName : $erpDisplayName . ' → ' . $ecomDisplayName }}
                    </div>
                    <p class="text-xs text-gray-400 mt-1">Set by <strong>{{ $entity->label }}</strong> sync mode in Global Settings.</p>
                @else
                    <select name="direction" x-model="form.direction"
                            class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 outline-none">
                        <option value="erp_to_ecom">{{ $erpDisplayName }} → {{ $ecomDisplayName }}</option>
                        <option value="ecom_to_erp">{{ $ecomDisplayName }} → {{ $erpDisplayName }}</option>
                    </select>
                    <p class="text-xs text-gray-400 mt-1">Bidirectional sync — pick which push direction this row applies to.</p>
                @endif
            </div>

            {{-- Ecom Field (source for default/combine; target for erp→ecom custom/combine) --}}
            <div x-show="form.field_type !== 'custom' || form.direction !== 'ecom_to_erp'">
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    <span x-text="form.field_type === 'custom' ? '{{ $ecomDisplayName }} Field (target)' : (form.field_type === 'combine' && form.direction === 'ecom_to_erp' ? '{{ $ecomDisplayName }} Field 1' : (form.field_type === 'combine' ? '{{ $ecomDisplayName }} Field (target)' : '{{ $ecomDisplayName }} Field'))"></span>
                    <span class="text-red-500">*</span>
                </label>
                @php
                    // template_fields (product), variant_fields (product), or fields (all other entities)
                    $allEcomFields = array_merge(
                        !empty($ecomFields['template_fields']) ? $ecomFields['template_fields'] : ($ecomFields['fields'] ?? []),
                        $ecomFields['variant_fields'] ?? []
                    );
                @endphp

                <input list="ecomFieldOptions" type="text" :value="form.ecom_field"
                       @input="form.ecom_field = $event.target.value; onEcomFieldChange()" autocomplete="off"
                       placeholder="e.g. title, inventoryItem.sku, lineItems.0.quantity"
                       class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 outline-none font-mono">
                <datalist id="ecomFieldOptions">
                    @foreach($allEcomFields as $f)
                        <option value="{{ $f['key'] }}">{{ $f['label'] }}</option>
                    @endforeach
                </datalist>

                <p class="text-xs text-gray-400 mt-1">The real payload path — use dot notation for nested fields (same as product sync).</p>
                <div class="flex justify-end mt-1" x-show="form.field_type === 'default' || form.field_type === 'combine'">
                    <button type="button" @click="showConditions = !showConditions"
                            class="text-xs text-indigo-600 hover:text-indigo-800 font-medium">
                        <span x-text="showConditions ? '− Hide Conditions' : '+ Add Condition'"></span>
                    </button>
                </div>
            </div>

            {{-- Always submit Alpine-bound values (outside x-show blocks) --}}
            <input type="hidden" name="ecom_field"       :value="form.ecom_field">
            <input type="hidden" name="ecom_field_label" :value="form.ecom_field_label">

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
                    <option value="default">Default — map from a single source field</option>
                    <option value="combine">Combine — merge two source fields into one target</option>
                    <option value="custom">Custom — send a fixed value to a target field</option>
                </select>
                <p class="text-xs text-gray-400 mt-1" x-show="form.field_type === 'custom'">
                    Custom: enter a fixed value, or <strong>leave blank to send null</strong> (e.g. changeFromQuantity).
                </p>
            </div>

            <input type="hidden" name="ecom_field_2"       :value="form.ecom_field_2">
            <input type="hidden" name="ecom_field_2_label" :value="form.ecom_field_2_label">
            <input type="hidden" name="erp_field"         :value="form.erp_field">
            <input type="hidden" name="erp_field_label"   :value="form.erp_field_label">
            <input type="hidden" name="erp_field_2"       :value="form.erp_field_2">
            <input type="hidden" name="erp_field_2_label" :value="form.erp_field_2_label">
            <input type="hidden" name="combine_separator"  :value="form.combine_separator">
            <input type="hidden" name="default_value"      :value="form.default_value">

            {{-- ERP target (ecom→erp) or ERP source (erp→ecom) --}}
            <div x-show="form.field_type === 'default' || form.field_type === 'combine' || (form.field_type === 'custom' && form.direction === 'ecom_to_erp')">
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    <span x-text="form.direction === 'ecom_to_erp'
                        ? (form.field_type === 'combine' ? '{{ $erpDisplayName }} Field (target)' : (form.field_type === 'custom' ? '{{ $erpDisplayName }} Field (target)' : '{{ $erpDisplayName }} Field (target)'))
                        : (form.field_type === 'combine' ? '{{ $erpDisplayName }} Field 1' : '{{ $erpDisplayName }} Field')"></span>
                    <span x-show="form.field_type === 'custom'" class="text-red-500">*</span>
                </label>
                @php $erpFieldList = $erpFields['fields'] ?? array_merge($erpFields['template_fields'] ?? [], $erpFields['variant_fields'] ?? []); @endphp

                <input list="erpFieldOptions" type="text" :value="form.erp_field"
                       @input="form.erp_field = $event.target.value; onErpFieldChange()" autocomplete="off"
                       placeholder="e.g. item_name, item_code, standard_rate, contact_email"
                       class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 outline-none font-mono">
                <datalist id="erpFieldOptions">
                    @foreach($erpFieldList as $f)
                        <option value="{{ $f['key'] }}">{{ $f['label'] }}</option>
                    @endforeach
                </datalist>
                <p class="text-xs text-gray-400 mt-1"
                   x-text="form.field_type === 'custom'
                       ? 'The fixed value below will always be written to this {{ $erpDisplayName }} field.'
                       : 'Type the exact {{ $erpDisplayName }} field path (dot notation for child tables).'"></p>
            </div>

            {{-- Value conditions (source:target pairs) --}}
            <div x-show="showConditions && (form.field_type === 'default' || form.field_type === 'combine')"
                 class="rounded-lg border border-indigo-100 bg-indigo-50/50 p-3 space-y-2">
                <label class="block text-sm font-medium text-gray-700">Value Conditions</label>
                <textarea name="conditions" x-model="form.conditions" rows="3"
                          :placeholder="conditionsPlaceholder()"
                          class="w-full text-sm font-mono border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 outline-none"></textarea>
                <p class="text-xs text-gray-500" x-html="conditionsHelp()"></p>
            </div>

            {{-- Transform (default/combine rows only) --}}
            <div x-show="form.field_type === 'default' || form.field_type === 'combine'"
                 class="rounded-lg border border-gray-200 bg-gray-50/80 p-3 space-y-2">
                <label class="block text-sm font-medium text-gray-700">Transform</label>
                <select name="transform_base" x-model="form.transform_base"
                        @change="onTransformBaseChange()"
                        class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 outline-none">
                    <option value="">None</option>
                    <template x-for="opt in filteredTransforms()" :key="opt.value">
                        <option :value="opt.value" x-text="opt.label"></option>
                    </template>
                </select>
                <div x-show="selectedTransformDef() && selectedTransformDef().param_label">
                    <label class="block text-xs font-medium text-gray-600 mb-1"
                           x-text="selectedTransformDef().param_label"></label>
                    <select x-show="selectedTransformDef().param_options && selectedTransformDef().param_options.length"
                            x-model="form.transform_param"
                            class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 outline-none">
                        <option value="">— Select map type —</option>
                        <template x-for="opt in (selectedTransformDef().param_options || [])" :key="opt.value">
                            <option :value="opt.value" x-text="opt.label"></option>
                        </template>
                    </select>
                    <input type="text"
                           x-show="!selectedTransformDef().param_options || !selectedTransformDef().param_options.length"
                           x-model="form.transform_param"
                           :placeholder="selectedTransformDef()?.param_hint || ''"
                           class="w-full text-sm font-mono border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 outline-none">
                    <input type="hidden" name="transform_param" :value="form.transform_param">
                </div>
                <p class="text-xs text-gray-500">
                    <span x-show="form.direction === 'ecom_to_erp'">Runs when pushing <strong>{{ $ecomDisplayName }} → {{ $erpDisplayName }}</strong>.</span>
                    <span x-show="form.direction !== 'ecom_to_erp'">Runs when pushing <strong>{{ $erpDisplayName }} → {{ $ecomDisplayName }}</strong>.</span>
                    Works with any ERP/ecom driver — each adapter implements the lookup.
                </p>
            </div>

            {{-- Ecom Field 2 (ecom→erp combine) --}}
            <div x-show="form.field_type === 'combine' && form.direction === 'ecom_to_erp'" class="space-y-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ $ecomDisplayName }} Field 2</label>
                    <input list="ecomFieldOptions" type="text" :value="form.ecom_field_2"
                           @input="form.ecom_field_2 = $event.target.value" autocomplete="off"
                           placeholder="e.g. lastName"
                           class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 outline-none font-mono">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Separator</label>
                    <input type="text" @input="form.combine_separator = $event.target.value" :value="form.combine_separator"
                           placeholder="e.g. space, -, /"
                           class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 outline-none font-mono">
                </div>
            </div>

            {{-- ERP Field 2 + Separator (erp→ecom combine) --}}
            <div x-show="form.field_type === 'combine' && form.direction !== 'ecom_to_erp'" class="space-y-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ $erpDisplayName }} Field 2</label>
                    <input list="erpFieldOptions" type="text" :value="form.erp_field_2"
                           @input="form.erp_field_2 = $event.target.value" autocomplete="off"
                           placeholder="e.g. description_sale"
                           class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 outline-none font-mono">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Separator</label>
                    <input type="text" @input="form.combine_separator = $event.target.value" :value="form.combine_separator"
                           placeholder="e.g. - or / or space"
                           class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 outline-none font-mono">
                </div>
            </div>

            {{-- Custom fixed value (blank = null) --}}
            <div x-show="form.field_type === 'custom'">
                <label class="block text-sm font-medium text-gray-700 mb-1">Fixed Value</label>
                <input type="text" @input="form.default_value = $event.target.value" :value="form.default_value"
                       placeholder="e.g. available, correction — leave blank for null"
                       class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 outline-none">
                <p class="text-xs text-gray-400 mt-1">Leave blank to send <code>null</code> (e.g. changeFromQuantity).</p>
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
                    <input type="number" x-model.number="form.sort_order" min="0"
                           class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 outline-none">
                    <input type="hidden" name="sort_order" :value="Number(form.sort_order) || 0">
                    <p class="text-xs text-gray-400 mt-1">1 = first row. Leave 0 for default order.</p>
                </div>
                <div class="flex items-center gap-2 pb-2">
                    <input type="checkbox" name="is_active" value="1" id="modal_is_active"
                           :checked="form.is_active" @change="form.is_active = $event.target.checked"
                           class="rounded text-indigo-600">
                    <label for="modal_is_active" class="text-sm text-gray-700 cursor-pointer">Active</label>
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
        showConditions: false,

        transformOptions: @json($transformOptions),
        entityType: @json($entityType),
        directionLocked: @json($directionLocked),
        defaultDirection: @json($defaultDirection),

        ecomFields: @json(array_merge(!empty($ecomFields['template_fields']) ? $ecomFields['template_fields'] : ($ecomFields['fields'] ?? []), $ecomFields['variant_fields'] ?? [])),
        erpFields:  @json($erpFields['fields'] ?? array_merge($erpFields['template_fields'] ?? [], $erpFields['variant_fields'] ?? [])),

        form: {
            direction: '{{ $defaultDirection ?? 'erp_to_ecom' }}',
            ecom_field: '', ecom_field_label: '',
            field_type: 'default',
            erp_field: '', erp_field_label: '',
            ecom_field_2: '', ecom_field_2_label: '',
            erp_field_2: '', erp_field_2_label: '',
            combine_separator: ' ',
            scope: '{{ $entity->scopes[0] ?? "header" }}',
            default_value: '', conditions: '',
            transform_base: '', transform_param: '',
            min_length: '', max_length: '',
            sort_order: 0, is_active: true,
        },

        init() {},

        openAdd() {
            this.editId = null;
            this.showConditions = false;
            this.form = {
                direction: '{{ $defaultDirection ?? 'erp_to_ecom' }}',
                ecom_field: '', ecom_field_label: '',
                ecom_cast: '',
                field_type: 'default',
                erp_field: '', erp_field_label: '',
                erp_field_2: '', erp_field_2_label: '',
                combine_separator: ' ',
                scope: '{{ $entity->scopes[0] ?? "header" }}',
                default_value: '', conditions: '',
                transform_base: '', transform_param: '',
                min_length: '', max_length: '',
                sort_order: 0, is_active: true,
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
                direction:         this.directionLocked ? this.defaultDirection : (config.direction || 'erp_to_ecom'),
                ecom_field:        config.ecom_field        || config.shopify_field       || '',
                ecom_field_label:  config.ecom_field_label  || config.shopify_field_label || '',
                field_type:        config.field_type        || 'default',
                erp_field:         config.erp_field         || config.odoo_field          || '',
                erp_field_label:   config.erp_field_label   || config.odoo_field_label    || '',
                ecom_field_2:      config.ecom_field_2      || '',
                ecom_field_2_label: config.ecom_field_2_label || '',
                erp_field_2:       config.erp_field_2       || config.odoo_field_2        || '',
                erp_field_2_label: config.erp_field_2_label || config.odoo_field_2_label  || '',
                combine_separator: config.combine_separator || ' ',
                scope:             config.scope             || '{{ $entity->scopes[0] ?? "header" }}',
                default_value:     (config.default_value === '__NULL__') ? '' : (config.default_value || ''),
                conditions:        config.conditions        || '',
                transform_base:    '',
                transform_param:   '',
                min_length:        config.min_length        || '',
                max_length:        config.max_length        || '',
                sort_order:        config.sort_order ?? 0,
                is_active:         config.is_active,
            };
            this.showConditions = !!(config.conditions && String(config.conditions).trim());
            const parsed = this.parseTransform(config.transform || '');
            this.form.transform_base = parsed.base;
            this.form.transform_param = parsed.param;
            this.$nextTick(() => {
                this.$refs.form.action = '{{ url('dashboard/product-field-config') }}/' + config.id;
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

        conditionsPlaceholder() {
            return this.form.direction === 'ecom_to_erp'
                ? 'active:1, draft:0'
                : '1:ACTIVE, 0:DRAFT';
        },

        conditionsHelp() {
            if (this.form.direction === 'ecom_to_erp') {
                return '<strong>{{ $ecomDisplayName }} value : {{ $erpDisplayName }} value</strong> — e.g. <code class="font-mono">active:1, draft:0</code>. Many2one <code class="font-mono">[20, "INR"]</code> matches on id or label. Image URLs to <code class="font-mono">image_*</code> fields are fetched automatically.';
            }
            return '<strong>{{ $erpDisplayName }} value : {{ $ecomDisplayName }} value</strong> — e.g. <code class="font-mono">1:ACTIVE, 0:DRAFT</code>. ERP paths: <code class="font-mono">vendors.partner_id.1</code>, <code class="font-mono">uom_id.1</code>. Unit/value mapping = your Conditions on each row.';
        },

        filteredTransforms() {
            return this.transformOptions.filter(t => {
                if (!(t.directions || []).includes(this.form.direction)) {
                    return false;
                }

                const entities = t.entities || [];
                if (entities.length && !entities.includes(this.entityType)) {
                    return false;
                }

                return true;
            });
        },

        selectedTransformDef() {
            return this.transformOptions.find(t => t.value === this.form.transform_base) || null;
        },

        parseTransform(stored) {
            if (!stored) return { base: '', param: '' };
            if (stored.startsWith('channel_map:')) {
                return { base: 'channel_map', param: stored.slice('channel_map:'.length) };
            }
            if (stored.startsWith('resolve_state_id:')) {
                return { base: 'resolve_state_id', param: stored.slice('resolve_state_id:'.length) };
            }
            if (stored.startsWith('resolve_state_code:')) {
                return { base: 'resolve_state_code', param: stored.slice('resolve_state_code:'.length) };
            }
            if (stored.startsWith('resolve_partner:')) {
                return { base: 'resolve_partner', param: stored.slice('resolve_partner:'.length) };
            }
            if (stored.startsWith('sync_mapping:')) {
                return { base: 'sync_mapping', param: stored.slice('sync_mapping:'.length) };
            }
            return { base: stored, param: '' };
        },

        onTransformBaseChange() {
            this.form.transform_param = '';
            if (this.form.transform_base) {
                this.form.default_value = '';
            }
        },
    };
}
</script>
@endsection