{{--
    Mapping modal form — shared by Add and Edit.
    Parent (mappingPage) exposes: form.*, erpFields[], ecomFields[],
    erpLoading, ecomLoading, fetchErpFields(), fetchEcomFields().
    Both fetch endpoints return [{ id, label }].
--}}

{{-- Channel --}}
<div>
    <label class="block text-xs font-medium text-gray-600 mb-1">Channel <span class="text-red-500">*</span></label>
    <select name="channel" :value="form.channel" @change="form.channel = $event.target.value"
            class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 outline-none">
        <option value="shopify">{{ $ecomDisplayName }}</option>
        <option value="amazon">Amazon</option>
        <option value="both">Both</option>
    </select>
</div>

{{-- Odoo category --}}
<div>
    <label class="block text-xs font-medium text-gray-600 mb-1">
        {{ $erpDisplayName }} {{ $typeNoun }} <span class="text-red-500">*</span>
        <button type="button" @click="fetchErpFields()"
                class="ml-1 text-indigo-500 hover:text-indigo-700 text-xs underline"
                x-text="erpLoading ? 'Loading…' : 'Refresh'"></button>
    </label>
    <template x-if="erpFields.length > 0">
        <select :value="form.odoo_id"
                @change="form.odoo_id = $event.target.value; form.odoo_label = erpFields.find(f => f.id == $event.target.value)?.label ?? ''"
                class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 outline-none">
            <option value="">— Select {{ $erpDisplayName }} {{ $typeNoun }}   </option>
            <template x-for="f in erpFields" :key="f.id">
                <option :value="f.id" :selected="form.odoo_id == f.id"
                        x-text="f.label + (String(f.id) !== String(f.label) ? ' (' + f.id + ')' : '')"></option>
            </template>
        </select>
    </template>
    <template x-if="erpFields.length === 0">
        <input type="text" :value="form.odoo_id" @input="form.odoo_id = $event.target.value"
               placeholder="Loading… or type an ERP warehouse / category id"
               class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 outline-none">
    </template>
    <p class="text-xs text-gray-400 mt-1" x-show="form.odoo_label" x-text="'Selected: ' + form.odoo_label"></p>
</div>

{{-- Shopify category --}}
<div>
    <label class="block text-xs font-medium text-gray-600 mb-1">
        {{ $ecomDisplayName }} {{ $typeNoun }} <span class="text-red-500">*</span>
        <button type="button" @click="fetchEcomFields()"
                class="ml-1 text-indigo-500 hover:text-indigo-700 text-xs underline"
                x-text="ecomLoading ? 'Loading…' : 'Refresh'"></button>
    </label>
    <template x-if="ecomFields.length > 0">
        <select :value="form.external_id"
                @change="form.external_id = $event.target.value; form.external_label = ecomFields.find(f => f.id == $event.target.value)?.label ?? ''"
                class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 outline-none">
            <option value="">— Select {{ $ecomDisplayName }} {{ $typeNoun }} —</option>
            <template x-for="f in ecomFields" :key="f.id">
                <option :value="f.id" :selected="form.external_id == f.id" x-text="f.label"></option>
            </template>
        </select>
    </template>
    <template x-if="ecomFields.length === 0">
        <input type="text" :value="form.external_id" @input="form.external_id = $event.target.value"
               placeholder="Loading… or paste a TaxonomyCategory GID"
               class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 outline-none">
    </template>
    <p class="text-xs text-gray-400 mt-1" x-show="form.external_label" x-text="'Selected: ' + form.external_label"></p>
</div>

{{-- Hidden submits — the four columns store()/update() require --}}
<input type="hidden" name="odoo_id"        :value="form.odoo_id">
<input type="hidden" name="odoo_label"     :value="form.odoo_label">
<input type="hidden" name="external_id"    :value="form.external_id">
<input type="hidden" name="external_label" :value="form.external_label">

{{-- Default + Active --}}
<div class="grid grid-cols-2 gap-4">
    <div>
        <label class="block text-xs font-medium text-gray-600 mb-1">Default Value</label>
        <input type="text" name="default_value" :value="form.default_value" @input="form.default_value = $event.target.value"
               placeholder="Fallback if no match"
               class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-300 outline-none">
    </div>
    <div class="flex items-end pb-2">
        <label class="flex items-center gap-2 text-sm text-gray-600 cursor-pointer">
            <input type="checkbox" name="is_active" value="1" :checked="form.is_active"
                   @change="form.is_active = $event.target.checked" class="rounded text-indigo-600">
            Active
        </label>
    </div>
</div>