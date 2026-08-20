@extends('dashboard.layout')
@section('title', 'Dispatch Info')
@section('page-title', 'Dispatch Info')

@section('content')
@php
    $isEcomToErp = ($dispatchDirection ?? 'erp_to_ecom') === 'ecom_to_erp';
    $syncMode    = $dispatchDirection ?? 'erp_to_ecom';
    $sourceLabel = $isEcomToErp ? $ecomDisplayName : $erpDisplayName;
    $targetLabel = $isEcomToErp ? $erpDisplayName : $ecomDisplayName;
    $wasPushed   = !empty($syncLog);
    $pushOk      = $wasPushed && $syncLog->status === 'success';
    $pushFailed  = $wasPushed && $syncLog->status === 'failed';
    $isPending   = \App\Services\Sync\SyncEntityState::dispatchNeedsPush($dispatchMapping?->ecom_status ?? null);
    $initialTab  = ($wasPushed && $pushFailed) ? 'target' : 'source';
    $respArr     = ($wasPushed && $syncLog->response_payload)
                     ? (json_decode($syncLog->response_payload, true) ?? [])
                     : [];
    $respTargetId = $respArr['picking_id'] ?? $respArr['fulfillment_id'] ?? $respArr['id'] ?? null;
@endphp

<div class="max-w-5xl">

@include('dashboard._sync-entity-detail', [
    'backUrl' => route('dashboard.orders'),
    'backLabel' => 'Back to Orders',
    'syncMode' => $syncMode,
    'erpDisplayName' => $erpDisplayName,
    'ecomDisplayName' => $ecomDisplayName,
    'displayId' => $displayId,
    'title' => $title,
    'mapping' => $dispatchMapping,
    'syncLog' => $syncLog ?? null,
    'lastLog' => $syncLog ?? null,
    'metaPills' => array_values(array_filter([
        ($orderMapping?->erp_id) ? ['label' => $erpDisplayName . ' Order', 'value' => '#' . $orderMapping->erp_id] : null,
        ($orderMapping?->ecom_id) ? ['label' => $ecomDisplayName . ' Order', 'value' => $orderMapping->ecom_id] : null,
        ($dispatchMapping?->erp_id) ? ['label' => 'Picking', 'value' => '#' . $dispatchMapping->erp_id] : null,
        ($dispatchMapping?->ecom_id && $isEcomToErp) ? ['label' => 'Fulfillment', 'value' => $dispatchMapping->ecom_id] : null,
        ($dispatchMapping?->ecom_status) ? ['label' => 'Dispatch status', 'value' => \App\Services\Sync\SyncEntityState::dispatchDisplayLabel($dispatchMapping->ecom_status)] : null,
    ])),
])

<div x-data="{ tab: '{{ $initialTab }}' }" class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">

    <div class="flex border-b border-gray-100 bg-gray-50">
        <button @click="tab = 'source'"
                :class="tab === 'source' ? 'border-b-2 border-indigo-500 text-indigo-600 bg-white' : 'text-gray-500 hover:text-gray-700'"
                class="px-5 py-3 text-sm font-medium transition">
            @if($isEcomToErp)
            🛍 Raw {{ $ecomDisplayName }} Fulfillment
            @else
            📦 Raw {{ $erpDisplayName }} Picking
            @endif
        </button>
        <button @click="tab = 'target'"
                :class="tab === 'target' ? 'border-b-2 border-indigo-500 text-indigo-600 bg-white' : 'text-gray-500 hover:text-gray-700'"
                class="px-5 py-3 text-sm font-medium transition flex items-center gap-2">
            @if($isEcomToErp)
            📦 {{ $erpDisplayName }} Delivery
            @else
            🛍 {{ $ecomDisplayName }} Fulfillment
            @endif
            @if($pushOk)<span class="w-2 h-2 rounded-full bg-emerald-400 inline-block"></span>
            @elseif($pushFailed)<span class="w-2 h-2 rounded-full bg-red-400 inline-block"></span>@endif
        </button>
    </div>

    <div x-show="tab === 'source'" class="p-5">
        <div class="mb-4 p-3 rounded-lg bg-indigo-50 border border-indigo-100 text-xs">
            @if($isEcomToErp)
            Full {{ $ecomDisplayName }} fulfillment record stored on <strong>Fetch Dispatch</strong>.
            Re-run fetch to refresh after Shopify updates the fulfillment.
            @else
            Full {{ $erpDisplayName }} delivery record (all picking fields + <code>moves</code> lines) stored on <strong>Fetch Dispatch</strong>.
            Re-run fetch to refresh if this record was stored before a sync update.
            @endif
        </div>

        @if(!empty($sourceData))
        <pre class="bg-gray-50 border border-gray-200 rounded-lg p-4 text-xs text-gray-700 overflow-x-auto whitespace-pre-wrap" style="max-height:72vh">{{ json_encode($sourceData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
        @else
        <div class="py-16 text-center text-sm text-gray-400">
            No dispatch data fetched yet. Use <strong>Fetch Dispatch</strong> on the orders list first.
        </div>
        @endif
    </div>

    <div x-show="tab === 'target'" class="p-5">
        @if(!$wasPushed)
        <div class="py-16 text-center">
            <div class="text-5xl mb-4">{{ $isEcomToErp ? '📦' : '🛍' }}</div>
            <p class="text-sm font-semibold text-gray-500 mb-1">Not posted to {{ $targetLabel }} yet</p>
            <p class="text-xs text-gray-400 mb-6">
                Use <strong>Post Dispatch</strong> on the orders list.<br>
                After push, field-config mapped payload and response appear here.
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
            @if($dispatchMapping?->erp_id)
            <span class="text-gray-500">Picking: <strong>#{{ $dispatchMapping->erp_id }}</strong></span>
            @endif
        </div>

        @if($pushFailed && $syncLog->error_message)
        <div class="mb-5 bg-red-50 border border-red-200 rounded-lg p-4">
            <p class="text-sm font-semibold text-red-700 mb-1">Error</p>
            <p class="text-sm text-red-600">{{ $syncLog->error_message }}</p>
        </div>
        @endif

        <div class="rounded-lg border border-gray-200 overflow-hidden">
            @if($isEcomToErp)
            <div class="bg-gray-50 border-b border-gray-200 px-4 py-2 flex items-center gap-2">
                <span class="text-xs font-semibold text-gray-600">Field-config mapped payload</span>
                <span class="text-xs text-gray-400">sent to {{ $erpDisplayName }}</span>
            </div>
            @if(!empty($mappedPayload))
            <pre class="bg-white p-4 text-xs text-gray-700 overflow-x-auto whitespace-pre-wrap" style="max-height:50vh">{{ json_encode($mappedPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            @elseif(!empty($outgoingPayload))
            <pre class="bg-white p-4 text-xs text-gray-700 overflow-x-auto whitespace-pre-wrap" style="max-height:50vh">{{ json_encode($outgoingPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            @else
            <div class="p-4 text-xs text-gray-400 italic">Mapped payload not available for this log entry.</div>
            @endif

            <div class="bg-gray-50 border-t border-b border-gray-200 px-4 py-2 flex items-center gap-2">
                <span class="text-xs font-semibold text-gray-600">↑ {{ $erpDisplayName }} wire payload</span>
                <span class="text-xs text-gray-400">actual JSON-RPC calls to Odoo</span>
            </div>
            @if(!empty($wirePayload))
            <pre class="bg-white p-4 text-xs text-gray-700 overflow-x-auto whitespace-pre-wrap" style="max-height:50vh">{{ json_encode($wirePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            @else
            <div class="p-4 text-xs text-gray-400 italic">Wire payload not available for this log entry.</div>
            @endif

            <div class="bg-gray-50 border-t border-b border-gray-200 px-4 py-2 flex items-center gap-2">
                <span class="text-xs font-semibold text-gray-600">↓ {{ $erpDisplayName }} Response</span>
                @if($respTargetId)
                <span class="text-xs text-gray-400">· Picking ID: <strong class="text-gray-700">{{ $respTargetId }}</strong></span>
                @endif
            </div>
            @else
            <div class="bg-gray-50 border-b border-gray-200 px-4 py-2 flex items-center gap-2">
                <span class="text-xs font-semibold text-gray-600">↑ Shopify wire payload</span>
                <span class="text-xs text-gray-400">actual GraphQL sent to {{ $ecomDisplayName }}</span>
            </div>
            @if(!empty($wirePayload))
            <pre class="bg-white p-4 text-xs text-gray-700 overflow-x-auto whitespace-pre-wrap" style="max-height:50vh">{{ json_encode($wirePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            @elseif(!empty($outgoingPayload))
            <pre class="bg-white p-4 text-xs text-gray-700 overflow-x-auto whitespace-pre-wrap" style="max-height:50vh">{{ json_encode($outgoingPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            @else
            <div class="p-4 text-xs text-gray-400 italic">Wire payload not available for this log entry.</div>
            @endif

            @if(!empty($mappedPayload) && $mappedPayload !== ($wirePayload ?? null))
            <div class="bg-gray-50 border-t border-b border-gray-200 px-4 py-2 flex items-center gap-2">
                <span class="text-xs font-semibold text-gray-600">Field-config mapped</span>
                <span class="text-xs text-gray-400">from dispatch field mappings before Shopify adapter</span>
            </div>
            <pre class="bg-white p-4 text-xs text-gray-700 overflow-x-auto whitespace-pre-wrap" style="max-height:50vh">{{ json_encode($mappedPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            @endif

            <div class="bg-gray-50 border-t border-b border-gray-200 px-4 py-2 flex items-center gap-2">
                <span class="text-xs font-semibold text-gray-600">↓ {{ $ecomDisplayName }} Response</span>
                @if($respTargetId)
                <span class="text-xs text-gray-400">· Fulfillment ID: <strong class="text-gray-700">{{ $respTargetId }}</strong></span>
                @endif
            </div>
            @endif

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
