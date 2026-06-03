@extends('dashboard.layout')
@section('title', 'Order Detail')
@section('page-title', 'Order Detail')

@section('content')

{{-- Flash --}}
@if(session('success'))
<div class="mb-4 bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 rounded-lg text-sm flex items-center gap-2">
    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
    {{ session('success') }}
</div>
@endif
@if(session('error'))
<div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm flex items-center gap-2">
    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
    {{ session('error') }}
</div>
@endif

<div class="mb-4">
    <a href="{{ route('dashboard.orders') }}" class="text-sm text-indigo-600 hover:underline flex items-center gap-1">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        Back to Orders
    </a>
</div>

{{-- Header --}}
<div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 mb-4">
    <div class="flex items-start justify-between">
        <div>
            <div class="flex items-center gap-3 mb-2">
                <h2 class="text-lg font-bold text-gray-800">
                    Order {{ $mapping->ecom_handle ?? '#' . $mapping->erp_id }}
                </h2>
                @if($latestLog)
                    @php $s = $latestLog->status; $colors = ['success'=>'emerald','failed'=>'red','processing'=>'blue','pending'=>'amber']; $c = $colors[$s] ?? 'gray'; @endphp
                    <span class="badge bg-{{ $c }}-100 text-{{ $c }}-700 text-xs">{{ ucfirst($s) }}</span>
                @endif
            </div>
            <div class="flex items-center gap-6 text-xs text-gray-500">
                <span>{{ $erpDisplayName }} ID: <strong class="text-gray-700">#{{ $mapping->erp_id ?? '—' }}</strong></span>
                <span>{{ $ecomDisplayName }} ID: <strong class="text-gray-700">{{ $mapping->ecom_id ?? '—' }}</strong></span>
                <span>Last synced: <strong class="text-gray-700">{{ $mapping->last_synced_at?->diffForHumans() ?? 'Never' }}</strong></span>
                <span>Direction: <strong class="text-gray-700">{{ $mapping->last_sync_direction ?? '—' }}</strong></span>
            </div>
        </div>
        <div class="flex items-center gap-2">
            @if($mapping->erp_id && $syncMode !== 'ecom_to_erp')
            <form method="POST" action="{{ route('dashboard.orders.push', $mapping->erp_id) }}">
                @csrf
                <button type="submit" class="inline-flex items-center gap-1.5 text-sm bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1.5 rounded-lg transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                    Push to {{ $ecomDisplayName }}
                </button>
            </form>
            @endif
            @if($mapping->ecom_id && $syncMode !== 'erp_to_ecom')
            <form method="POST" action="{{ route('dashboard.orders.sync-back', $mapping->ecom_id) }}">
                @csrf
                <button type="submit" class="inline-flex items-center gap-1.5 text-sm bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1.5 rounded-lg transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    Sync from {{ $ecomDisplayName }}
                </button>
            </form>
            @endif
            @if($mapping->ecom_id)
            <a href="https://admin.shopify.com/orders/{{ $mapping->ecom_id }}" target="_blank"
               class="inline-flex items-center gap-1.5 text-sm border border-gray-300 text-gray-600 hover:bg-gray-50 px-3 py-1.5 rounded-lg transition">
                View on {{ $ecomDisplayName }} ↗
            </a>
            @endif
        </div>
    </div>
</div>

{{-- Sync Logs --}}
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    <div class="px-5 py-3 border-b border-gray-100">
        <h3 class="text-sm font-semibold text-gray-700">Sync History</h3>
    </div>
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
            <tr>
                <th class="px-4 py-3 text-left">Direction</th>
                <th class="px-4 py-3 text-left">Action</th>
                <th class="px-4 py-3 text-left">Status</th>
                <th class="px-4 py-3 text-left">Response</th>
                <th class="px-4 py-3 text-left">Error</th>
                <th class="px-4 py-3 text-left">Time</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
            @forelse($logs as $log)
            <tr class="hover:bg-gray-50 {{ $log->status === 'failed' ? 'bg-red-50/40' : '' }}">
                <td class="px-4 py-3">
                    @if(in_array($log->direction, ['erp_to_ecom','odoo_to_shopify']))
                        <span class="badge bg-blue-100 text-blue-700 text-xs">{{ $erpDisplayName }} → {{ $ecomDisplayName }}</span>
                    @else
                        <span class="badge bg-purple-100 text-purple-700 text-xs">{{ $ecomDisplayName }} → {{ $erpDisplayName }}</span>
                    @endif
                </td>
                <td class="px-4 py-3 text-xs capitalize text-gray-600">{{ $log->action }}</td>
                <td class="px-4 py-3">
                    @php $s = $log->status; $colors = ['success'=>'emerald','failed'=>'red','processing'=>'blue','pending'=>'amber','skipped'=>'gray']; $c = $colors[$s] ?? 'gray'; @endphp
                    <span class="badge bg-{{ $c }}-100 text-{{ $c }}-700 text-xs">{{ $s }}</span>
                </td>
                <td class="px-4 py-3 text-xs text-gray-500 max-w-xs">
                    @if($log->response_payload)
                        @php $resp = json_decode($log->response_payload, true); @endphp
                        <span class="font-mono">{{ $resp['erp_id'] ?? $resp['ecom_order_id'] ?? Str::limit($log->response_payload, 40) }}</span>
                    @else
                        <span class="text-gray-300">—</span>
                    @endif
                </td>
                <td class="px-4 py-3 text-xs text-red-500 max-w-xs">
                    {{ Str::limit($log->error_message, 60) }}
                </td>
                <td class="px-4 py-3 text-xs text-gray-400 whitespace-nowrap">
                    {{ $log->created_at->format('M j, H:i') }}
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="6" class="px-4 py-8 text-center text-gray-400 text-sm">No sync history yet.</td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

@endsection
