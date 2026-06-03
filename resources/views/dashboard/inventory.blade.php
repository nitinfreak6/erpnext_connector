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

{{-- Global action buttons --}}
<div class="flex gap-3 mb-4">
    <form method="POST" action="{{ route('dashboard.inventory.fetch-stock') }}">
        @csrf
        <button class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg shadow-sm transition">
            ↓ Fetch Stock
        </button>
    </form>
    <form method="POST" action="{{ route('dashboard.inventory.post-stock') }}">
        @csrf
        <button class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium rounded-lg shadow-sm transition">
            ↑ Post Stock
        </button>
    </form>
</div>

{{-- Main table --}}
<div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden mb-4">
    <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
        <span class="text-sm font-medium text-gray-700">{{ $variants->total() }} SKU mappings</span>
        <form method="GET" class="flex gap-2">
            <input type="text" name="search" value="{{ $search }}"
                   placeholder="SKU, {{ $erpDisplayName }} ID…"
                   class="border border-gray-200 rounded-lg px-3 py-1 text-xs w-48 focus:ring-2 focus:ring-indigo-300 outline-none">
            <select name="status" class="border border-gray-200 rounded-lg px-2 py-1 text-xs focus:ring-2 focus:ring-indigo-300 outline-none">
                <option value="">All Status</option>
                <option value="success"  {{ request('status') === 'success'  ? 'selected' : '' }}>Success</option>
                <option value="failed"   {{ request('status') === 'failed'   ? 'selected' : '' }}>Failed</option>
                <option value="pending"  {{ request('status') === 'pending'  ? 'selected' : '' }}>Pending</option>
            </select>
            <button class="text-xs bg-indigo-600 text-white px-3 py-1 rounded-lg hover:bg-indigo-700">Search</button>
        </form>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
                <tr>
                    <th class="px-4 py-3 text-left font-medium">Product Id</th>
                    <th class="px-4 py-3 text-left font-medium">{{ $ecomDisplayName }} Site</th>
                    <th class="px-4 py-3 text-left font-medium">{{ $ecomDisplayName }} Location</th>
                    <th class="px-4 py-3 text-left font-medium">{{ $erpDisplayName }} Location</th>
                    <th class="px-4 py-3 text-left font-medium">SKU</th>
                    <th class="px-4 py-3 text-left font-medium">{{ $erpDisplayName }} Qty</th>
                    <th class="px-4 py-3 text-left font-medium">Adjusted Quantity</th>
                    <th class="px-4 py-3 text-left font-medium">Status</th>
                    <th class="px-4 py-3 text-left font-medium">Message</th>
                    <th class="px-4 py-3 text-left font-medium">Last Synced</th>
                    <th class="px-4 py-3 text-right font-medium">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @forelse($variants as $log)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-mono text-xs font-medium text-gray-800">
                        {{ $log->entity_id }}
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-600">
                        {{ config('shopify.shop_domain', '—') }}
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-500 font-mono" title="{{ $log->shopify_location_id ?? '' }}">
                        {{ $log->shopify_location_id ? Str::limit((string)$log->shopify_location_id, 14) : '—' }}
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-500 font-mono" title="{{ $log->erp_location_id ?? '' }}">
                        {{ $log->erp_location_id ? Str::limit((string)$log->erp_location_id, 14) : '—' }}
                    </td>
                    <td class="px-4 py-3 font-mono text-xs text-gray-700">
                        {{ $log->sku ?? '—' }}
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-700">
                        {{ $log->erp_qty ?? '—' }}
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-700">
                        {{ $log->erp_qty ?? 0 }}
                    </td>
                    <td class="px-4 py-3">
                        @php
                            $sc = ['success' => 'emerald', 'failed' => 'red', 'processing' => 'blue', 'pending' => 'amber'][$log->status] ?? 'gray';
                        @endphp
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-{{ $sc }}-100 text-{{ $sc }}-700">
                            {{ ucfirst($log->status) }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-400 max-w-[120px] truncate" title="{{ $log->error_message ?? '' }}">
                        {{ $log->error_message ? \Str::limit($log->error_message, 20) : '—' }}
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-400 whitespace-nowrap">
                        {{ $log->created_at?->diffForHumans() ?? 'Never' }}
                    </td>
                    {{-- Per-row Tools dropdown --}}
                    <td class="px-4 py-3 text-right">
                        <div class="relative inline-block" x-data="{ open: false }">
                            <button @click="open = !open" @click.outside="open = false"
                                    class="px-3 py-1.5 text-xs font-medium bg-white border border-gray-200 rounded-lg hover:bg-gray-50 flex items-center gap-1">
                                Tools <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                            </button>
                            <div x-show="open" x-cloak
                                 class="absolute right-0 mt-1 w-36 bg-white border border-gray-100 rounded-lg shadow-lg z-20 py-1">
                                <form method="POST" action="{{ route('dashboard.inventory.fetch-stock-single', $log->entity_id) }}">
                                    @csrf
                                    <button class="w-full text-left px-4 py-2 text-xs text-gray-700 hover:bg-gray-50">↓ Fetch Stock</button>
                                </form>
                                <form method="POST" action="{{ route('dashboard.inventory.post-stock-single', $log->entity_id) }}">
                                    @csrf
                                    <button class="w-full text-left px-4 py-2 text-xs text-gray-700 hover:bg-gray-50">↑ Post Stock</button>
                                </form>
                                <a href="{{ route('dashboard.inventory.stock-info', $log->entity_id) }}"
                                   class="block px-4 py-2 text-xs text-gray-700 hover:bg-gray-50">ℹ Stock Info</a>
                            </div>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="11" class="px-4 py-12 text-center text-gray-400">
                        No inventory sync logs yet.
                        <div class="text-xs text-gray-400 mt-2">Click <strong>Fetch Stock</strong> to pull from {{ $erpDisplayName }}.</div>
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

@endsection