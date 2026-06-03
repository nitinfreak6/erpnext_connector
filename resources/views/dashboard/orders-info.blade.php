@extends('dashboard.layout')
@section('title', $infoType === 'dispatch' ? 'Dispatch Info' : 'Sales Info')
@section('page-title', $infoType === 'dispatch' ? 'Dispatch Info' : 'Sales Info')

@section('content')

@if(session('success'))
<div class="mb-4 bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 rounded-lg text-sm">{{ session('success') }}</div>
@endif
@if(session('error'))
<div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm">{{ session('error') }}</div>
@endif

{{-- Back + Tab switcher --}}
<div class="mb-4 flex items-center justify-between">
    <a href="{{ route('dashboard.orders') }}" class="text-sm text-indigo-600 hover:underline flex items-center gap-1">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        Back to Orders
    </a>
    @if($mapping?->erp_id)
    <div class="flex gap-2">
        <a href="{{ route('dashboard.orders.sales-info', $mapping->erp_id) }}"
           class="px-3 py-1.5 rounded-lg text-xs font-medium transition
                  {{ $infoType === 'sales' ? 'bg-indigo-600 text-white' : 'border border-gray-300 text-gray-600 hover:bg-gray-50' }}">
            Sales Info
        </a>
        <a href="{{ route('dashboard.orders.dispatch-info', $mapping->erp_id) }}"
           class="px-3 py-1.5 rounded-lg text-xs font-medium transition
                  {{ $infoType === 'dispatch' ? 'bg-purple-600 text-white' : 'border border-gray-300 text-gray-600 hover:bg-gray-50' }}">
            Dispatch Info
        </a>
    </div>
    @endif
</div>

{{-- Order Header --}}
@if($mapping)
<div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 mb-4">
    <div class="flex items-start justify-between">
        <div>
            <h2 class="text-base font-bold text-gray-800 mb-1">
                {{ $infoType === 'dispatch' ? 'Dispatch' : 'Sales Order' }}:
                {{ $mapping->ecom_handle ?? '#' . $mapping->erp_id }}
            </h2>
            <div class="flex items-center gap-6 text-xs text-gray-500">
                <span>{{ $erpDisplayName }} ID: <strong class="text-gray-700">#{{ $mapping->erp_id ?? '—' }}</strong></span>
                <span>{{ $ecomDisplayName }} ID: <strong class="text-gray-700">{{ $mapping->ecom_id ?? '—' }}</strong></span>
                <span>Direction: <strong class="text-gray-700">{{ $mapping->last_sync_direction ?? '—' }}</strong></span>
                <span>Last synced: <strong class="text-gray-700">{{ $mapping->last_synced_at?->diffForHumans() ?? 'Never' }}</strong></span>
            </div>
        </div>
        <div class="flex gap-2">
            @if($infoType === 'sales' && $syncMode !== 'ecom_to_erp' && $mapping->erp_id)
            <form method="POST" action="{{ route('dashboard.orders.push', $mapping->erp_id) }}">
                @csrf
                <button type="submit" class="inline-flex items-center gap-1.5 text-xs bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1.5 rounded-lg">
                    Push to {{ $ecomDisplayName }}
                </button>
            </form>
            @endif
            @if($infoType === 'sales' && $syncMode !== 'erp_to_ecom' && $mapping->ecom_id)
            <form method="POST" action="{{ route('dashboard.orders.sync-back', $mapping->ecom_id) }}">
                @csrf
                <button type="submit" class="inline-flex items-center gap-1.5 text-xs bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1.5 rounded-lg">
                    Sync from {{ $ecomDisplayName }}
                </button>
            </form>
            @endif
            @if($mapping->ecom_id)
            <a href="https://admin.shopify.com/orders/{{ $mapping->ecom_id }}" target="_blank"
               class="inline-flex items-center gap-1 text-xs border border-gray-300 text-gray-600 hover:bg-gray-50 px-3 py-1.5 rounded-lg">
                View on {{ $ecomDisplayName }} ↗
            </a>
            @endif
        </div>
    </div>
</div>
@endif

{{-- API Logs --}}
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
        <h3 class="text-sm font-semibold text-gray-700">
            {{ $infoType === 'dispatch' ? 'Dispatch' : 'Sales Order' }} API History
        </h3>
        <span class="text-xs text-gray-400">{{ $logs->count() }} record(s)</span>
    </div>

    @if($logs->isEmpty())
    <div class="px-5 py-12 text-center text-gray-400 text-sm">
        No {{ $infoType === 'dispatch' ? 'dispatch' : 'sales order' }} sync history yet.
        @if($infoType === 'dispatch')
            <br><span class="text-xs mt-1 block">Use <strong>Fetch Dispatch</strong> or <strong>Post Dispatch</strong> to sync fulfillments.</span>
        @else
            <br><span class="text-xs mt-1 block">Use <strong>Fetch Sales</strong> or <strong>Post Sales</strong> to sync this order.</span>
        @endif
    </div>
    @else
    <div class="divide-y divide-gray-50">
        @foreach($logs as $log)
        <div class="px-5 py-4 hover:bg-gray-50">
            <div class="flex items-center justify-between mb-2">
                <div class="flex items-center gap-3">
                    {{-- Direction --}}
                    @if(in_array($log->direction, ['erp_to_ecom','odoo_to_shopify']))
                        <span class="badge bg-blue-100 text-blue-700 text-xs">{{ $erpDisplayName }} → {{ $ecomDisplayName }}</span>
                    @else
                        <span class="badge bg-purple-100 text-purple-700 text-xs">{{ $ecomDisplayName }} → {{ $erpDisplayName }}</span>
                    @endif

                    {{-- Status --}}
                    @php $s = $log->status; $c = ['success'=>'emerald','failed'=>'red','processing'=>'blue','pending'=>'amber','skipped'=>'gray'][$s] ?? 'gray'; @endphp
                    <span class="badge bg-{{ $c }}-100 text-{{ $c }}-700 text-xs font-medium">{{ ucfirst($s) }}</span>

                    {{-- Action --}}
                    <span class="text-xs text-gray-500 capitalize">{{ $log->action }}</span>
                </div>
                <span class="text-xs text-gray-400">{{ $log->created_at->format('M j Y, H:i:s') }}</span>
            </div>

            {{-- Request Payload --}}
            @if($log->request_payload)
            <div class="mb-2">
                <div class="text-xs font-medium text-gray-500 mb-1">Request Payload</div>
                <pre class="bg-gray-50 rounded-lg p-3 text-xs text-gray-700 overflow-x-auto font-mono max-h-32 overflow-y-auto">{{ json_encode(json_decode($log->request_payload), JSON_PRETTY_PRINT) }}</pre>
            </div>
            @endif

            {{-- Response Payload --}}
            @if($log->response_payload)
            <div class="mb-2">
                <div class="text-xs font-medium text-gray-500 mb-1">
                    API Response
                    @if($log->status === 'success')
                        <span class="text-emerald-600">✓ Success</span>
                    @endif
                </div>
                <pre class="bg-emerald-50 rounded-lg p-3 text-xs text-emerald-800 overflow-x-auto font-mono max-h-32 overflow-y-auto">{{ json_encode(json_decode($log->response_payload), JSON_PRETTY_PRINT) }}</pre>
            </div>
            @endif

            {{-- Error --}}
            @if($log->error_message)
            <div>
                <div class="text-xs font-medium text-red-500 mb-1">Error</div>
                <pre class="bg-red-50 rounded-lg p-3 text-xs text-red-700 overflow-x-auto font-mono max-h-32 overflow-y-auto">{{ $log->error_message }}</pre>
            </div>
            @endif
        </div>
        @endforeach
    </div>
    @endif
</div>

@endsection
