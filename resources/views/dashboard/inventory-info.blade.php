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
        <a href="{{ route('dashboard.inventory') }}" class="text-xs text-indigo-600 hover:underline">← Back to Inventory</a>
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
        {{ $cache?->name ?? 'Inventory Item #' . $erpId }}
    </h2>
    <div class="flex flex-wrap gap-4 text-xs text-gray-500 mt-2">
        <span>ID: <strong class="text-gray-700">#{{ $erpId }}</strong></span>
        @if($cache?->default_code ?? null)
            <span>SKU: <strong class="text-gray-700">{{ $cache->default_code }}</strong></span>
        @endif
        @if($mapping)
            @php $meta = is_array($mapping->metadata) ? $mapping->metadata : json_decode($mapping->metadata ?? '{}', true); @endphp
            @if($mapping->ecom_id)
                <span>{{ $ecomDisplayName }} Inventory Item ID: <strong class="text-gray-700">{{ $mapping->ecom_id }}</strong></span>
            @endif
            @if($meta['product_ecom_id'] ?? null)
                <span>{{ $ecomDisplayName }} Product ID: <strong class="text-gray-700">{{ $meta['product_ecom_id'] }}</strong></span>
            @endif
            @if($meta['sku'] ?? null)
                <span>SKU: <strong class="text-gray-700">{{ $meta['sku'] }}</strong></span>
            @endif
            @if($meta['shopify_location_id'] ?? null)
                <span>Location ID: <strong class="text-gray-700">{{ $meta['shopify_location_id'] }}</strong></span>
            @endif
            <span>Qty ({{ $ecomDisplayName }}): <strong class="text-gray-700">{{ $meta['available'] ?? $meta['quantity'] ?? '—' }}</strong></span>
            <span>Status: <strong class="text-gray-700">{{ $mapping->ecom_status ?? '—' }}</strong></span>
            <span>Last synced: <strong class="text-gray-700">{{ $mapping->last_synced_at?->diffForHumans() ?? 'Never' }}</strong></span>
        @endif
    </div>
</div>

{{-- Tabbed panel --}}
<div x-data="{ tab: 'logs' }" class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">

    {{-- Tab nav --}}
    <div class="flex border-b border-gray-100 bg-gray-50">
        <button @click="tab = 'logs'"
                :class="tab === 'logs' ? 'border-b-2 border-indigo-500 text-indigo-600 bg-white' : 'text-gray-500 hover:text-gray-700'"
                class="px-5 py-3 text-sm font-medium transition">
            📋 Sync History
        </button>
        <button @click="tab = 'payload'"
                :class="tab === 'payload' ? 'border-b-2 border-indigo-500 text-indigo-600 bg-white' : 'text-gray-500 hover:text-gray-700'"
                class="px-5 py-3 text-sm font-medium transition">
            📦 Stored Payload
        </button>
        @if($logs->isNotEmpty())
        <button @click="tab = 'response'"
                :class="tab === 'response' ? 'border-b-2 border-indigo-500 text-indigo-600 bg-white' : 'text-gray-500 hover:text-gray-700'"
                class="px-5 py-3 text-sm font-medium transition flex items-center gap-1.5">
            📨 Last Response
            @php $lastLog = $logs->first(); @endphp
            @if($lastLog->status === 'success')
                <span class="w-2 h-2 rounded-full bg-emerald-400 inline-block"></span>
            @elseif($lastLog->status === 'failed')
                <span class="w-2 h-2 rounded-full bg-red-400 inline-block"></span>
            @else
                <span class="w-2 h-2 rounded-full bg-gray-300 inline-block"></span>
            @endif
        </button>
        @endif
    </div>

    {{-- SYNC HISTORY tab --}}
    <div x-show="tab === 'logs'" class="p-5">
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-semibold text-gray-700">Stock Sync History</h3>
            <span class="text-xs text-gray-400">{{ $logs->count() }} record(s)</span>
        </div>

        @if($logs->isEmpty())
            <div class="text-center py-10 text-gray-400 text-sm">
                No sync history yet.
                <div class="text-xs mt-1">Use <strong>Fetch Stock</strong> then <strong>Post Stock</strong> to sync.</div>
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
                            <th class="px-4 py-2 text-left">Qty</th>
                            <th class="px-4 py-2 text-left">SKU</th>
                            <th class="px-4 py-2 text-left">Message</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        @foreach($logs as $log)
                        @php
                            $sc      = ['success' => 'emerald', 'failed' => 'red', 'processing' => 'blue', 'pending' => 'amber'][$log->status] ?? 'gray';
                            $payload = is_string($log->request_payload) ? json_decode($log->request_payload, true) : ($log->request_payload ?? []);
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
                            <td class="px-4 py-2 text-xs text-gray-700">{{ $payload['qty'] ?? $payload['quantity'] ?? '—' }}</td>
                            <td class="px-4 py-2 text-xs text-gray-500 font-mono">{{ $payload['sku'] ?? '—' }}</td>
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

    {{-- PAYLOAD tab — stored metadata (what was fetched from Shopify) --}}
    <div x-show="tab === 'payload'" class="p-5">
        <p class="text-xs text-gray-400 mb-3">
            Inventory data fetched from {{ $ecomDisplayName }} and stored locally.
            This is what gets sent to {{ $erpDisplayName }} on Post Stock.
        </p>
        @if($mapping && $mapping->metadata)
            @php
                $displayMeta = is_array($mapping->metadata)
                    ? $mapping->metadata
                    : json_decode($mapping->metadata, true);
            @endphp
            <pre class="bg-gray-50 border border-gray-200 rounded-lg p-4 text-xs text-gray-700 overflow-x-auto whitespace-pre-wrap"
                 style="max-height:72vh">{{ json_encode($displayMeta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
        @else
            <div class="py-10 text-center text-gray-400 text-sm">
                No payload stored yet. Click <strong>Fetch Stock</strong> to pull from {{ $ecomDisplayName }}.
            </div>
        @endif
    </div>

    {{-- RESPONSE tab — last sync log request/response --}}
    @if($logs->isNotEmpty())
    <div x-show="tab === 'response'" class="p-5">
        @php $lastLog = $logs->first(); @endphp

        {{-- Log meta --}}
        <div class="flex flex-wrap items-center gap-3 mb-4 pb-4 border-b border-gray-100">
            @if($lastLog->status === 'success')
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-emerald-100 text-emerald-700">✓ Success</span>
            @elseif($lastLog->status === 'failed')
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-700">✗ Failed</span>
            @else
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600">{{ $lastLog->status }}</span>
            @endif
            <span class="text-xs text-gray-500">Action: <strong>{{ $lastLog->action }}</strong></span>
            <span class="text-xs text-gray-500">At: <strong>{{ $lastLog->created_at?->format('Y-m-d H:i:s') }}</strong> ({{ $lastLog->created_at?->diffForHumans() }})</span>
        </div>

        @if($lastLog->status === 'failed' && $lastLog->error_message)
        <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4">
            <h4 class="text-sm font-semibold text-red-700 mb-1">Error</h4>
            <p class="text-sm text-red-600">{{ $lastLog->error_message }}</p>
        </div>
        @endif

        {{-- Request payload --}}
        @if($lastLog->request_payload)
        <p class="text-xs text-gray-400 mb-2">Request payload sent to {{ $erpDisplayName }}:</p>
        <pre class="bg-gray-50 border border-gray-200 rounded-lg p-4 text-xs text-gray-700 overflow-x-auto whitespace-pre-wrap mb-4"
             style="max-height:40vh">{{ json_encode(json_decode($lastLog->request_payload, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
        @endif

        {{-- Response payload --}}
        @if($lastLog->response_payload)
        <p class="text-xs text-gray-400 mb-2">Response from {{ $erpDisplayName }}:</p>
        <pre class="bg-gray-50 border border-gray-200 rounded-lg p-4 text-xs text-gray-700 overflow-x-auto whitespace-pre-wrap"
             style="max-height:40vh">{{ json_encode(json_decode($lastLog->response_payload, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
        @else
            <p class="text-xs text-gray-400">No response body stored.</p>
        @endif
    </div>
    @endif

</div>

@endsection