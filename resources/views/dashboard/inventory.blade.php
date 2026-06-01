@extends('dashboard.layout')
@section('title', 'Inventory')
@section('page-title', 'Inventory Sync')

@section('content')

{{-- Stats --}}
<div class="grid grid-cols-3 gap-4 mb-4">
    @foreach([
        ['label' => 'Total SKUs',       'value' => $stats['total_skus'],   'color' => 'indigo'],
        ['label' => 'Mapped SKUs',      'value' => $stats['mapped_skus'] ?? 0, 'color' => 'violet'],
        ['label' => 'Synced Today',     'value' => $stats['synced_today'], 'color' => 'green'],
        ['label' => 'Failed Today',     'value' => $stats['failed_today'], 'color' => 'red'],
    ] as $s)
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 flex items-center gap-3">
        <div class="text-2xl font-bold text-{{ $s['color'] }}-600">{{ number_format($s['value']) }}</div>
        <div class="text-sm text-gray-500">{{ $s['label'] }}</div>
    </div>
    @endforeach
</div>

{{-- Sync state --}}
<div class="grid grid-cols-2 gap-4 mb-4">
    @foreach($syncState as $key => $state)
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
        <div class="flex items-center justify-between">
            <span class="text-sm font-medium text-gray-700 capitalize">{{ str_replace('_', ' ', $key) }}</span>
            @if($state->is_running)
                <span class="badge bg-blue-100 text-blue-700 animate-pulse">● Running</span>
            @else
                <span class="badge bg-gray-100 text-gray-600">Idle</span>
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

{{-- SKU / Variant mapping table --}}
<div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden mb-4">
    <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
        <span class="text-sm font-medium text-gray-700">{{ $variants->total() }} SKU mappings</span>
        <form method="GET" class="flex gap-2">
            <input type="text" name="search" value="{{ $search }}"
                   placeholder="SKU, {{ $erpDisplayName }} ID…"
                   class="border border-gray-200 rounded-lg px-3 py-1 text-xs w-48 focus:ring-2 focus:ring-indigo-300 outline-none">
            <button class="text-xs bg-indigo-600 text-white px-3 py-1 rounded-lg hover:bg-indigo-700">Search</button>
        </form>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
                <tr>
                    <th class="px-4 py-3 text-left font-medium">SKU ({{ $erpDisplayName }} Ref)</th>
                    <th class="px-4 py-3 text-left font-medium">{{ $erpDisplayName }} Variant ID</th>
                    <th class="px-4 py-3 text-left font-medium">{{ $ecomDisplayName  }} Variant ID</th>
                    <th class="px-4 py-3 text-left font-medium">{{ $ecomDisplayName  }} Inventory Item ID</th>
                    <th class="px-4 py-3 text-left font-medium">Last Synced</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @forelse($variants as $v)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-mono text-xs font-medium text-gray-800">{{ $v->erp_reference ?? $v->odoo_reference ?: '—' }}</td>
                    <td class="px-4 py-3 font-mono text-xs text-gray-600">
                        <span class="bg-gray-100 px-1.5 py-0.5 rounded">#{{ $v->erp_id ?? $v->odoo_id }}</span>
                    </td>
                    <td class="px-4 py-3 font-mono text-xs text-indigo-600">{{ $v->ecom_id ?? $v->shopify_id }}</td>
                    <td class="px-4 py-3 font-mono text-xs text-gray-500">{{ $v->ecom_secondary_id ?? $v->shopify_secondary_id ?: '—' }}</td>
                    <td class="px-4 py-3 text-xs text-gray-400">{{ $v->last_synced_at?->diffForHumans() ?? 'Never' }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="px-4 py-12 text-center text-gray-400">
                        No variant mappings with {{ $ecomDisplayName  }} inventory item IDs.
                        <div class="text-xs text-gray-400 mt-2">
                            Run <span class="font-mono bg-gray-100 px-1.5 py-0.5 rounded">php artisan sync:products --full</span>
                            and ensure {{ $ecomDisplayName  }} variant SKUs match {{ $erpDisplayName }} <span class="font-mono">default_code</span>.
                        </div>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="px-5 py-3 border-t border-gray-100">{{ $variants->links() }}</div>
</div>

{{-- Inventory logs --}}
<div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
    <h3 class="text-sm font-semibold text-gray-700 mb-3">Recent Inventory Sync Logs</h3>
    @include('dashboard._log-rows', ['logs' => $recentLogs])
</div>
@endsection