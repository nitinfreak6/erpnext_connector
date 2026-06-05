@extends('dashboard.layout')
@section('title', 'Inventory')
@section('page-title', 'Stock Sync')

@section('content')

{{-- Flash messages --}}
@foreach(['success','error','warning','info'] as $type)
    @if(session($type))
        <div class="mb-4 px-4 py-3 rounded-lg text-sm
            {{ $type === 'success' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' :
               ($type === 'error'  ? 'bg-red-50 text-red-700 border border-red-200' :
               ($type === 'warning'? 'bg-amber-50 text-amber-700 border border-amber-200' :
                                     'bg-blue-50 text-blue-700 border border-blue-200')) }}">
            {!! session($type) !!}
        </div>
    @endif
@endforeach

{{-- Stats --}}
<div class="grid grid-cols-4 gap-4 mb-4">
    @foreach([
        ['label' => 'Total SKUs',   'value' => $stats['total_skus'],   'color' => 'indigo'],
        ['label' => 'Synced Today', 'value' => $stats['synced_today'], 'color' => 'green'],
        ['label' => 'Failed Today', 'value' => $stats['failed_today'], 'color' => 'red'],
        ['label' => 'Total Synced', 'value' => $stats['total_synced'], 'color' => 'violet'],
    ] as $s)
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 flex items-center gap-3">
        <div class="text-2xl font-bold text-{{ $s['color'] }}-600">{{ number_format($s['value']) }}</div>
        <div class="text-sm text-gray-500">{{ $s['label'] }}</div>
    </div>
    @endforeach
</div>

{{-- Global action buttons — direction-aware --}}
{{-- erp_to_ecom (Odoo → Shopify): Fetch from Odoo + Post to Shopify --}}
{{-- ecom_to_erp (Shopify → Odoo): Fetch from Shopify + Post to Odoo --}}
<div class="flex gap-3 mb-4">
    <form method="POST" action="{{ route('dashboard.inventory.fetch-stock') }}">
        @csrf
        <button class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg shadow-sm transition">
            ↓ Fetch from {{ $syncMode === 'ecom_to_erp' ? $ecomDisplayName : $erpDisplayName }}
        </button>
    </form>
    <form method="POST" action="{{ route('dashboard.inventory.post-stock') }}">
        @csrf
        <button class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium rounded-lg shadow-sm transition">
            ↑ Post to {{ $syncMode === 'ecom_to_erp' ? $erpDisplayName : $ecomDisplayName }}
        </button>
    </form>
</div>

{{-- Main table --}}
{{--
    IMPORTANT: $variants is now a paginated collection of SyncMapping rows (not SyncLog).
    SyncMapping is written by Fetch Stock — so rows appear immediately after fetching.
    Each row has a ->latest_log (SyncLog|null) attached by the controller, which holds
    the push result (success/failed) written by PushInventoryToEcomJob.
    This mirrors the pattern used by Orders and Products pages.
--}}
<div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden mb-4">
    <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
        <span class="text-sm font-medium text-gray-700">{{ $variants->total() }} SKU mappings</span>
        <form method="GET" class="flex gap-2">
            <input type="text" name="search" value="{{ $search }}"
                   placeholder="SKU, {{ $erpDisplayName }} ID…"
                   class="border border-gray-200 rounded-lg px-3 py-1 text-xs w-48 focus:ring-2 focus:ring-indigo-300 outline-none">
            <select name="status" class="border border-gray-200 rounded-lg px-2 py-1 text-xs focus:ring-2 focus:ring-indigo-300 outline-none">
                <option value="">All Status</option>
                {{-- Status values match SyncMapping.ecom_status: pending / posted --}}
                {{-- and also match the derived push label shown in the table --}}
                <option value="pending"  {{ request('status') === 'pending'  ? 'selected' : '' }}>Fetched / Pending</option>
                <option value="posted"   {{ request('status') === 'posted'   ? 'selected' : '' }}>Posted</option>
            </select>
            <button class="text-xs bg-indigo-600 text-white px-3 py-1 rounded-lg hover:bg-indigo-700">Search</button>
        </form>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
                <tr>
                    <th class="px-4 py-3 text-left font-medium">{{ $erpDisplayName }} ID</th>
                    <th class="px-4 py-3 text-left font-medium">Product</th>
                    <th class="px-4 py-3 text-left font-medium">SKU</th>
                    <th class="px-4 py-3 text-left font-medium">{{ $ecomDisplayName }} Site</th>
                    <th class="px-4 py-3 text-left font-medium">{{ $ecomDisplayName }} Location</th>
                    <th class="px-4 py-3 text-left font-medium">{{ $erpDisplayName }} Location</th>
                    <th class="px-4 py-3 text-left font-medium">{{ $erpDisplayName }} Qty</th>
                    <th class="px-4 py-3 text-left font-medium">Fetch Status</th>
                    <th class="px-4 py-3 text-left font-medium">Push Status</th>
                    <th class="px-4 py-3 text-left font-medium">Message</th>
                    <th class="px-4 py-3 text-left font-medium">Last Synced</th>
                    <th class="px-4 py-3 text-right font-medium">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @forelse($variants as $mapping)
                <tr class="hover:bg-gray-50">

                    {{-- ERP Product ID — from SyncMapping.erp_id --}}
                    <td class="px-4 py-3 font-mono text-xs font-medium text-gray-800">
                        {{ $mapping->erp_id }}
                    </td>

                    {{-- Product name — enriched by controller from ProductCache --}}
                    <td class="px-4 py-3 text-xs text-gray-700 max-w-[140px] truncate" title="{{ $mapping->product_name ?? '' }}">
                        {{ $mapping->product_name ?? '—' }}
                    </td>

                    {{-- SKU — enriched by controller from ProductCache --}}
                    <td class="px-4 py-3 font-mono text-xs text-gray-700">
                        {{ $mapping->sku ?? '—' }}
                    </td>

                    {{-- Ecom site --}}
                    <td class="px-4 py-3 text-xs text-gray-600">
                        {{ config('shopify.shop_domain', '—') }}
                    </td>

                    {{-- Shopify/Ecom location ID — extracted from metadata by controller --}}
                    <td class="px-4 py-3 text-xs text-gray-500 font-mono" title="{{ $mapping->shopify_location_id ?? '' }}">
                        {{ $mapping->shopify_location_id ? Str::limit((string)$mapping->shopify_location_id, 14) : '—' }}
                    </td>

                    {{-- ERP location ID — extracted from metadata by controller --}}
                    <td class="px-4 py-3 text-xs text-gray-500 font-mono" title="{{ $mapping->erp_location_id ?? '' }}">
                        {{ $mapping->erp_location_id ? Str::limit((string)$mapping->erp_location_id, 14) : '—' }}
                    </td>

                    {{-- Quantity — extracted from metadata by controller --}}
                    <td class="px-4 py-3 text-xs text-gray-700">
                        {{ $mapping->erp_qty ?? '—' }}
                    </td>

                    {{--
                        Fetch Status — from SyncMapping.ecom_status
                        "pending"  = fetched from ERP, waiting to be pushed
                        "posted"   = push was attempted (check Push Status for result)
                    --}}
                    <td class="px-4 py-3">
                        @php
                            $fetchStatus = $mapping->ecom_status ?? 'unknown';
                            $fetchColor  = match($fetchStatus) {
                                'pending' => 'amber',
                                'posted'  => 'violet',
                                default   => 'gray',
                            };
                            $fetchLabel = match($fetchStatus) {
                                'pending' => 'Fetched',
                                'posted'  => 'Posted',
                                default   => ucfirst($fetchStatus),
                            };
                        @endphp
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-{{ $fetchColor }}-100 text-{{ $fetchColor }}-700">
                            {{ $fetchLabel }}
                        </span>
                    </td>

                    {{--
                        Push Status — from SyncLog written by PushInventoryToEcomJob.
                        $mapping->latest_log is null until Post Stock has been run.
                        success   = successfully pushed to Shopify/ERP
                        failed    = push failed (see Message column)
                        processing = push in progress
                        null      = never pushed yet
                    --}}
                    <td class="px-4 py-3">
                        @php
                            $pushStatus = $mapping->latest_log?->status ?? null;
                            $pushColor  = match($pushStatus) {
                                'success'    => 'emerald',
                                'failed'     => 'red',
                                'processing' => 'blue',
                                'skipped'    => 'gray',
                                default      => 'gray',
                            };
                            $pushLabel = match($pushStatus) {
                                'success'    => 'Pushed ✓',
                                'failed'     => 'Failed',
                                'processing' => 'Pushing…',
                                'skipped'    => 'Skipped',
                                default      => 'Not pushed',
                            };
                        @endphp
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-{{ $pushColor }}-100 text-{{ $pushColor }}-700">
                            {{ $pushLabel }}
                        </span>
                    </td>

                    {{--
                        Message — shows push error from SyncLog if any,
                        otherwise blank (fetch phase has no error message).
                    --}}
                    <td class="px-4 py-3 text-xs text-gray-400 max-w-[120px] truncate"
                        title="{{ $mapping->latest_log?->error_message ?? '' }}">
                        {{ $mapping->latest_log?->error_message
                            ? \Str::limit($mapping->latest_log->error_message, 20)
                            : '—' }}
                    </td>

                    {{-- Last Synced — from SyncMapping.last_synced_at (set on every fetch/post) --}}
                    <td class="px-4 py-3 text-xs text-gray-400 whitespace-nowrap">
                        {{ $mapping->last_synced_at?->diffForHumans() ?? 'Never' }}
                    </td>

                    {{-- Per-row Tools dropdown — routes use erp_id (was entity_id from SyncLog) --}}
                    <td class="px-4 py-3 text-right">
                        <div class="relative inline-block" x-data="{ open: false }">
                            <button @click="open = !open" @click.outside="open = false"
                                    class="px-3 py-1.5 text-xs font-medium bg-white border border-gray-200 rounded-lg hover:bg-gray-50 flex items-center gap-1">
                                Tools <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                            </button>
                            <div x-show="open" x-cloak
                                 class="absolute right-0 mt-1 w-36 bg-white border border-gray-100 rounded-lg shadow-lg z-20 py-1">
                                <form method="POST" action="{{ route('dashboard.inventory.fetch-stock-single', $mapping->erp_id ?? $mapping->ecom_id) }}">
                                    @csrf
                                    <button class="w-full text-left px-4 py-2 text-xs text-gray-700 hover:bg-gray-50">
                                        ↓ Fetch from {{ $syncMode === 'ecom_to_erp' ? $ecomDisplayName : $erpDisplayName }}
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('dashboard.inventory.post-stock-single', $mapping->erp_id ?? $mapping->ecom_id) }}">
                                    @csrf
                                    <button class="w-full text-left px-4 py-2 text-xs text-gray-700 hover:bg-gray-50">
                                        ↑ Post to {{ $syncMode === 'ecom_to_erp' ? $erpDisplayName : $ecomDisplayName }}
                                    </button>
                                </form>
                                <a href="{{ route('dashboard.inventory.stock-info', $mapping->erp_id ?? $mapping->ecom_id) }}"
                                   class="block px-4 py-2 text-xs text-gray-700 hover:bg-gray-50">ℹ Stock Info</a>
                            </div>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="12" class="px-4 py-12 text-center text-gray-400">
                        No inventory records yet.
                        <div class="text-xs text-gray-400 mt-2">Click <strong>Fetch from {{ $erpDisplayName }}</strong> to pull stock data.</div>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="px-5 py-3 border-t border-gray-100">{{ $variants->links() }}</div>
</div>

{{-- Sync state cards --}}
<div class="grid grid-cols-2 gap-4 mb-4">
    @foreach($syncState as $key => $state)
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
        <div class="flex items-center justify-between">
            <span class="text-sm font-medium text-gray-700 capitalize">{{ str_replace('_', ' ', $key) }}</span>
            @if($state->is_running)
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-700 animate-pulse">● Running</span>
            @else
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600">Idle</span>
            @endif
        </div>
        <div class="text-xs text-gray-500 mt-2">
            Last poll: <span class="font-medium text-gray-700">{{ $state->last_poll_at?->diffForHumans() ?? 'Never' }}</span>
        </div>
        @if($state->last_erp_write_date ?? null)
            <div class="text-xs text-gray-400 mt-0.5">Cursor: {{ $state->last_erp_write_date }}</div>
        @endif
    </div>
    @endforeach
</div>

{{-- Recent push logs (bottom section) — still from SyncLog, only appears after Post Stock --}}
@if($recentLogs->isNotEmpty())
<div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
    <div class="px-5 py-3 border-b border-gray-100">
        <span class="text-sm font-medium text-gray-700">Recent Push Activity</span>
        <span class="text-xs text-gray-400 ml-2">(written when Post Stock runs)</span>
    </div>
    <div class="divide-y divide-gray-50">
        @foreach($recentLogs as $rlog)
        <div class="px-5 py-2 flex items-center gap-4 text-xs">
            <span class="font-mono text-gray-500 w-20 shrink-0">{{ $rlog->entity_id }}</span>
            @php
                $rc = ['success' => 'emerald', 'failed' => 'red', 'processing' => 'blue', 'skipped' => 'gray'][$rlog->status] ?? 'gray';
            @endphp
            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-{{ $rc }}-100 text-{{ $rc }}-700">
                {{ ucfirst($rlog->status) }}
            </span>
            <span class="text-gray-400 truncate flex-1">{{ $rlog->error_message ?? $rlog->response_payload ?? '—' }}</span>
            <span class="text-gray-400 shrink-0 whitespace-nowrap">{{ $rlog->created_at?->diffForHumans() }}</span>
        </div>
        @endforeach
    </div>
</div>
@endif

@endsection