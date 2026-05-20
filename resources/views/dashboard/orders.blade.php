@extends('dashboard.layout')
@section('title', 'Orders')
@section('page-title', 'Orders Sync')

@section('content')

{{-- Stats --}}
<div class="grid grid-cols-3 gap-4 mb-4">
    @foreach([
        ['label' => ucfirst($ecomDriver ?? 'Ecom') . ' Orders', 'value' => $stats['ecom_total'] ?? 0, 'color' => 'indigo'],
        ['label' => 'Amazon Orders',  'value' => $stats['amazon_total'] ?? 0,  'color' => 'amber'],
        ['label' => 'Synced Today',   'value' => $stats['today'] ?? 0,         'color' => 'green'],
    ] as $s)
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 flex items-center gap-3">
        <div class="text-2xl font-bold text-{{ $s['color'] }}-600">{{ number_format($s['value']) }}</div>
        <div class="text-sm text-gray-500">{{ $s['label'] }}</div>
    </div>
    @endforeach
</div>

{{-- Filters --}}
<div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 mb-4">
    <form method="GET" class="flex flex-wrap gap-3 items-end">
        <div>
            <label class="block text-xs text-gray-500 mb-1">Search</label>
            <input type="text" name="search" value="{{ $search }}"
                   placeholder="Order ID, {{ $ecomDisplayName }} name…"
                   class="border border-gray-200 rounded-lg px-3 py-1.5 text-sm w-64 focus:ring-2 focus:ring-indigo-300 outline-none">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Channel</label>
            <select name="channel" class="border border-gray-200 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-indigo-300 outline-none">
                <option value="all"     {{ $channel === 'all'     ? 'selected' : '' }}>All</option>
                <option value="shopify" {{ $channel === 'shopify' ? 'selected' : '' }}>{{ $ecomDisplayName }}</option>
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
                    <th class="px-4 py-3 text-left font-medium">External Order</th>
                    <th class="px-4 py-3 text-left font-medium">Order Name / Ref</th>
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
                            <span class="badge bg-indigo-100 text-indigo-800">{{ $ecomDisplayName }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 font-mono text-xs">
                        <span class="bg-gray-100 px-1.5 py-0.5 rounded text-gray-700">#{{ $mapping->odoo_id }}</span>
                    </td>
                    <td class="px-4 py-3 font-mono text-xs text-gray-600">
                        {{ $mapping->shopify_id }}
                    </td>
                    <td class="px-4 py-3 text-gray-700 font-medium">
                        {{ $mapping->shopify_handle ?: '—' }}
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-400">
                        {{ $mapping->last_synced_at?->diffForHumans() ?? 'Never' }}
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="px-4 py-12 text-center text-gray-400">
                        No order mappings yet. Orders sync when Shopify webhooks are received or Amazon is polled.
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