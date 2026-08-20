@extends('dashboard.layout')
@section('title', 'Order Detail')
@section('page-title', 'Order Detail')

@section('content')
@php
    $isEcomToErp  = $isEcomToErp ?? (($syncMode ?? 'erp_to_ecom') === 'ecom_to_erp');
    $sourceLabel  = $isEcomToErp ? $ecomDisplayName : $erpDisplayName;
    $targetLabel  = $isEcomToErp ? $erpDisplayName  : $ecomDisplayName;
    $wasPushed    = !empty($syncLog);
    $pushOk       = $wasPushed && $syncLog->status === 'success';
    $pushFailed   = $wasPushed && $syncLog->status === 'failed';
    $orderTitle   = $mapping?->ecom_handle ?? ('Order #' . ($displayId ?? $erpId));
    $initialTab   = ($wasPushed && $pushFailed) ? 'target' : 'source';
    $respArr      = ($wasPushed && $syncLog->response_payload)
                      ? (json_decode($syncLog->response_payload, true) ?? [])
                      : [];
    $respTargetId = $isEcomToErp
        ? ($respArr['erp_id'] ?? $mapping?->erp_id ?? null)
        : ($respArr['id'] ?? $mapping?->ecom_id ?? null);
@endphp

<div class="max-w-5xl">

@include('dashboard._sync-entity-detail', [
    'backUrl' => route('dashboard.orders'),
    'backLabel' => 'Back to Orders',
    'syncMode' => $isEcomToErp ? 'ecom_to_erp' : 'erp_to_ecom',
    'erpDisplayName' => $erpDisplayName,
    'ecomDisplayName' => $ecomDisplayName,
    'displayId' => $displayId ?? $erpId,
    'title' => $orderTitle,
    'mapping' => $mapping,
    'syncLog' => $syncLog ?? null,
    'lastLog' => $syncLog ?? null,
    'metaPills' => array_values(array_filter([
        ($mapping?->erp_id) ? ['label' => $erpDisplayName . ' ID', 'value' => '#' . $mapping->erp_id] : null,
        ($mapping?->ecom_id) ? ['label' => $ecomDisplayName . ' ID', 'value' => $mapping->ecom_id] : null,
    ])),
    //'actions' => view('dashboard._orders-info-actions', compact('canPost', 'mapping', 'erpDisplayName', 'ecomDisplayName'))->render(),
])

<div x-data="{ tab: '{{ $initialTab }}' }" class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">

    <div class="flex border-b border-gray-100 bg-gray-50">
        <button @click="tab = 'source'"
                :class="tab === 'source' ? 'border-b-2 border-indigo-500 text-indigo-600 bg-white' : 'text-gray-500 hover:text-gray-700'"
                class="px-5 py-3 text-sm font-medium transition">
            @if($isEcomToErp)
                🛍 Raw {{ $ecomDisplayName }} Order
            @else
                📦 Raw {{ $erpDisplayName }} Order
            @endif
        </button>
        <button @click="tab = 'target'"
                :class="tab === 'target' ? 'border-b-2 border-indigo-500 text-indigo-600 bg-white' : 'text-gray-500 hover:text-gray-700'"
                class="px-5 py-3 text-sm font-medium transition flex items-center gap-2">
            @if($isEcomToErp)
                📦 {{ $erpDisplayName }} Sync
            @else
                🛍 {{ $ecomDisplayName }} Sync
            @endif
            @if($pushOk)<span class="w-2 h-2 rounded-full bg-emerald-400 inline-block"></span>
            @elseif($pushFailed)<span class="w-2 h-2 rounded-full bg-red-400 inline-block"></span>@endif
        </button>
    </div>

    {{-- Source --}}
    <div x-show="tab === 'source'" class="p-5">
        @if($isEcomToErp)
        <div class="mb-4 p-3 rounded-lg bg-violet-50 border border-violet-100 text-xs">
            Fetched from {{ $ecomDisplayName }} — stored on fetch.
        </div>
        @elseif(!empty($erpHost))
        <div class="mb-4 p-3 rounded-lg bg-indigo-50 border border-indigo-100 text-xs">
            Fetched from {{ $erpDisplayName }} — stored on fetch.
        </div>
        @endif

        @if(!empty($sourceData))
        <pre class="bg-gray-50 border border-gray-200 rounded-lg p-4 text-xs text-gray-700 overflow-x-auto whitespace-pre-wrap" style="max-height:72vh">{{ json_encode($sourceData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
        @else
        <div class="py-16 text-center text-sm text-gray-400">
            No order data fetched yet. Use <strong>Fetch</strong> on the orders list first.
        </div>
        @endif
    </div>

    {{-- Target sync --}}
    <div x-show="tab === 'target'" class="p-5">
        @if(!$wasPushed)
        <div class="py-16 text-center">
            <div class="text-5xl mb-4">{{ $isEcomToErp ? '📦' : '🛍' }}</div>
            <p class="text-sm font-semibold text-gray-500 mb-1">Not pushed to {{ $targetLabel }} yet</p>
            <p class="text-xs text-gray-400">
                Use <strong>Push to {{ $targetLabel }}</strong> on the orders list.<br>
                The outgoing payload and {{ $targetLabel }} response will appear here after the first push.
            </p>
        </div>
        @else
        <div class="flex flex-wrap items-center gap-3 mb-5 pb-4 border-b border-gray-100 text-xs">
            @if($pushOk)
            <span class="px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-700">✓ Push succeeded</span>
            @elseif($pushFailed)
            <span class="px-2.5 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-700">✗ Push failed</span>
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
            @if($syncLog->error_context)
            <pre class="mt-2 text-xs text-red-500 overflow-x-auto whitespace-pre-wrap">{{ json_encode($syncLog->error_context, JSON_PRETTY_PRINT) }}</pre>
            @endif
        </div>
        @endif

        <div class="rounded-lg border border-gray-200 overflow-hidden">
            <div class="bg-gray-50 border-b border-gray-200 px-4 py-2 flex items-center gap-2">
                <span class="text-xs font-semibold text-gray-600">↑ Outgoing Payload</span>
                <span class="text-xs text-gray-400">actual {{ $targetLabel }} create/write values (from wire log)</span>
            </div>
            @if(!empty($wirePayload))
            <pre class="bg-white p-4 text-xs text-gray-700 overflow-x-auto whitespace-pre-wrap" style="max-height:50vh">{{ json_encode($wirePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            @elseif(!empty($ecomPayload))
            <pre class="bg-white p-4 text-xs text-gray-700 overflow-x-auto whitespace-pre-wrap" style="max-height:50vh">{{ json_encode($ecomPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            @else
            <div class="p-4 text-xs text-gray-400 italic">Payload not available for this log entry.</div>
            @endif

            @if(!empty($mappedPayload) && $mappedPayload !== ($wirePayload ?? null))
            <div class="bg-gray-50 border-t border-b border-gray-200 px-4 py-2 flex items-center gap-2">
                <span class="text-xs font-semibold text-gray-600">Field-config mapped</span>
                <span class="text-xs text-gray-400">before Odoo update logic — may include order_line even when lines were not re-sent</span>
            </div>
            <pre class="bg-white p-4 text-xs text-gray-700 overflow-x-auto whitespace-pre-wrap" style="max-height:50vh">{{ json_encode($mappedPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            @endif

            <div class="bg-gray-50 border-t border-b border-gray-200 px-4 py-2 flex items-center gap-2">
                <span class="text-xs font-semibold text-gray-600">↓ {{ $targetLabel }} Response</span>
                @if($respTargetId)
                <span class="text-xs text-gray-400">· ID: <strong class="text-gray-700">{{ $respTargetId }}</strong></span>
                @endif
            </div>
            @if(!empty($ecomResponse))
            <pre class="bg-white p-4 text-xs text-gray-700 overflow-x-auto whitespace-pre-wrap" style="max-height:50vh">{{ json_encode($ecomResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            @else
            <div class="p-4 text-xs text-gray-400 italic">No response body stored for this log entry.</div>
            @endif
        </div>
        @endif
    </div>
</div>

</div>
@endsection
