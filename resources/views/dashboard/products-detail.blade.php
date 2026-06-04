@extends('dashboard.layout')
@section('title', 'Product Detail')
@section('page-title', 'Product {{ $erpDisplayName }} Data')

@section('content')
<div class="max-w-5xl">
    <div class="mb-4 flex items-center justify-between">
        <a href="{{ route('dashboard.products') }}" class="text-sm text-indigo-600 hover:underline">← Back to Products</a>
        @if(!empty($odooId))
        <form method="POST" action="{{ route('dashboard.products.refresh', $odooId) }}">
            @csrf
            @method('PATCH')
            <button type="submit"
                    class="inline-flex items-center gap-1.5 text-sm text-gray-600 border border-gray-200 hover:border-gray-400 px-3 py-1.5 rounded-lg transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                Re-fetch from {{ $erpDisplayName }}
            </button>
        </form>
        @endif
    </div>

    @if(session('success'))
    <div class="mb-4 bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 rounded-lg text-sm">{{ session('success') }}</div>
    @endif
    @if(session('error'))
    <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm">{{ session('error') }}</div>
    @endif

    {{-- Header info bar --}}
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5 mb-4">
        <div class="flex items-start justify-between">
            <div>
                <div class="flex items-center gap-2 mb-1">
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-indigo-100 text-indigo-700">{{ $erpDisplayName }} Product</span>
                    <span class="font-mono text-sm text-gray-700">#{{ $odooId }}</span>
                    @if(!empty($data['template']['name']))
                        <span class="text-sm font-semibold text-gray-800">{{ $data['template']['name'] }}</span>
                    @endif
                </div>
                <p class="text-xs text-gray-400 mt-1">
                    @if(!empty($data['fetched_at']))
                    Cached: {{ \Carbon\Carbon::parse($data['fetched_at'])->format('Y-m-d H:i:s') }}
                    &nbsp;·&nbsp;
                    File: <code class="bg-gray-100 px-1 rounded">storage/app/products/{{ $erpId ?? $odooId }}.json</code>
                    &nbsp;·&nbsp;
                    <span class="text-emerald-600 font-medium">No {{ $erpDisplayName }} API call</span>
                    @elseif(!empty($data['ecom_id']))
                    {{ $ecomDisplayName ?? 'Ecom' }} ID: <code class="bg-gray-100 px-1 rounded">{{ $data['ecom_id'] }}</code>
                    &nbsp;·&nbsp;
                    <span class="text-blue-600 font-medium">Fetched from {{ $ecomDisplayName ?? 'Ecom' }}</span>
                    @endif
                </p>
            </div>
            <div class="text-right text-xs text-gray-400 space-y-1">
                <div>Variants: <strong class="text-gray-700">{{ count($data['variants'] ?? []) }}</strong></div>
                <div>Attribute Values: <strong class="text-gray-700">{{ count($data['attribute_values'] ?? []) }}</strong></div>
                @if($syncLog)
                <div>
                    Last pushed:
                    @if($syncLog->status === 'success')
                        <strong class="text-emerald-600">{{ $syncLog->synced_at?->diffForHumans() ?? $syncLog->updated_at->diffForHumans() }}</strong>
                    @else
                        <strong class="text-red-500">Failed {{ $syncLog->updated_at->diffForHumans() }}</strong>
                    @endif
                </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Tabbed panel --}}
    <div x-data="{ tab: 'raw' }" class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">

        {{-- Tab nav --}}
        <div class="flex border-b border-gray-100 bg-gray-50">
            <button @click="tab = 'raw'"
                    :class="tab === 'raw' ? 'border-b-2 border-indigo-500 text-indigo-600 bg-white' : 'text-gray-500 hover:text-gray-700'"
                    class="px-5 py-3 text-sm font-medium transition">
                📦 Raw {{ $erpDisplayName }} JSON
            </button>
            <button @click="tab = 'shopify'"
                    :class="tab === 'shopify' ? 'border-b-2 border-indigo-500 text-indigo-600 bg-white' : 'text-gray-500 hover:text-gray-700'"
                    class="px-5 py-3 text-sm font-medium transition">
                🛍 {{ $ecomDisplayName }} Payload
            </button>
            <button @click="tab = 'response'"
                    :class="tab === 'response' ? 'border-b-2 border-indigo-500 text-indigo-600 bg-white' : 'text-gray-500 hover:text-gray-700'"
                    class="px-5 py-3 text-sm font-medium transition flex items-center gap-1.5">
                📨 {{ $ecomDisplayName }} Response
                @if($syncLog)
                    @if($syncLog->status === 'success')
                        <span class="w-2 h-2 rounded-full bg-emerald-400 inline-block"></span>
                    @else
                        <span class="w-2 h-2 rounded-full bg-red-400 inline-block"></span>
                    @endif
                @else
                    <span class="w-2 h-2 rounded-full bg-gray-300 inline-block"></span>
                @endif
            </button>
        </div>

        {{-- RAW JSON tab --}}
        <div x-show="tab === 'raw'" class="p-5">
            <p class="text-xs text-gray-400 mb-3">
                Full contents of <code class="bg-gray-100 px-1 rounded">storage/app/products/{{ $erpId ?? $odooId }}.json</code>
            </p>
            <pre class="bg-gray-50 border border-gray-200 rounded-lg p-4 text-xs text-gray-700 overflow-x-auto whitespace-pre-wrap"
                 style="max-height:72vh">{{ json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
        </div>

        {{-- SHOPIFY PAYLOAD tab --}}
        <div x-show="tab === 'shopify'" class="p-5">
            <p class="text-xs text-gray-400 mb-3">
                What will be sent to {{ $ecomDisplayName }} API for this product, based on active
                <a href="{{ route('dashboard.product-field-config.index') }}" class="text-indigo-500 hover:underline">Product Field Mappings</a>.
            </p>

            @if(!empty($ecomPayload['_error'] ?? $shopifyPayload['_error'] ?? null))
                <div class="bg-red-50 border border-red-200 rounded-lg p-4 text-sm text-red-700">
                    <strong>Error building payload:</strong> {{ $ecomPayload['_error'] ?? $shopifyPayload['_error'] ?? '' }}
                </div>
            @elseif(!empty($ecomPayload ?? $shopifyPayload ?? []))
                <div class="flex items-center gap-3 mb-3">
                    <span class="text-xs bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded-full font-medium">
                        {{ count(($ecomPayload ?? $shopifyPayload)['variants'] ?? []) }} variant(s)
                    </span>
                    @if(!empty(($ecomPayload ?? $shopifyPayload)['options']))
                    <span class="text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded-full">
                        {{ count(($ecomPayload ?? $shopifyPayload)['options']) }} option(s)
                    </span>
                    @endif
                    <span class="text-xs text-gray-400">
                        Sent as <code class="bg-gray-100 px-1 rounded">POST /products.json</code>
                        wrapped in <code class="bg-gray-100 px-1 rounded">{"product": ...}</code>
                    </span>
                </div>
                <pre class="bg-gray-50 border border-gray-200 rounded-lg p-4 text-xs text-gray-700 overflow-x-auto whitespace-pre-wrap"
                     style="max-height:72vh">{{ json_encode($ecomPayload ?? $shopifyPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            @else
                <div class="py-10 text-center text-gray-400 text-sm">
                    No payload generated. Check
                    <a href="{{ route('dashboard.product-field-config.index') }}" class="text-indigo-500 hover:underline">Product Field Mappings</a>
                    and run <code class="bg-gray-100 px-1 rounded">php artisan migrate</code> to seed defaults.
                </div>
            @endif
        </div>

        {{-- SHOPIFY RESPONSE tab --}}
        <div x-show="tab === 'response'" class="p-5">

            @if($syncLog)
                {{-- Log meta --}}
                <div class="flex flex-wrap items-center gap-3 mb-4 pb-4 border-b border-gray-100">
                    <div class="flex items-center gap-1.5">
                        @if($syncLog->status === 'success')
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-emerald-100 text-emerald-700">✓ Success</span>
                        @elseif($syncLog->status === 'failed')
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-700">✗ Failed</span>
                        @else
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600">{{ $syncLog->status }}</span>
                        @endif
                    </div>
                    <span class="text-xs text-gray-500">Action: <strong>{{ $syncLog->action }}</strong></span>
                    <span class="text-xs text-gray-500">
                        At: <strong>{{ ($syncLog->synced_at ?? $syncLog->updated_at)?->format('Y-m-d H:i:s') }}</strong>
                        ({{ ($syncLog->synced_at ?? $syncLog->updated_at)?->diffForHumans() }})
                    </span>
                    <span class="text-xs text-gray-500">Attempts: <strong>{{ $syncLog->attempts }}</strong></span>
                    @if($shopifyResponse && isset($shopifyResponse['id']))
                        <a href="https://admin.shopify.com/products/{{ $shopifyResponse['id'] }}"
                           target="_blank"
                           class="text-xs text-indigo-600 hover:underline">
                            View on {{ $ecomDisplayName }} ↗
                        </a>
                    @endif
                </div>

                {{-- Error (if failed) --}}
                @if($syncLog->status === 'failed' && $syncLog->error_message)
                <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4">
                    <h4 class="text-sm font-semibold text-red-700 mb-1">Error</h4>
                    <p class="text-sm text-red-600">{{ $syncLog->error_message }}</p>
                    @if($syncLog->error_context)
                    <pre class="mt-2 text-xs text-red-500 overflow-x-auto whitespace-pre-wrap">{{ json_encode($syncLog->error_context, JSON_PRETTY_PRINT) }}</pre>
                    @endif
                </div>
                @endif

                {{-- Full Shopify response --}}
                @if($shopifyResponse)
                    <p class="text-xs text-gray-400 mb-2">
                        Full response from {{ $ecomDisplayName }} API
                        @if(isset($shopifyResponse['id']))
                            · {{ $ecomDisplayName }} Product ID: <strong class="text-gray-700">{{ ($ecomResponse ?? $shopifyResponse)['id'] }}</strong>
                        @endif
                    </p>
                    <pre class="bg-gray-50 border border-gray-200 rounded-lg p-4 text-xs text-gray-700 overflow-x-auto whitespace-pre-wrap"
                         style="max-height:72vh">{{ json_encode($ecomResponse ?? $shopifyResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                @else
                    <p class="text-xs text-gray-400 mb-3">No response body stored for this log entry.</p>
                @endif

            @else
                {{-- Never pushed --}}
                <div class="py-12 text-center">
                    <svg class="w-10 h-10 text-gray-200 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                    </svg>
                    <p class="text-sm text-gray-400 font-medium">Not pushed to {{ $ecomDisplayName }} yet</p>
                    <p class="text-xs text-gray-300 mt-1">Run <code class="bg-gray-100 px-1 rounded">php artisan sync:products</code> or use the Fetch Products button.</p>
                </div>
            @endif
        </div>

    </div>
</div>
@endsection