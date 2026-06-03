@extends('dashboard.layout')
@section('title', 'Stock Info')
@section('page-title', 'Stock Info')

@section('content')

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

{{-- Header card --}}
<div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5 mb-4">
    <div class="flex items-center justify-between mb-3">
        <div class="flex items-center gap-3">
            <a href="{{ route('dashboard.inventory') }}" class="text-xs text-indigo-600 hover:underline">← Back to Inventory</a>
        </div>
        <div class="flex gap-2">
            <form method="POST" action="{{ route('dashboard.inventory.fetch-stock-single', $erpId) }}">
                @csrf
                <button class="px-3 py-1.5 text-xs bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">↓ Fetch Stock</button>
            </form>
            <form method="POST" action="{{ route('dashboard.inventory.post-stock-single', $erpId) }}">
                @csrf
                <button class="px-3 py-1.5 text-xs bg-emerald-600 text-white rounded-lg hover:bg-emerald-700">↑ Post Stock</button>
            </form>
        </div>
    </div>

    <h2 class="text-lg font-semibold text-gray-800 mb-1">
        {{ $cache?->name ?? 'Product #' . $erpId }}
    </h2>
    <div class="flex flex-wrap gap-4 text-xs text-gray-500">
        <span>{{ $erpDisplayName }} ID: <strong class="text-gray-700">#{{ $erpId }}</strong></span>
        @if($cache?->default_code)
            <span>SKU: <strong class="text-gray-700">{{ $cache->default_code }}</strong></span>
        @endif
        @if($mapping)
            <span>{{ $ecomDisplayName }} Variant ID: <strong class="text-gray-700">{{ $mapping->ecom_id ?? '—' }}</strong></span>
            <span>Inventory Item: <strong class="text-gray-700">{{ $mapping->ecom_secondary_id ?? '—' }}</strong></span>
            <span>Status: <strong class="text-gray-700">{{ $mapping->ecom_status ?? '—' }}</strong></span>
            <span>Last synced: <strong class="text-gray-700">{{ $mapping->last_synced_at?->diffForHumans() ?? 'Never' }}</strong></span>
        @endif
    </div>
</div>

{{-- Sync log history --}}
<div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
    <div class="flex items-center justify-between mb-3">
        <h3 class="text-sm font-semibold text-gray-700">Stock Sync History</h3>
        <span class="text-xs text-gray-400">{{ count($logs) }} record(s)</span>
    </div>

    @if($logs->isEmpty())
        <div class="text-center py-10 text-gray-400 text-sm">
            No sync history yet.
            <div class="text-xs mt-1">Use <strong>Fetch Stock</strong> or <strong>Post Stock</strong> to sync this product.</div>
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
                    <tr>
                        <th class="px-4 py-2 text-left">Date</th>
                        <th class="px-4 py-2 text-left">Direction</th>
                        <th class="px-4 py-2 text-left">Action</th>
                        <th class="px-4 py-2 text-left">Status</th>
                        <th class="px-4 py-2 text-left">Quantity</th>
                        <th class="px-4 py-2 text-left">Message</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    @foreach($logs as $log)
                    @php
                        $sc = ['success' => 'emerald', 'failed' => 'red', 'processing' => 'blue', 'pending' => 'amber'][$log->status] ?? 'gray';
                        $payload = is_string($log->request_payload) ? json_decode($log->request_payload, true) : [];
                    @endphp
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2 text-xs text-gray-500 whitespace-nowrap">{{ $log->created_at?->format('Y-m-d H:i') }}</td>
                        <td class="px-4 py-2 text-xs text-gray-500">{{ $log->direction ?? '—' }}</td>
                        <td class="px-4 py-2 text-xs text-gray-500">{{ $log->action ?? '—' }}</td>
                        <td class="px-4 py-2">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-{{ $sc }}-100 text-{{ $sc }}-700">
                                {{ ucfirst($log->status) }}
                            </span>
                        </td>
                        <td class="px-4 py-2 text-xs text-gray-700">{{ $payload['quantity'] ?? '—' }}</td>
                        <td class="px-4 py-2 text-xs text-gray-400 max-w-xs truncate" title="{{ $log->error_message ?? '' }}">
                            {{ $log->error_message ?? '—' }}
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

@endsection