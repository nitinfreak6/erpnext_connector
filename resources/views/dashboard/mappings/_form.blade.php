{{--
    Mapping modal form — shared by Add and Edit.
    Alpine parent must expose: form.* (all fields), erpFields[], ecomFields[].
--}}

{{-- Row 1: ERP Save ID (channel) + Ecom Save ID (external_id) --}}
<div class="grid grid-cols-2 gap-4">
    <div>
        <label class="block text-xs font-medium text-gray-600 mb-1">
            {{ $erpDisplayName }} Save ID <span class="text-red-500">*</span>
        </label>
        <select name="channel" :value="form.channel" @change="form.channel = $event.target.value"
                class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 outline-none">
            <option value="shopify">{{ $ecomDisplayName }}</option>
            <option value="amazon">Amazon</option>
            <option value="both">Both</option>
        </select>
    </div>
    <div>
        <label class="block text-xs font-medium text-gray-600 mb-1">
            {{ $ecomDisplayName }} Save ID <span class="text-red-500">*</span>
        </label>
        {{-- Dropdown if ecom fields loaded, else text input --}}
        <template x-if="ecomFields.length > 0">
            <select name="external_id" :value="form.external_id" @change="form.external_id = $event.target.value"
                    class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 outline-none">
                <option value="">— Select —</option>
                <template x-for="f in ecomFields" :key="f.key">
                    <option :value="f.key" :selected="form.external_id === f.key" x-text="f.label + ' (' + f.key + ')'"></option>
                </template>
            </select>
        </template>
        <template x-if="ecomFields.length === 0">
            <input type="text" name="external_id" :value="form.external_id" @input="form.external_id = $event.target.value"
                   placeholder="e.g. shopify_payments"
                   class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 outline-none">
        </template>
    </div>
</div>

{{-- Row 2: ERP Field (id from Odoo) + ERP Label --}}
<div class="grid grid-cols-2 gap-4">
    <div>
        <label class="block text-xs font-medium text-gray-600 mb-1">
            {{ $erpDisplayName }} Field
            <button type="button" @click="fetchErpFields()"
                    class="ml-1 text-indigo-500 hover:text-indigo-700 text-xs underline" x-text="erpLoading ? 'Loading…' : 'Fetch'"></button>
        </label>
        <template x-if="erpFields.length > 0">
            <select name="odoo_id" :value="form.odoo_id" @change="form.odoo_id = $event.target.value; form.odoo_label = erpFields.find(f=>f.id===$event.target.value)?.label ?? form.odoo_label"
                    class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 outline-none">
                <option value="">— Select {{ $erpDisplayName }} record —</option>
                <template x-for="f in erpFields" :key="f.id">
                    <option :value="f.id" :selected="form.odoo_id === f.id" x-text="f.label + ' (#' + f.id + ')'"></option>
                </template>
            </select>
        </template>
        <template x-if="erpFields.length === 0">
            <input type="text" name="odoo_id" :value="form.odoo_id" @input="form.odoo_id = $event.target.value"
                   placeholder="e.g. 5"
                   class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 outline-none">
        </template>
    </div>
    <div>
        <label class="block text-xs font-medium text-gray-600 mb-1">{{ $erpDisplayName }} Label</label>
        <input type="text" name="odoo_label" :value="form.odoo_label" @input="form.odoo_label = $event.target.value"
               placeholder="e.g. WH/Stock"
               class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 outline-none">
    </div>
</div>

{{-- Row 3: ERP Value Field + Ecom Field --}}
<div class="grid grid-cols-2 gap-4">
    <div>
        <label class="block text-xs font-medium text-gray-600 mb-1">{{ $erpDisplayName }} Value Field</label>
        <input type="text" name="odoo_value_field" :value="form.odoo_value_field" @input="form.odoo_value_field = $event.target.value"
               placeholder="e.g. id, name"
               class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 outline-none">
    </div>
    <div>
        <label class="block text-xs font-medium text-gray-600 mb-1">
            {{ $ecomDisplayName }} Field
            <button type="button" @click="fetchEcomFields()"
                    class="ml-1 text-indigo-500 hover:text-indigo-700 text-xs underline" x-text="ecomLoading ? 'Loading…' : 'Fetch'"></button>
        </label>
        <input type="text" name="external_label" :value="form.external_label" @input="form.external_label = $event.target.value"
               placeholder="e.g. Main Warehouse"
               class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 outline-none">
    </div>
</div>

{{-- Row 4: Ecom Value Field + Default Value --}}
<div class="grid grid-cols-2 gap-4">
    <div>
        <label class="block text-xs font-medium text-gray-600 mb-1">{{ $ecomDisplayName }} Value Field</label>
        <template x-if="ecomFields.length > 0">
            <select name="external_value_field" :value="form.external_value_field" @change="form.external_value_field = $event.target.value"
                    class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 outline-none">
                <option value="">— Select field —</option>
                <template x-for="f in ecomFields" :key="f.key">
                    <option :value="f.key" :selected="form.external_value_field === f.key" x-text="f.label + ' (' + f.key + ')'"></option>
                </template>
            </select>
        </template>
        <template x-if="ecomFields.length === 0">
            <input type="text" name="external_value_field" :value="form.external_value_field" @input="form.external_value_field = $event.target.value"
                   placeholder="e.g. id, title"
                   class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 outline-none">
        </template>
    </div>
    <div>
        <label class="block text-xs font-medium text-gray-600 mb-1">Default Value</label>
        <input type="text" name="default_value" :value="form.default_value" @input="form.default_value = $event.target.value"
               placeholder="Fallback if no match"
               class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 outline-none">
    </div>
</div>

{{-- Row 5: Min Length + Max Length --}}
<div class="grid grid-cols-2 gap-4">
    <div>
        <label class="block text-xs font-medium text-gray-600 mb-1">Min Length (Prefix Value)</label>
        <input type="text" name="min_length" :value="form.min_length" @input="form.min_length = $event.target.value"
               placeholder="e.g. 3"
               class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 outline-none">
    </div>
    <div>
        <label class="block text-xs font-medium text-gray-600 mb-1">Max Length</label>
        <input type="text" name="max_length" :value="form.max_length" @input="form.max_length = $event.target.value"
               placeholder="e.g. 255"
               class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 outline-none">
    </div>
</div>

<label class="flex items-center gap-2 text-sm text-gray-600 cursor-pointer">
    <input type="checkbox" name="is_active" value="1"
           :checked="form.is_active"
           @change="form.is_active = $event.target.checked"
           class="rounded text-indigo-600">
    Active
</label>