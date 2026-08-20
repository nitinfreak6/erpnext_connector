@extends('dashboard.layout')
@section('title', 'Stock Info')
@section('page-title', 'Stock Info')

@section('content')
@php
    $sourceLabel  = $isEcomToErp ? $ecomDisplayName : $erpDisplayName;
    $targetLabel  = $isEcomToErp ? $erpDisplayName  : $ecomDisplayName;

    $wasPushed    = !empty($syncLog);
    $pushOk       = $wasPushed && $syncLog->status === 'success';
    $pushFailed   = $wasPushed && $syncLog->status === 'failed';

    $productName  = $cache?->name ?? 'Inventory Item #' . ($displayId ?? $erpId);
    $sku          = $cache?->default_code ?? ($sourceData['sku'] ?? null);

    $initialTab   = ($wasPushed && $pushFailed) ? 'target' : 'source';
@endphp

<div class="max-w-5xl">

@include('dashboard._sync-entity-detail', [
    'backUrl' => route('dashboard.inventory'),
    'backLabel' => 'Back to Inventory',
    'syncMode' => $isEcomToErp ? 'ecom_to_erp' : 'erp_to_ecom',
    'erpDisplayName' => $erpDisplayName,
    'ecomDisplayName' => $ecomDisplayName,
    'displayId' => $displayId ?? $erpId,
    'title' => $productName,
    'mapping' => $mapping,
    'syncLog' => $syncLog ?? null,
    'lastLog' => $syncLog ?? null,
    'metaPills' => array_values(array_filter([
        $sku ? ['label' => 'SKU', 'value' => $sku] : null,
        isset($displayQty) ? ['label' => 'Qty', 'value' => $displayQty] : null,
        ($mapping?->ecom_id) ? ['label' => $ecomDisplayName . ' Item', 'value' => $mapping->ecom_id] : null,
    ])),
    'actions' => '',
])

<div x-data="{ tab: '{{ $initialTab }}' }" class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">

    <div class="flex border-b border-gray-100 bg-gray-50">
        <button @click="tab = 'source'"
                :class="tab === 'source' ? 'border-b-2 border-indigo-500 text-indigo-600 bg-white' : 'text-gray-500 hover:text-gray-700'"
                class="px-5 py-3 text-sm font-medium transition">
            @if($isEcomToErp)
                📦 Raw {{ $ecomDisplayName }} Fetch
            @else
                📦 Raw {{ $erpDisplayName }} Data
            @endif
        </button>
        <button @click="tab = 'target'"
                :class="tab === 'target' ? 'border-b-2 border-indigo-500 text-indigo-600 bg-white' : 'text-gray-500 hover:text-gray-700'"
                class="px-5 py-3 text-sm font-medium transition flex items-center gap-2">
            @if($isEcomToErp)
                📦 {{ $erpDisplayName }} Post
            @else
                🛍 {{ $ecomDisplayName }} Post
            @endif
            @if($pushOk)<span class="w-2 h-2 rounded-full bg-emerald-400 inline-block"></span>
            @elseif($pushFailed)<span class="w-2 h-2 rounded-full bg-red-400 inline-block"></span>@endif
        </button>
    </div>

    {{-- Fetch --}}
    <div x-show="tab === 'source'" class="p-5">
        <div class="mb-4 p-3 rounded-lg bg-indigo-50 border border-indigo-100 text-xs">
            @if($isEcomToErp)
                Exact payload stored on <strong>Fetch from {{ $ecomDisplayName }}</strong> (includes GraphQL raw when available).
            @else
                Fetched from {{ $erpDisplayName }} — model <code class="bg-white px-1 rounded">stock.quant</code>, stored on fetch.
            @endif
        </div>

        @if(!empty($sourceData))
        <pre class="bg-gray-50 border border-gray-200 rounded-lg p-4 text-xs text-gray-700 overflow-x-auto whitespace-pre-wrap" style="max-height:72vh">{{ json_encode($sourceData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
        @else
        <div class="py-16 text-center text-sm text-gray-400">
            No stock data fetched yet. Use <strong>Fetch</strong> on the inventory list first.
        </div>
        @endif
    </div>

    {{-- Post --}}
    <div x-show="tab === 'target'" class="p-5">
        @if(!$wasPushed)
        <div class="py-16 text-center">
            <div class="text-5xl mb-4">{{ $isEcomToErp ? '📦' : '🛍' }}</div>
            <p class="text-sm font-semibold text-gray-500 mb-1">Not posted to {{ $targetLabel }} yet</p>
            <p class="text-xs text-gray-400">
                Use <strong>Post to {{ $targetLabel }}</strong> on the inventory list.<br>
                The real API payload and {{ $targetLabel }} response will appear here after the first post.
            </p>
        </div>
        @else
        <div class="flex flex-wrap items-center gap-3 mb-5 pb-4 border-b border-gray-100 text-xs">
            @if($pushOk)
            <span class="px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-700">✓ Post succeeded</span>
            @elseif($pushFailed)
            <span class="px-2.5 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-700">✗ Post failed</span>
            @else
            <span class="px-2.5 py-1 rounded-full text-xs font-semibold bg-yellow-100 text-yellow-700">{{ $syncLog->status }}</span>
            @endif
            <span class="text-gray-500">Action: <strong>{{ $syncLog->action ?? '—' }}</strong></span>
            <span class="text-gray-500">At: <strong>{{ ($syncLog->synced_at ?? $syncLog->created_at)?->format('Y-m-d H:i:s') }}</strong></span>
        </div>

        @if($pushFailed && $syncLog->error_message)
        <div class="mb-5 bg-red-50 border border-red-200 rounded-lg p-4">
            <p class="text-sm font-semibold text-red-700 mb-1">Error</p>
            <p class="text-sm text-red-600">{{ $syncLog->error_message }}</p>
        </div>
        @endif

        <div class="rounded-lg border border-gray-200 overflow-hidden">
            <div class="bg-gray-50 border-b border-gray-200 px-4 py-2 flex flex-wrap items-center gap-2">
                <span class="text-xs font-semibold text-gray-600">↑ Outgoing Payload</span>
                @if(!empty($targetPayloadIsMapped))
                <span class="text-xs text-amber-700">field-config mapped values (wire not stored — re-post to capture RPC)</span>
                @else
                <span class="text-xs text-gray-400">
                    @if($isEcomToErp)
                        exact {{ $erpDisplayName }} XML-RPC calls (model, method, args)
                    @else
                        exact {{ $ecomDisplayName }} GraphQL sent on post
                    @endif
                </span>
                @endif
                @if(!$isEcomToErp && !empty($graphqlUrl))
                <code class="ml-auto text-xs bg-white border border-gray-200 px-2 py-0.5 rounded text-gray-600 break-all">{{ $graphqlUrl }}</code>
                @elseif($isEcomToErp && !empty($erpHost))
                <code class="ml-auto text-xs bg-white border border-gray-200 px-2 py-0.5 rounded text-gray-600 break-all">{{ $erpHost }}/xmlrpc/2/object</code>
                @endif
            </div>
            @if(!empty($targetPayload))
            <pre class="bg-white p-4 text-xs text-gray-700 overflow-x-auto whitespace-pre-wrap" style="max-height:50vh">{{ json_encode($targetPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            @else
            <div class="p-4 text-xs text-gray-400 italic">No wire payload stored. Re-post to capture the real API request.</div>
            @endif

            <div class="bg-gray-50 border-t border-b border-gray-200 px-4 py-2">
                <span class="text-xs font-semibold text-gray-600">↓ {{ $targetLabel }} Response</span>
            </div>
            @if(!empty($targetResponse))
            <pre class="bg-white p-4 text-xs text-gray-700 overflow-x-auto whitespace-pre-wrap" style="max-height:50vh">{{ json_encode($targetResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            @else
            <div class="p-4 text-xs text-gray-400 italic">No response body stored for this log entry.</div>
            @endif
        </div>
        @endif
    </div>
</div>

</div>
@endsection
