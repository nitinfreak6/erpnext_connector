@extends('dashboard.layout')
@section('title', 'Product Detail')
@section('page-title')
    Product Detail
@endsection

@section('content')
@php
    $isEcomToErp  = ($syncMode ?? 'erp_to_ecom') === 'ecom_to_erp';

    // ── Source / Target labels ──
    // ecom_to_erp : source = Shopify, target = ERP (Odoo)
    // erp_to_ecom : source = ERP (Odoo), target = Shopify
    $sourceLabel  = $isEcomToErp ? $ecomDisplayName : $erpDisplayName;
    $targetLabel  = $isEcomToErp ? $erpDisplayName  : $ecomDisplayName;

    $graphqlUrl   = $shopifyStore ? "https://{$shopifyStore}.myshopify.com/admin/api/{$apiVersion}/graphql.json" : null;
    $erpHost      = rtrim(config('odoo.url', config('erp.url', '')), '/');

    // syncLog is ONLY set when an actual push (create/update) happened
    $wasPushed    = !empty($syncLog);
    $pushOk       = $wasPushed && $syncLog->status === 'success';
    $pushFailed   = $wasPushed && $syncLog->status === 'failed';
    $respArr      = ($wasPushed && $syncLog->response_payload)
                      ? (json_decode($syncLog->response_payload, true) ?? [])
                      : [];
    $respErpId    = $respArr['erp_id'] ?? $respArr['id'] ?? null;

    // Product name — works for both directions
    $productName  = $data['template']['name']
                 ?? $data['product']['title']
                 ?? $data['product']['name']
                 ?? null;
    $fetchedAt    = $data['fetched_at'] ?? null;
    $variantCount = count($data['variants'] ?? $data['product']['variants'] ?? []);
    $attrCount    = count($data['attribute_values'] ?? []);

    // IDs
    $displayId = $isEcomToErp
        ? ($ecomId ?? ($mapping->ecom_id ?? '—'))
        : ($erpId ?? $odooId ?? '—');
@endphp

<div class="max-w-5xl">

    <div class="mb-4">
        <a href="{{ route('dashboard.products') }}" class="text-sm text-indigo-600 hover:underline">← Back to Products</a>
    </div>

    @foreach(['success','error','info','warning'] as $level)
        @if(session($level))
            <div class="mb-4 px-4 py-3 rounded-lg text-sm border
                {{ $level === 'success' ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : '' }}
                {{ $level === 'error'   ? 'bg-red-50 border-red-200 text-red-700'             : '' }}
                {{ $level === 'info'    ? 'bg-blue-50 border-blue-200 text-blue-700'           : '' }}
                {{ $level === 'warning' ? 'bg-yellow-50 border-yellow-200 text-yellow-700'     : '' }}
            ">{!! session($level) !!}</div>
        @endif
    @endforeach

    {{-- ── Header card ── --}}
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5 mb-4">
        <div class="flex items-start justify-between gap-4">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2 mb-1">
                    {{-- Direction badge --}}
                    @if($isEcomToErp)
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-violet-100 text-violet-700">
                            {{ $ecomDisplayName }} → {{ $erpDisplayName }}
                        </span>
                    @else
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-indigo-100 text-indigo-700">
                            {{ $erpDisplayName }} → {{ $ecomDisplayName }}
                        </span>
                    @endif

                    <span class="font-mono text-sm text-gray-700">#{{ $displayId }}</span>

                    @if($productName)
                        <span class="text-sm font-semibold text-gray-800">{{ $productName }}</span>
                    @endif

                    {{-- Push status badge --}}
                    @if($wasPushed)
                        @if($pushOk)
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-700">✓ Pushed to {{ $targetLabel }}</span>
                        @elseif($pushFailed)
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700">✗ Push failed</span>
                        @else
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-700">{{ $syncLog->status }}</span>
                        @endif
                    @else
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500">
                            Not pushed to {{ $targetLabel }} yet
                        </span>
                    @endif
                </div>

                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 mt-1 text-xs text-gray-400">
                    @if($fetchedAt)
                        <span>Fetched from {{ $sourceLabel }}: <strong class="text-gray-600">{{ \Carbon\Carbon::parse($fetchedAt)->format('Y-m-d H:i:s') }}</strong></span>
                    @endif
                    @if($wasPushed)
                        <span>·</span>
                        <span>Last push: <strong class="text-gray-600">{{ ($syncLog->synced_at ?? $syncLog->updated_at)?->format('Y-m-d H:i:s') }}</strong></span>
                    @endif
                    @if($isEcomToErp && !empty($mapping->erp_id))
                        <span>·</span>
                        <span>{{ $erpDisplayName }} ID: <strong class="text-gray-600">#{{ $mapping->erp_id }}</strong></span>
                    @endif
                </div>
            </div>

            <div class="text-right text-xs text-gray-400 space-y-1 shrink-0">
                <div>Variants: <strong class="text-gray-700">{{ $variantCount }}</strong></div>
                @if(!$isEcomToErp)
                    <div>Attr Values: <strong class="text-gray-700">{{ $attrCount }}</strong></div>
                @endif
                @if($wasPushed)
                    <div>Attempts: <strong class="text-gray-700">{{ $syncLog->attempts }}</strong></div>
                @endif
            </div>
        </div>
    </div>

    {{-- ── 2-tab panel ── --}}
    @php $initialTab = ($syncLog && $syncLog->status === 'failed') ? 'target' : 'source'; @endphp
    <div x-data="{ tab: '{{ $initialTab }}' }" class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">

        <div class="flex border-b border-gray-100 bg-gray-50">
            {{-- Tab 1: Source data --}}
            <button @click="tab = 'source'"
                    :class="tab === 'source' ? 'border-b-2 border-indigo-500 text-indigo-600 bg-white' : 'text-gray-500 hover:text-gray-700'"
                    class="px-5 py-3 text-sm font-medium transition">
                @if($isEcomToErp)
                    🛍 Raw {{ $ecomDisplayName }} Data
                @else
                    📦 Raw {{ $erpDisplayName }} Data
                @endif
            </button>

            {{-- Tab 2: Target sync --}}
            <button @click="tab = 'target'"
                    :class="tab === 'target' ? 'border-b-2 border-indigo-500 text-indigo-600 bg-white' : 'text-gray-500 hover:text-gray-700'"
                    class="px-5 py-3 text-sm font-medium transition flex items-center gap-2">
                @if($isEcomToErp)
                    📦 {{ $erpDisplayName }} Sync
                @else
                    🛍 {{ $ecomDisplayName }} Sync
                @endif
                @if($pushOk)
                    <span class="w-2 h-2 rounded-full bg-emerald-400 inline-block"></span>
                @elseif($pushFailed)
                    <span class="w-2 h-2 rounded-full bg-red-400 inline-block"></span>
                @endif
            </button>
        </div>

        {{-- ────────────────────────────────────────────────────
             TAB 1 — Raw Source Data (Shopify OR ERP depending on direction)
        ──────────────────────────────────────────────────── --}}
        <div x-show="tab === 'source'" class="p-5">

            {{-- Source API endpoint info pill --}}
            @if($isEcomToErp && $graphqlUrl)
                <div class="mb-4 p-3 rounded-lg bg-violet-50 border border-violet-100 text-xs flex flex-wrap items-center gap-x-3 gap-y-1">
                    <span class="font-semibold text-violet-800">{{ $ecomDisplayName }} GraphQL</span>
                    <span class="text-violet-300">·</span>
                    <code class="bg-white border border-violet-100 px-2 py-0.5 rounded text-violet-800 break-all">{{ $graphqlUrl }}</code>
                    <span class="text-violet-300">·</span>
                    <span class="text-violet-600">Ecom ID: <code class="bg-white border border-violet-100 px-1 rounded">{{ $ecomId ?? $displayId }}</code></span>
                </div>
            @elseif(!$isEcomToErp && $erpHost)
                <div class="mb-4 p-3 rounded-lg bg-indigo-50 border border-indigo-100 text-xs flex flex-wrap items-center gap-x-3 gap-y-1">
                    <span class="font-semibold text-indigo-800">{{ $erpDisplayName }} API Endpoint</span>
                    <span class="text-indigo-300">·</span>
                    <span class="text-indigo-600 font-medium">POST</span>
                    <code class="bg-white border border-indigo-100 px-2 py-0.5 rounded text-indigo-800 break-all">{{ $erpHost }}/web/dataset/call_kw</code>
                    <span class="text-indigo-300">·</span>
                    <span class="text-indigo-600">models: <code class="bg-white border border-indigo-100 px-1 rounded">product.template</code>,
                    <code class="bg-white border border-indigo-100 px-1 rounded">product.product</code>,
                    <code class="bg-white border border-indigo-100 px-1 rounded">product.supplierinfo</code></span>
                    <span class="text-indigo-300">·</span>
                    <span class="text-indigo-600">id: <code class="bg-white border border-indigo-100 px-1 rounded">{{ $erpId ?? $odooId }}</code></span>
                </div>
            @endif

           
            <pre class="bg-gray-50 border border-gray-200 rounded-lg p-4 text-xs text-gray-700 overflow-x-auto whitespace-pre-wrap"
                 style="max-height:72vh">{{ json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
        </div>

        {{-- ────────────────────────────────────────────────────
             TAB 2 — Target Sync (push payload + response)
        ──────────────────────────────────────────────────── --}}
        <div x-show="tab === 'target'" class="p-5">

            @if(!$wasPushed)
                {{-- ── Never pushed to target ── --}}
                <div class="py-16 text-center">
                    <div class="text-5xl mb-4">{{ $isEcomToErp ? '📦' : '🛍' }}</div>
                    <p class="text-sm font-semibold text-gray-500 mb-1">Not pushed to {{ $targetLabel }} yet</p>
                    <p class="text-xs text-gray-400">
                        @if($isEcomToErp)
                            Use <strong>Push to {{ $erpDisplayName }}</strong> on the products list.<br>
                        @else
                            Use <strong>Push to {{ $ecomDisplayName }}</strong> on the products list.<br>
                        @endif
                        The outgoing payload and {{ $targetLabel }} response will appear here after the first push.
                    </p>
                </div>

            @else
                {{-- ── Pushed: status row ── --}}
                <div class="flex flex-wrap items-center gap-3 mb-5 pb-4 border-b border-gray-100 text-xs">
                    @if($pushOk)
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-700">✓ Push succeeded</span>
                    @elseif($pushFailed)
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-700">✗ Push failed</span>
                    @else
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-yellow-100 text-yellow-700">{{ $syncLog->status }}</span>
                    @endif
                    <span class="text-gray-500">Action: <strong>{{ $syncLog->action }}</strong></span>
                    <span class="text-gray-500">At: <strong>{{ ($syncLog->synced_at ?? $syncLog->updated_at)?->format('Y-m-d H:i:s') }}</strong></span>
                    <span class="text-gray-500">Attempts: <strong>{{ $syncLog->attempts }}</strong></span>
                </div>

                {{-- Error box --}}
                @if($pushFailed && $syncLog->error_message)
                <div class="mb-5 bg-red-50 border border-red-200 rounded-lg p-4">
                    <p class="text-sm font-semibold text-red-700 mb-1">Error</p>
                    <p class="text-sm text-red-600">{{ $syncLog->error_message }}</p>
                    @if($syncLog->error_context)
                        <pre class="mt-2 text-xs text-red-500 overflow-x-auto whitespace-pre-wrap">{{ json_encode($syncLog->error_context, JSON_PRETTY_PRINT) }}</pre>
                    @endif
                </div>
                @endif

                {{-- Payload + Response card --}}
                <div class="rounded-lg border border-gray-200 overflow-hidden">

                    {{-- Outgoing payload header --}}
                    <div class="bg-gray-50 border-b border-gray-200 px-4 py-2 flex items-center gap-2">
                        <span class="text-xs font-semibold text-gray-600">↑ Outgoing Payload</span>
                        <span class="text-xs text-gray-400">sent to {{ $targetLabel }}</span>
                        @if($isEcomToErp)
                            {{-- ERP endpoint info --}}
                            @if($erpHost)
                                <span class="ml-auto text-xs text-gray-400 font-mono truncate">{{ $erpHost }}/web/dataset/call_kw</span>
                            @endif
                        @else
                            {{-- Shopify GraphQL info --}}
                            @if($graphqlUrl)
                                <span class="ml-2 text-xs text-gray-400">Mutation: <strong>{{ $syncLog->action === 'update' ? 'productUpdate' : 'productCreate' }}</strong></span>
                                @if(isset($respArr['id']))
                                    <span class="text-xs text-gray-400">· Shopify ID: <strong class="text-gray-700">{{ $respArr['id'] }}</strong></span>
                                @endif
                            @endif
                            @if(!empty($ecomPayload['variants']))
                                <span class="ml-auto text-xs bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded-full">{{ count($ecomPayload['variants']) }} variant(s)</span>
                            @endif
                        @endif
                    </div>

                    @if(!empty($ecomPayload['_error'] ?? null))
                        <div class="p-4 bg-red-50 text-sm text-red-700">
                            <strong>Error building payload:</strong> {{ $ecomPayload['_error'] }}
                        </div>
                    @elseif(!empty($ecomPayload))
                        <pre class="bg-white p-4 text-xs text-gray-700 overflow-x-auto whitespace-pre-wrap" style="max-height:50vh">{{ json_encode($ecomPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                    @else
                        <div class="p-4 text-xs text-gray-400 italic">Payload not available for this log entry.</div>
                    @endif

                    {{-- Response header --}}
                    <div class="bg-gray-50 border-t border-b border-gray-200 px-4 py-2 flex items-center gap-2">
                        <span class="text-xs font-semibold text-gray-600">↓ {{ $targetLabel }} Response</span>
                        @if($isEcomToErp && !empty($respErpId))
                            <span class="text-xs text-gray-400">· {{ $erpDisplayName }} ID: <strong class="text-gray-700">{{ $respErpId }}</strong></span>
                        @elseif(!$isEcomToErp && !empty($respArr['id']))
                            <span class="text-xs text-gray-400">· Product ID: <strong class="text-gray-700">{{ $respArr['id'] }}</strong></span>
                        @endif
                    </div>

                    @if(!empty($ecomResponse))
                        <pre class="bg-white p-4 text-xs text-gray-700 overflow-x-auto whitespace-pre-wrap"
                             style="max-height:50vh">{{ json_encode($ecomResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                    @else
                        <div class="p-4 text-xs text-gray-400 italic">No response body stored for this log entry.</div>
                    @endif

                </div>{{-- end payload+response card --}}
            @endif
        </div>

    </div>
</div>
@endsection