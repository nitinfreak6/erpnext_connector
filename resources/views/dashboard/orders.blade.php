@extends('dashboard.layout')
@section('title', 'Orders')
@section('page-title', 'Order listing')

@section('content')

<div x-data="orderSyncPage">

<div x-show="pageLoading"
     x-cloak
     style="display: none;"
     class="fixed inset-0 z-[200] flex items-center justify-center bg-gray-900/40 backdrop-blur-[1px]"
     aria-live="polite"
     aria-busy="true">
    <div class="bg-white rounded-xl shadow-2xl px-8 py-6 flex flex-col items-center gap-3 min-w-[220px]">
        <svg class="animate-spin h-10 w-10 text-indigo-600" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
        </svg>
        <p class="text-sm font-medium text-gray-700" x-text="pageLoadingMessage || 'Working…'"></p>
    </div>
</div>

{{-- AJAX Toast --}}
<div x-show="toast.show"
     x-cloak
     style="display: none;"
     x-transition:enter="transition ease-out duration-200"
     x-transition:enter-start="opacity-0 -translate-y-2"
     x-transition:enter-end="opacity-100 translate-y-0"
     class="fixed top-4 right-4 z-50 max-w-md shadow-lg rounded-lg px-4 py-3 text-sm flex items-start gap-2"
     :class="{
         'bg-emerald-50 border border-emerald-200 text-emerald-700': toast.level === 'success',
         'bg-blue-50 border border-blue-200 text-blue-700': toast.level === 'info',
         'bg-yellow-50 border border-yellow-200 text-yellow-800': toast.level === 'warning',
         'bg-red-50 border border-red-200 text-red-700': toast.level === 'error',
     }">
    <span x-text="toast.message" class="flex-1"></span>
    <button type="button" @click="toast.show = false" class="opacity-60 hover:opacity-100 shrink-0">&times;</button>
</div>

@foreach(['success' => 'emerald', 'error' => 'red', 'info' => 'blue', 'warning' => 'amber'] as $type => $color)
@if(session($type))
<div class="mb-4 bg-{{ $color }}-50 border border-{{ $color }}-200 text-{{ $color }}-700 px-4 py-3 rounded-lg text-sm flex items-center gap-2">
    {!! session($type) !!}
</div>
@endif
@endforeach

{{-- Sync Direction --}}
<div class="mb-4 bg-gradient-to-r from-indigo-50 to-purple-50 border border-indigo-200 rounded-xl p-4">
    <div class="flex items-center justify-between">
        <div>
            <h3 class="text-sm font-semibold text-gray-700">Sync Direction</h3>
            <p class="text-xs text-gray-500 mt-0.5">
                @if($syncMode === 'erp_to_ecom')
                    Orders created in <strong>{{ $erpDisplayName }}</strong>, synced to <strong>{{ $ecomDisplayName }}</strong>
                @elseif($syncMode === 'ecom_to_erp')
                    Orders imported from <strong>{{ $ecomDisplayName }}</strong> to <strong>{{ $erpDisplayName }}</strong>
                @else
                    Orders flow in <strong>both directions</strong>
                @endif
            </p>
        </div>
        <div>
            @if($syncMode === 'erp_to_ecom')
                <span class="px-3 py-1.5 bg-indigo-100 text-indigo-700 rounded-lg text-xs font-medium">{{ $erpDisplayName }} → {{ $ecomDisplayName }}</span>
            @elseif($syncMode === 'ecom_to_erp')
                <span class="px-3 py-1.5 bg-emerald-100 text-emerald-700 rounded-lg text-xs font-medium">{{ $ecomDisplayName }} → {{ $erpDisplayName }}</span>
            @else
                <span class="px-3 py-1.5 bg-purple-100 text-purple-700 rounded-lg text-xs font-medium">⟷ Bidirectional</span>
            @endif
        </div>
    </div>
</div>

{{-- Filters --}}
<div class="bg-white rounded-xl border border-gray-200 p-4 mb-4 shadow-sm">
    <form method="GET" class="flex flex-wrap items-end gap-3">
        @if($syncMode === 'bidirectional')
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Direction</label>
            <select name="direction" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-indigo-300 outline-none bg-white">
                <option value="erp_to_ecom" {{ ($direction ?? 'erp_to_ecom') === 'erp_to_ecom' ? 'selected' : '' }}>{{ $erpDisplayName }} → {{ $ecomDisplayName }}</option>
                <option value="ecom_to_erp" {{ ($direction ?? '') === 'ecom_to_erp' ? 'selected' : '' }}>{{ $ecomDisplayName }} → {{ $erpDisplayName }}</option>
            </select>
        </div>
        @endif
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Search</label>
            <input type="text" name="search" value="{{ $search }}" placeholder="Order ID, reference…" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm w-64 focus:ring-2 focus:ring-indigo-300 outline-none">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Status</label>
            <select name="status" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-indigo-300 outline-none bg-white">
                <option value="all" {{ ($status ?? 'all') === 'all' ? 'selected' : '' }}>All</option>
                <option value="pending" {{ ($status ?? '') === 'pending' ? 'selected' : '' }}>Pending</option>
                <option value="updated" {{ ($status ?? '') === 'updated' ? 'selected' : '' }}>Updated</option>
                <option value="sent" {{ in_array($status ?? '', ['sent', 'success'], true) ? 'selected' : '' }}>Sent</option>
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Channel</label>
            <select name="channel" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-indigo-300 outline-none bg-white">
                <option value="all" {{ $channel === 'all' ? 'selected' : '' }}>All</option>
                <option value="shopify" {{ $channel === 'shopify' ? 'selected' : '' }}>{{ $ecomDisplayName }}</option>
                <option value="amazon" {{ $channel === 'amazon' ? 'selected' : '' }}>Amazon</option>
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Per Page</label>
            <select name="per_page" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-indigo-300 outline-none bg-white">
                @foreach([25, 50, 100] as $n)
                <option value="{{ $n }}" {{ $perPage == $n ? 'selected' : '' }}>{{ $n }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="bg-indigo-600 text-white px-4 py-[7px] rounded-lg text-sm hover:bg-indigo-700 transition">Search</button>
        <a href="{{ route('dashboard.orders') }}" class="bg-gray-400 text-white px-4 py-[7px] rounded-lg text-sm hover:bg-gray-500 transition">Reset</a>
    </form>
</div>

{{-- Sales sync actions --}}
<div class="flex flex-wrap items-center justify-end gap-3 mb-5">
    @if($syncMode === 'ecom_to_erp' || $syncMode === 'bidirectional')
    <button type="button"
            @click="run('pull')"
            :disabled="!!loading.pull"
            class="inline-flex items-center gap-2 px-4 py-2 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-60 disabled:cursor-not-allowed text-white text-sm font-medium rounded-lg shadow-sm transition">
        <svg x-show="loading.pull" x-cloak class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
        <span x-text="loading.pull ? 'Fetching…' : labels.pull"></span>
    </button>
    @endif

    @if($syncMode === 'erp_to_ecom' || $syncMode === 'bidirectional')
    <button type="button"
            @click="run('fetch')"
            :disabled="!!loading.fetch"
            class="inline-flex items-center gap-2 px-4 py-2 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-60 disabled:cursor-not-allowed text-white text-sm font-medium rounded-lg shadow-sm transition">
        <svg x-show="loading.fetch" x-cloak class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
        <span x-text="loading.fetch ? 'Fetching…' : labels.fetch"></span>
    </button>
    @endif

    @if($syncMode === 'ecom_to_erp' || $syncMode === 'bidirectional')
    <button type="button"
            @click="run('postErp')"
            :disabled="!!loading.postErp"
            class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-60 disabled:cursor-not-allowed text-white text-sm font-medium rounded-lg shadow-sm transition">
        <svg x-show="loading.postErp" x-cloak class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
        <span x-text="loading.postErp ? 'Pushing…' : labels.postErp"></span>
    </button>
    @endif

    @if($syncMode === 'erp_to_ecom' || $syncMode === 'bidirectional')
    <button type="button"
            @click="run('postEcom')"
            :disabled="!!loading.postEcom"
            class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-60 disabled:cursor-not-allowed text-white text-sm font-medium rounded-lg shadow-sm transition">
        <svg x-show="loading.postEcom" x-cloak class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
        <span x-text="loading.postEcom ? 'Pushing…' : labels.postEcom"></span>
    </button>
    @endif

    {{-- Dispatch — follows sales order sync direction --}}
    @if($syncMode === 'erp_to_ecom' || $syncMode === 'ecom_to_erp' || $syncMode === 'bidirectional')
    <div class="flex gap-2 items-center border-l border-gray-200 pl-3">
        <button type="button"
                @click="run('fetchDispatch')"
                :disabled="!!loading.fetchDispatch"
                class="inline-flex items-center gap-2 px-4 py-2 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-60 text-white text-sm font-medium rounded-lg shadow-sm transition">
            <span x-text="loading.fetchDispatch ? 'Fetching…' : labels.fetchDispatch"></span>
        </button>
        <button type="button"
                @click="run('postDispatch')"
                :disabled="!!loading.postDispatch"
                class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-60 text-white text-sm font-medium rounded-lg shadow-sm transition">
            <span x-text="loading.postDispatch ? 'Posting…' : labels.postDispatch"></span>
        </button>
    </div>
    @endif
</div>

{{-- Bulk delete bar --}}
<div x-show="selectedRows.length > 0"
     x-cloak
     class="flex flex-wrap items-center gap-3 mb-3 px-4 py-2.5 bg-red-50 border border-red-200 rounded-lg">
    <span class="text-sm font-medium text-red-800" x-text="selectedRows.length + ' selected'"></span>
    <button type="button"
            @click="confirmBulkDelete()"
            :disabled="pageLoading"
            class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-red-600 hover:bg-red-700 disabled:opacity-60 text-white text-xs font-medium rounded-lg transition">
        Delete selected
    </button>
    <button type="button" @click="selectedRows = []" class="text-xs text-red-700 hover:underline">Clear selection</button>
</div>

{{-- Table --}}
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-4">
    <div class="overflow-x-auto min-h-[200px]">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-200">
                <tr>
                    <th class="px-3 py-3 w-10 text-center">
                        <input type="checkbox"
                               class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 h-4 w-4 cursor-pointer"
                               :checked="allRowsSelected()"
                               :indeterminate.prop="someRowsSelected()"
                               @change="toggleSelectAll($event.target.checked)">
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Channel</th>
                    @if($syncMode === 'erp_to_ecom' || ($syncMode === 'bidirectional' && ($direction ?? 'erp_to_ecom') === 'erp_to_ecom'))
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">{{ $erpDisplayName }} ID</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">{{ $ecomDisplayName }} ID</th>
                    @else
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">{{ $ecomDisplayName }} ID</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">{{ $erpDisplayName }} ID</th>
                    @endif
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Reference</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider min-w-[160px]">Message</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Dispatch</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Last Synced</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody id="orders-table-body" class="divide-y divide-gray-100">
                @include('dashboard.partials.orders-table-rows', compact('orders', 'syncMode', 'direction', 'erpDisplayName', 'ecomDisplayName'))
            </tbody>
        </table>
    </div>

    @if($orders->hasPages())
    <div class="px-5 py-3 border-t border-gray-100 flex items-center justify-between bg-gray-50">
        <p class="text-xs text-gray-500">
            Showing {{ $orders->firstItem() }}–{{ $orders->lastItem() }} of {{ $orders->total() }}
        </p>
        {{ $orders->links() }}
    </div>
    @endif
</div>

</div>

@php
    $pushUrlTemplateEcom = str_replace('/0/push', '/{id}/push', route('dashboard.orders.push', ['erpId' => 0]));
    $pushUrlTemplateErp  = str_replace('post-single/0', 'post-single/{id}', route('dashboard.orders.post-single', ['ecomId' => '0']));
    $dispatchFetchTemplate = str_replace('/0/fetch-dispatch', '/{id}/fetch-dispatch', route('dashboard.orders.fetch-dispatch-single', ['erpId' => 0]));
    $dispatchPostTemplate  = str_replace('/0/post-dispatch', '/{id}/post-dispatch', route('dashboard.orders.post-dispatch-single', ['erpId' => 0]));
@endphp

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('orderSyncPage', () => ({
        csrfToken: @json(csrf_token()),
        loading: {},
        pageLoading: false,
        pageLoadingMessage: '',
        selectedRows: [],
        tableBodyId: 'orders-table-body',
        bulkDeleteUrl: @json(route('dashboard.orders.destroy-bulk')),
        deleteLabel: 'order',
        openToolId: null,
        toast: { show: false, level: 'success', message: '' },
        toastTimer: null,
        tableRowsUrl: @json(route('dashboard.orders.rows')),

        labels: {
            pull: @json('↓ Fetch from ' . $ecomDisplayName),
            fetch: @json('↓ Fetch from ' . $erpDisplayName),
            postErp: @json('↑ Post to ' . $erpDisplayName),
            postEcom: @json('↑ Post to ' . $ecomDisplayName),
            fetchDispatch: @json('↓ Fetch Dispatch'),
            postDispatch: @json('↑ Post Dispatch'),
        },

        listingDirection: @json($direction ?? 'erp_to_ecom'),

        pushUrlTemplateEcom: @json($pushUrlTemplateEcom),
        pushUrlTemplateErp: @json($pushUrlTemplateErp),
        dispatchFetchTemplate: @json($dispatchFetchTemplate),
        dispatchPostTemplate: @json($dispatchPostTemplate),
        ecomDisplayName: @json($ecomDisplayName),
        erpDisplayName: @json($erpDisplayName),

        actions: {
            pull: { url: @json(route('dashboard.orders.pull')), body: null },
            fetch: { url: @json(route('dashboard.orders.fetch')), body: null },
            postErp: { url: @json(route('dashboard.orders.post-sales')), body: { direction: 'ecom_to_erp' } },
            postEcom: { url: @json(route('dashboard.orders.post-sales')), body: { direction: 'erp_to_ecom' } },
            fetchDispatch: { url: @json(route('dashboard.orders.fetch-dispatch')), body: { direction: @json($direction ?? 'erp_to_ecom') } },
            postDispatch: { url: @json(route('dashboard.orders.post-dispatch')), body: { direction: @json($direction ?? 'erp_to_ecom') } },
        },

        run(actionKey) {
            const action = this.actions[actionKey];
            if (!action) return;
            this.runAction(actionKey, action.url, action);
        },

        setLoading(key, value) {
            this.loading = { ...this.loading, [key]: value };
        },

        pushSingleEcom(erpId, loadingKey) {
            const url = this.pushUrlTemplateEcom.replace('{id}', String(erpId));
            this.runAction(loadingKey, url, {});
        },

        pushSingleErp(ecomId, loadingKey) {
            const url = this.pushUrlTemplateErp.replace('{id}', encodeURIComponent(String(ecomId)));
            this.runAction(loadingKey, url, {});
        },

        dispatchFetch(erpId, loadingKey) {
            const url = this.dispatchFetchTemplate.replace('{id}', String(erpId));
            this.runAction(loadingKey, url, { body: { direction: this.listingDirection } });
        },

        dispatchPost(erpId, loadingKey) {
            const url = this.dispatchPostTemplate.replace('{id}', String(erpId));
            this.runAction(loadingKey, url, { body: { direction: this.listingDirection } });
        },

        toggleSelectAll(checked) {
            syncListing.toggleSelectAll(this, this.tableBodyId, checked);
        },

        allRowsSelected() {
            return syncListing.allRowsSelected(this, this.tableBodyId);
        },

        someRowsSelected() {
            return syncListing.someRowsSelected(this, this.tableBodyId);
        },

        confirmBulkDelete() {
            syncListing.confirmBulkDelete(this, this.bulkDeleteUrl, this.deleteLabel, this.ecomDisplayName, this.erpDisplayName);
        },

        initRows(container) {
            if (container && window.Alpine) {
                window.Alpine.initTree(container);
            }
        },

        async refreshTable() {
            const query = window.location.search || '';
            const res = await fetch(this.tableRowsUrl + query, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data.html) return;
            const tbody = document.getElementById('orders-table-body');
            if (tbody) {
                tbody.innerHTML = data.html;
                this.selectedRows = [];
                this.initRows(tbody);
            }
        },

        replaceRow(rowId, rowHtml) {
            const current = document.querySelector(`tr[data-row-id="${rowId}"]`);
            if (!current) return this.refreshTable();
            const template = document.createElement('tbody');
            template.innerHTML = rowHtml.trim();
            const nextRow = template.firstElementChild;
            if (!nextRow) return;
            current.replaceWith(nextRow);
            this.initRows(nextRow);
        },

        async runAction(key, url, options = {}) {
            return syncListing.runAction(this, key, url, options);
        },

        showToast(level, message) {
            if (this.toastTimer) clearTimeout(this.toastTimer);
            this.toast = { show: true, level, message };
            this.toastTimer = setTimeout(() => { this.toast.show = false; }, 6000);
        },
    }));
});
</script>

@endsection
