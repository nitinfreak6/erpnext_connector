@extends('dashboard.layout')
@section('title', 'Orders')
@section('page-title', 'Orders Sync')

@section('content')

{{-- Flash Messages --}}
@if(session('success'))
<div class="mb-4 bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 rounded-lg text-sm flex items-center gap-2">
    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
    {{ session('success') }}
</div>
@endif

{{-- Sync Direction Indicator --}}
<div class="mb-4 bg-gradient-to-r from-emerald-50 to-teal-50 border border-emerald-200 rounded-xl p-4">
    <div class="flex items-center justify-between">
        <div>
            <h3 class="text-sm font-semibold text-gray-700">Sync Direction</h3>
            <p class="text-xs text-gray-500 mt-0.5">
                @if($syncMode === 'erp_to_ecom')
                    Orders created in <strong>{{ $erpDisplayName }}</strong>, fulfillments synced to <strong>{{ $ecomDisplayName }}</strong>
                @elseif($syncMode === 'ecom_to_erp')
                    Orders imported from <strong>{{ $ecomDisplayName }}</strong> to <strong>{{ $erpDisplayName }}</strong>
                @else
                    Orders flow in <strong>both directions</strong>
                @endif
            </p>
        </div>
        <div class="flex items-center gap-2">
            @if($syncMode === 'erp_to_ecom')
                <span class="px-3 py-1.5 bg-indigo-100 text-indigo-700 rounded-lg text-xs font-medium">
                    {{ $erpDisplayName }} → {{ $ecomDisplayName }}
                </span>
            @elseif($syncMode === 'ecom_to_erp')
                <span class="px-3 py-1.5 bg-emerald-100 text-emerald-700 rounded-lg text-xs font-medium">
                    {{ $ecomDisplayName }} → {{ $erpDisplayName }}
                </span>
            @else
                <span class="px-3 py-1.5 bg-purple-100 text-purple-700 rounded-lg text-xs font-medium">⟷ Bidirectional</span>
            @endif
        </div>
    </div>
</div>

{{-- Stats --}}
<div class="grid grid-cols-4 gap-3 mb-4">
    @foreach([
        ['label' => 'Total Orders', 'value' => $stats['total'] ?? 0, 'color' => 'gray', 'bg' => 'bg-white'],
        ['label' => $ecomDisplayName . ' Orders', 'value' => $stats['ecom_total'] ?? 0, 'color' => 'emerald', 'bg' => 'bg-emerald-50'],
        ['label' => 'Amazon Orders', 'value' => $stats['amazon_total'] ?? 0, 'color' => 'amber', 'bg' => 'bg-amber-50'],
        ['label' => 'Synced Today', 'value' => $stats['today'] ?? 0, 'color' => 'blue', 'bg' => 'bg-blue-50'],
    ] as $card)
    <div class="{{ $card['bg'] }} border border-gray-200 rounded-xl px-4 py-3 shadow-sm">
        <div class="text-xs text-gray-500 font-medium">{{ $card['label'] }}</div>
        <div class="text-2xl font-bold text-{{ $card['color'] }}-600 mt-0.5">{{ number_format($card['value']) }}</div>
    </div>
    @endforeach
</div>

{{-- Action Buttons --}}
<div class="flex justify-end gap-3 mb-4">
    @if($syncMode === 'erp_to_ecom' || $syncMode === 'bidirectional')
    <form method="POST" action="{{ route('dashboard.orders.fetch') }}">
        @csrf
        <button type="submit" 
                onclick="return confirm('Fetch orders from {{ $erpDisplayName }} and create in {{ $ecomDisplayName }}?')"
                class="inline-flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
            </svg>
            Fetch from {{ $erpDisplayName }}
        </button>
    </form>
    @endif

    @if($syncMode === 'ecom_to_erp' || $syncMode === 'bidirectional')
    <form method="POST" action="{{ route('dashboard.orders.pull') }}">
        @csrf
        <button type="submit"
                onclick="return confirm('Pull orders from {{ $ecomDisplayName }} and create in {{ $erpDisplayName }}?')"
                class="inline-flex items-center gap-1.5 bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
            Pull from {{ $ecomDisplayName }}
        </button>
    </form>
    @endif
</div>

{{-- Filters --}}
<div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 mb-4">
    <form method="GET" class="flex flex-wrap gap-3 items-end">
        <div>
            <label class="block text-xs text-gray-500 mb-1">Search</label>
            <input type="text" name="search" value="{{ $search }}"
                   placeholder="Order ID, reference…"
                   class="border border-gray-200 rounded-lg px-3 py-1.5 text-sm w-64 focus:ring-2 focus:ring-indigo-300 outline-none">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Channel</label>
            <select name="channel" class="border border-gray-200 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-indigo-300 outline-none">
                <option value="all"     {{ $channel === 'all'     ? 'selected' : '' }}>All</option>
                <option value="shopify" {{ $channel === 'shopify' ? 'selected' : '' }}>{{ ucfirst($ecomDriver) }}</option>
                <option value="amazon"  {{ $channel === 'amazon'  ? 'selected' : '' }}>Amazon</option>
            </select>
        </div>
        <button type="submit" class="bg-indigo-600 text-white px-4 py-1.5 rounded-lg text-sm hover:bg-indigo-700">Filter</button>
        <a href="{{ route('dashboard.orders') }}" class="text-sm text-gray-500 py-1.5">Reset</a>
    </form>
</div>

{{-- Table --}}
<div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden mb-4">
    <div class="px-5 py-3 border-b border-gray-100">
        <span class="text-sm font-medium text-gray-700">{{ $orders->total() }} order mappings</span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
                <tr>
                    <th class="px-4 py-3 text-left font-medium">Channel</th>
                    <th class="px-4 py-3 text-left font-medium">{{ $erpDisplayName }} Order ID</th>
                    <th class="px-4 py-3 text-left font-medium">{{ $ecomDisplayName }} Order ID</th>
                    <th class="px-4 py-3 text-left font-medium">Order Reference</th>
                    <th class="px-4 py-3 text-left font-medium">Last Synced</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @forelse($orders as $mapping)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3">
                        @if(str_starts_with($mapping->entity_type, 'amazon'))
                            <span class="badge bg-amber-100 text-amber-800">Amazon</span>
                        @else
                            <span class="badge bg-emerald-100 text-emerald-800">{{ ucfirst($ecomDriver) }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 font-mono text-xs">
                        <span class="bg-gray-100 px-1.5 py-0.5 rounded text-gray-700">#{{ $mapping->erp_id ?? $mapping->odoo_id }}</span>
                    </td>
                    <td class="px-4 py-3 font-mono text-xs text-gray-600">
                        {{ $mapping->ecom_id ?? $mapping->shopify_id ?? '—' }}
                    </td>
                    <td class="px-4 py-3 text-gray-700 font-medium">
                        {{ $mapping->ecom_handle ?? $mapping->shopify_handle ?? '—' }}
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-400">
                        {{ $mapping->last_synced_at?->diffForHumans() ?? 'Never' }}
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="px-4 py-12 text-center text-gray-400">
                        No order mappings yet. Orders sync based on your sync direction setting.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="px-5 py-3 border-t border-gray-100">{{ $orders->links() }}</div>
</div>

{{-- Recent logs --}}
<div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
    <h3 class="text-sm font-semibold text-gray-700 mb-3">Recent Order Sync Activity</h3>
    @include('dashboard._log-rows', ['logs' => $recentLogs])
</div>
@endsection